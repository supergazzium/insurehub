<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Models\MailThread;
use App\Services\Mail\ZohoMailClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bridge between the frontend's Zoho-shaped payloads and the Zoho Mail API.
 *
 * Request bodies are passed through verbatim (with our `fromAddress` /
 * `replyTo` injected if missing); responses match Zoho's shape so the
 * frontend doesn't have to special-case anything.
 *
 * Side effect: every send is persisted in `mail_threads` + `mail_messages`
 * so the support page's case timeline survives across reloads.
 */
class MailController extends ApiController
{
    /** Returns the Zoho client, or aborts 503 if env vars aren't set yet. */
    private function zoho(): ZohoMailClient
    {
        if (! ZohoMailClient::isConfigured()) {
            abort(503, 'Zoho Mail is not configured — set ZOHO_* env vars and re-run config:clear.');
        }
        return app(ZohoMailClient::class);
    }

    /** POST /mail/send — immediate send. */
    public function send(Request $request): JsonResponse
    {
        $payload = $this->validateZohoPayload($request);
        return $this->dispatchToZoho($request, $this->zoho(), $payload, scheduled: false);
    }

    /** POST /mail/schedule — send later. The frontend POSTs the same body as /send
     *  plus `scheduleType: 6` and `scheduleTime`. */
    public function schedule(Request $request): JsonResponse
    {
        $payload = $this->validateZohoPayload($request, scheduled: true);
        return $this->dispatchToZoho($request, $this->zoho(), $payload, scheduled: true);
    }

    /** DELETE /mail/schedule/{scheduledMailId} — cancel a scheduled send. */
    public function cancelScheduled(Request $request, string $scheduledMailId): JsonResponse
    {
        $ok = $this->zoho()->cancelScheduled($scheduledMailId);
        if ($ok) {
            MailMessage::query()
                ->where('tenant_id', $this->tenantId($request))
                ->where('zoho_scheduled_mail_id', $scheduledMailId)
                ->update(['status' => 'cancelled']);
        }
        return response()->json(['ok' => $ok]);
    }

