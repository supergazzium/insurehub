<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP client for the Zoho Mail REST API.
 *
 * Responsibilities:
 *   - exchange the long-lived refresh_token for short-lived access_tokens
 *     (cached in Laravel's cache until shortly before expiry);
 *   - POST to Zoho's send / send-later endpoints;
 *   - upload + reuse attachments;
 *   - search for inbound messages addressed to our plus-aliases.
 *
 * The API is documented at https://www.zoho.com/mail/help/api/. Endpoints:
 *   POST  https://accounts.zoho.<region>/oauth/v2/token       — token refresh
 *   POST  https://mail.zoho.<region>/api/accounts/<id>/messages       — send
 *   POST  https://mail.zoho.<region>/api/accounts/<id>/messages/attachment  — upload
 *   POST  https://mail.zoho.<region>/api/accounts/<id>/messages/scheduledMail/<schedId>  — cancel
 *   GET   https://mail.zoho.<region>/api/accounts/<id>/messages/search   — list
 */
class ZohoMailClient
{
    private const ACCESS_TOKEN_CACHE_KEY = 'zoho.mail.access_token';
    /** Zoho access tokens live 1 hour; refresh a couple of minutes early. */
    private const ACCESS_TOKEN_TTL_SECONDS = 3300;

    public function __construct(
        private readonly string $region,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        private readonly string $accountId,
    ) {
    }

    public static function fromConfig(): self
    {
        $cfg = config('services.zoho');
        foreach (['client_id', 'client_secret', 'refresh_token', 'account_id'] as $key) {
            if (empty($cfg[$key])) {
                throw new RuntimeException("services.zoho.{$key} is not set — populate the ZOHO_* env vars.");
            }
        }
        return new self(
            region: $cfg['region'] ?? 'com',
            clientId: $cfg['client_id'],
            clientSecret: $cfg['client_secret'],
            refreshToken: $cfg['refresh_token'],
            accountId: $cfg['account_id'],
        );
    }

    /** Whether all required Zoho credentials are present in config. */
    public static function isConfigured(): bool
    {
        $cfg = config('services.zoho');
        foreach (['client_id', 'client_secret', 'refresh_token', 'account_id'] as $key) {
            if (empty($cfg[$key])) {
                return false;
            }
        }
        return true;
    }

    // ── Endpoints ────────────────────────────────────────────────────────────

    private function accountsHost(): string
    {
        return "https://accounts.zoho.{$this->region}";
    }

    private function mailHost(): string
    {
        return "https://mail.zoho.{$this->region}";
    }

    private function messagesUrl(string $suffix = ''): string
    {
        return $this->mailHost()."/api/accounts/{$this->accountId}/messages".$suffix;
    }

    // ── OAuth ────────────────────────────────────────────────────────────────

    /**
     * Get a valid access token. Cached for the TTL above; refreshed once when
     * expired. Multiple concurrent callers will each refresh the first time;
     * Cache::lock could be added if that becomes a problem.
     */
    public function accessToken(): string
    {
        $cached = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $token = $this->refreshAccessToken();
        Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $token, self::ACCESS_TOKEN_TTL_SECONDS);
        return $token;
    }

    private function refreshAccessToken(): string
    {
        $response = Http::asForm()->post($this->accountsHost().'/oauth/v2/token', [
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
        ]);
        $this->throwIfError($response, 'Zoho token refresh failed');
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Zoho token refresh returned no access_token: '.$response->body());
        }
        return $token;
    }

    // ── Send ─────────────────────────────────────────────────────────────────

    /**
     * Send a message immediately. Pass-through to Zoho except for credential injection.
     *
     * @param  array<string,mixed>  $payload  Zoho-shaped body (fromAddress, toAddress, subject, content, mailFormat, attachments?)
     * @return array<string,mixed>  Zoho's `data` object: ['messageId' => ...]
     */
    public function sendMessage(array $payload): array
    {
        // Force scheduleType=1 when caller didn't set it — defensive.
        $payload['scheduleType'] = $payload['scheduleType'] ?? 1;
        $response = $this->authedJson()->post($this->messagesUrl(), $payload);
        $this->throwIfError($response, 'Zoho send failed');
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('Zoho send returned no data: '.$response->body());
        }
        return $data;
    }

    /**
     * Schedule a message for later. The payload must already include
     * `scheduleType: 6` and `scheduleTime` (Zoho's MM/dd/yyyy HH:mm:ss UTC format).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>  ['messageId' => ..., 'scheduledMailId' => ...]
     */
    public function scheduleMessage(array $payload): array
    {
        $payload['scheduleType'] = 6;
        return $this->sendMessage($payload);
    }

    /** Cancel a previously-scheduled message. */
    public function cancelScheduled(string $scheduledMailId): bool
    {
        $response = $this->authedJson()->delete(
            $this->messagesUrl('/scheduledMail/'.urlencode($scheduledMailId)),
        );
        if ($response->failed()) {
            Log::warning('Zoho cancel scheduled failed', [
                'scheduledMailId' => $scheduledMailId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        }
        return true;
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    /**
     * Upload a file to Zoho's per-message attachment store. The returned triple
     * (storeName / attachmentName / attachmentPath) is what `sendMessage()`'s
     * `attachments[]` array expects.
     *
     * @param  resource  $stream  Open binary read stream of the file contents
     * @return array{storeName: string, attachmentName: string, attachmentPath: string}
     */
    public function uploadAttachment(string $filename, mixed $stream, ?string $contentType = null): array
    {
        $response = Http::withToken($this->accessToken(), 'Zoho-oauthtoken')
            ->withHeaders([
                'X-FILE-NAME' => $filename,
                'Content-Type' => $contentType ?? 'application/octet-stream',
            ])
            ->withBody(stream_get_contents($stream) ?: '', $contentType ?? 'application/octet-stream')
            ->post($this->messagesUrl('/attachment?uploadType=multipart'));

        $this->throwIfError($response, 'Zoho attachment upload failed');
        $data = $response->json('data');
        if (! is_array($data) || ! isset($data['storeName'], $data['attachmentName'], $data['attachmentPath'])) {
            throw new RuntimeException('Zoho attachment upload returned unexpected shape: '.$response->body());
        }
        return [
            'storeName' => (string) $data['storeName'],
            'attachmentName' => (string) $data['attachmentName'],
            'attachmentPath' => (string) $data['attachmentPath'],
        ];
    }

    // ── Inbox polling ────────────────────────────────────────────────────────

    /**
     * Search the account's inbox for messages received in the given window.
     * Used by the `mail:poll` artisan command to find inbound replies.
     *
     * @param  \DateTimeInterface  $since  start of the window (inclusive)
     * @return list<array<string,mixed>>   Zoho's `data` array of message summaries
     */
    public function searchMessages(\DateTimeInterface $since, int $limit = 200): array
    {
        $response = $this->authedJson()->get($this->messagesUrl('/view'), [
            'limit' => $limit,
            // Zoho `view` doesn't take a `since` directly; we filter by
            // `receivedTime` server-side using `searchKey`. For now we pull a
            // page and let the caller filter on receivedTime ≥ since.
            'sortBy' => 'date',
            'sortorder' => 'false', // newest first
        ]);
        $this->throwIfError($response, 'Zoho message list failed');
        $data = $response->json('data');
        if (! is_array($data)) {
            return [];
        }
        $sinceTs = $since->getTimestamp() * 1000;
        return array_values(array_filter($data, static function ($m) use ($sinceTs): bool {
            if (! is_array($m)) {
                return false;
            }
            // Zoho returns receivedTime in milliseconds.
            $rt = (int) ($m['receivedTime'] ?? 0);
            return $rt > 0 && $rt >= $sinceTs;
        }));
    }

    /**
     * Fetch one message's full content by Zoho messageId.
     *
     * @return array<string,mixed>
     */
    public function fetchMessage(string $messageId): array
    {
        $response = $this->authedJson()->get(
            $this->messagesUrl('/'.urlencode($messageId).'/content'),
        );
        $this->throwIfError($response, 'Zoho message fetch failed');
        $data = $response->json('data');
        return is_array($data) ? $data : [];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function authedJson(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->accessToken(), 'Zoho-oauthtoken')
            ->acceptJson()
            ->asJson();
    }

    private function throwIfError(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }
        // Token may have been revoked — clear cache so the next call re-mints.
        if ($response->status() === 401) {
            Cache::forget(self::ACCESS_TOKEN_CACHE_KEY);
        }
        $body = $response->body();
        Log::error($context, ['status' => $response->status(), 'body' => $body]);
        throw new RuntimeException("{$context}: HTTP {$response->status()} — {$body}");
    }
}