    /** POST /mail/attachments — upload a file to Zoho and return its refs. */
    public function uploadAttachment(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:25600'], // 25 MB — matches Zoho's per-message limit
        ]);
        $upload = $request->file('file');
        if ($upload === null) {
            abort(422, 'file is required');
        }
        $zoho = $this->zoho();

        $stream = fopen($upload->getRealPath(), 'rb');
        if ($stream === false) {
            abort(500, 'cannot open uploaded file');
        }
        try {
            $refs = $zoho->uploadAttachment(
                filename: $upload->getClientOriginalName(),
                stream: $stream,
                contentType: $upload->getMimeType(),
            );
        } finally {
            fclose($stream);
        }

        MailAttachment::create([
            'tenant_id' => $this->tenantId($request),
            'mail_message_id' => null, // linked once the user sends with it
            'store_name' => $refs['storeName'],
            'attachment_name' => $refs['attachmentName'],
            'attachment_path' => $refs['attachmentPath'],
            'size_bytes' => $upload->getSize(),
            'mime_type' => $upload->getMimeType(),
            'uploaded_by_user_id' => $request->user()?->id,
        ]);

        return response()->json($refs);
    }

    /** GET /mail/incoming?since=<iso8601> — return inbound messages persisted by the poll job. */
    public function incoming(Request $request): JsonResponse
    {
        $request->validate([
            'since' => ['sometimes', 'date'],
        ]);
        $since = $request->date('since') ?? now()->subDay();
        $rows = MailMessage::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('direction', 'inbound')
            ->where('received_at', '>=', $since)
            ->with('thread:id,thread_id,subject,case_id')
            ->orderBy('received_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (MailMessage $m): array => [
                'threadId' => $m->thread?->thread_id,
                'caseId' => $m->thread?->case_id,
                'messageId' => $m->zoho_message_id,
                'fromAddress' => $m->from_address,
                'fromName' => $m->from_name,
                'to' => $m->to_addresses,
                'cc' => $m->cc_addresses,
                'subject' => $m->subject,
                'content' => $m->content,
                'mailFormat' => $m->mail_format,
                'receivedAt' => $m->received_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>  Zoho-shaped payload, augmented with our defaults.
     */
    private function validateZohoPayload(Request $request, bool $scheduled = false): array
    {
        $rules = [
            'fromAddress' => ['sometimes', 'string'],
            'toAddress' => ['required', 'string'],
            'ccAddress' => ['nullable', 'string'],
            'bccAddress' => ['nullable', 'string'],
            'subject' => ['required', 'string', 'max:998'],
            'content' => ['required', 'string'],
            'mailFormat' => ['required', 'in:plaintext,html'],
            'askReceipt' => ['nullable', 'in:yes,no'],
            'replyTo' => ['nullable', 'string'],
            'scheduleType' => ['sometimes', 'integer'],
            'scheduleTime' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*.storeName' => ['required_with:attachments', 'string'],
            'attachments.*.attachmentName' => ['required_with:attachments', 'string'],
            'attachments.*.attachmentPath' => ['required_with:attachments', 'string'],
        ];
        if ($scheduled) {
            $rules['scheduleTime'][] = 'required';
        }
        $payload = $request->validate($rules);

        // Fill defaults from config.
        $cfg = config('services.zoho');
        $defaultFrom = $cfg['from_name']
            ? "{$cfg['from_name']} <{$cfg['from_address']}>"
            : $cfg['from_address'];
        $payload['fromAddress'] = $payload['fromAddress'] ?? $defaultFrom;

        return $payload;
    }

    private function dispatchToZoho(
        Request $request,
        ZohoMailClient $zoho,
        array $payload,
        bool $scheduled,
    ): JsonResponse {
        $threadId = $this->extractOrMakeThreadId($payload);
        $payload['replyTo'] = $payload['replyTo']
            ?? $this->makeReplyTo($threadId);

        $thread = $this->upsertThread($request, $threadId, $payload);

        try {
            $data = $scheduled
                ? $zoho->scheduleMessage($payload)
                : $zoho->sendMessage($payload);
        } catch (RuntimeException $e) {
            $this->persistMessage($request, $thread, $payload, null, null, 'failed', $e->getMessage());
            throw $e;
        }

        $messageId = (string) ($data['messageId'] ?? '');
        $scheduledMailId = isset($data['scheduledMailId']) ? (string) $data['scheduledMailId'] : null;
        $this->persistMessage($request, $thread, $payload, $messageId, $scheduledMailId, $scheduled ? 'scheduled' : 'sent', null);

        // Return Zoho's shape verbatim so the frontend's ZohoSendResponse type works as-is.
        return response()->json([
            'status' => ['code' => 200, 'description' => 'success'],
            'data' => array_filter([
                'messageId' => $messageId,
                'scheduledMailId' => $scheduledMailId,
            ], static fn ($v) => $v !== null),
        ]);
    }

    /**
     * Subject of the form `... [#T-xxxxxx]` carries the thread id. Pull it
     * out; if missing, mint a new one. (The frontend already does this client-side
     * before calling us; this is the server-side safety net.)
     */
    private function extractOrMakeThreadId(array $payload): string
    {
        if (preg_match('/\[#(T-[A-Za-z0-9-]+)\]/', $payload['subject'], $m) === 1) {
            return $m[1];
        }
        return 'T-'.strtolower(\Illuminate\Support\Str::random(10));
    }

    private function makeReplyTo(string $threadId): string
    {
        $cfg = config('services.zoho');
        $prefix = $cfg['reply_alias_prefix'] ?? 'no-reply';
        $domain = explode('@', (string) $cfg['from_address'])[1] ?? 'insurehub.co.th';
        return "{$prefix}+{$threadId}@{$domain}";
    }

    private function upsertThread(Request $request, string $threadId, array $payload): MailThread
    {
        $now = now();
        return DB::transaction(function () use ($request, $threadId, $payload, $now): MailThread {
            $thread = MailThread::query()
                ->where('tenant_id', $this->tenantId($request))
                ->where('thread_id', $threadId)
                ->first();
            if ($thread === null) {
                $thread = MailThread::create([
                    'tenant_id' => $this->tenantId($request),
                    'thread_id' => $threadId,
                    'subject' => $payload['subject'],
                    'reply_to_address' => $payload['replyTo'] ?? $this->makeReplyTo($threadId),
                    'from_address' => $payload['fromAddress'],
                    'opened_by_user_id' => $request->user()?->id,
                    'first_message_at' => $now,
                    'last_message_at' => $now,
                ]);
            } else {
                $thread->update(['last_message_at' => $now]);
            }
            return $thread;
        });
    }

    private function persistMessage(
        Request $request,
        MailThread $thread,
        array $payload,
        ?string $messageId,
        ?string $scheduledMailId,
        string $status,
        ?string $error,
    ): MailMessage {
        $now = now();
        return MailMessage::create([
            'tenant_id' => $this->tenantId($request),
            'mail_thread_id' => $thread->id,
            'direction' => 'outbound',
            'zoho_message_id' => $messageId,
            'zoho_scheduled_mail_id' => $scheduledMailId,
            'from_address' => $payload['fromAddress'],
            'to_addresses' => $payload['toAddress'],
            'cc_addresses' => $payload['ccAddress'] ?? null,
            'bcc_addresses' => $payload['bccAddress'] ?? null,
            'reply_to' => $payload['replyTo'] ?? null,
            'subject' => $payload['subject'],
            'mail_format' => $payload['mailFormat'],
            'content' => $payload['content'],
            'status' => $status,
            'error' => $error,
            'scheduled_for' => isset($payload['scheduleTime']) ? $this->parseZohoTime($payload['scheduleTime']) : null,
            'sent_at' => $status === 'sent' ? $now : null,
            'zoho_payload' => $payload,
            'sent_by_user_id' => $request->user()?->id,
        ]);
    }

    /** Parse Zoho's "MM/dd/yyyy HH:mm:ss" UTC into a Carbon. */
    private function parseZohoTime(?string $raw): ?\Carbon\Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::createFromFormat('m/d/Y H:i:s', $raw, 'UTC');
        } catch (\Throwable $e) {
            Log::warning('Could not parse Zoho scheduleTime', ['raw' => $raw, 'err' => $e->getMessage()]);
            return null;
        }
    }
}
