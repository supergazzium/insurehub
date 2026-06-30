<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MailMessage;
use App\Models\MailThread;
use App\Services\Mail\ZohoMailClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Poll Zoho Mail for inbound replies and persist them.
 *
 * Looks for messages addressed to `no-reply+T-xxx@<tenant_domain>` (the
 * plus-address Reply-To we set on outbound mail). The thread id is
 * extracted from the alias and used to associate the inbound message with
 * the right `mail_threads` row.
 *
 * Scheduled to run every minute via routes/console.php.
 */
class PollZohoMail extends Command
{
    protected $signature = 'mail:poll {--window=10 : Minutes of history to scan}';

    protected $description = 'Poll Zoho Mail for inbound replies and persist them as mail_messages.';

    private const LAST_RUN_CACHE_KEY = 'zoho.mail.poll.last_run';

    public function handle(): int
    {
        if (! ZohoMailClient::isConfigured()) {
            $this->warn('Zoho is not configured (ZOHO_* env vars missing). Skipping poll.');
            return self::SUCCESS;
        }
        $zoho = app(ZohoMailClient::class);

        $windowMinutes = (int) ($this->option('window') ?? config('services.zoho.poll_window_minutes', 10));
        $cutoff = Cache::get(self::LAST_RUN_CACHE_KEY)
            ?? now()->subMinutes($windowMinutes)->toIso8601String();
        $cutoffCarbon = Carbon::parse($cutoff);

        try {
            $messages = $zoho->searchMessages($cutoffCarbon);
        } catch (RuntimeException $e) {
            $this->error('Zoho fetch failed: '.$e->getMessage());
            Log::error('PollZohoMail fetch failed', ['err' => $e->getMessage()]);
            return self::FAILURE;
        }

        if ($messages === []) {
            $this->info('No new messages since '.$cutoffCarbon->toIso8601String());
            Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), 86400);
            return self::SUCCESS;
        }

        $persisted = 0;
        $skipped = 0;
        foreach ($messages as $message) {
            $persistedNow = $this->ingestMessage($zoho, $message);
            if ($persistedNow) {
                $persisted++;
            } else {
                $skipped++;
            }
        }

        $this->info("Polled {$cutoffCarbon->toIso8601String()} — persisted={$persisted} skipped={$skipped}");
        Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), 86400);
        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $summary  one entry from Zoho's search response
     */
    private function ingestMessage(ZohoMailClient $zoho, array $summary): bool
    {
        $messageId = (string) ($summary['messageId'] ?? '');
        if ($messageId === '') {
            return false;
        }

        // Dedupe: we may see the same message in successive polls.
        if (MailMessage::query()->where('zoho_message_id', $messageId)->exists()) {
            return false;
        }

        // Find the thread by scanning To + CC for our plus-alias.
        $aliasPrefix = config('services.zoho.reply_alias_prefix', 'no-reply').'+';
        $toCc = (string) ($summary['toAddress'] ?? '').' '.(string) ($summary['ccAddress'] ?? '');
        if (! preg_match('/'.preg_quote($aliasPrefix, '/').'(T-[A-Za-z0-9-]+)@/', $toCc, $m)) {
            // Not a tracked thread reply — ignore.
            return false;
        }
        $threadId = $m[1];

        $thread = MailThread::query()->where('thread_id', $threadId)->first();
        if ($thread === null) {
            Log::info('Inbound mail references unknown thread', ['threadId' => $threadId, 'messageId' => $messageId]);
            return false;
        }

        $content = '';
        $mailFormat = 'html';
        try {
            $full = $zoho->fetchMessage($messageId);
            $content = (string) ($full['content'] ?? '');
            $mailFormat = (string) ($full['mailFormat'] ?? 'html');
        } catch (RuntimeException $e) {
            Log::warning('Could not fetch full body — persisting summary only', [
                'messageId' => $messageId,
                'err' => $e->getMessage(),
            ]);
        }

        MailMessage::create([
            'tenant_id' => $thread->tenant_id,
            'mail_thread_id' => $thread->id,
            'direction' => 'inbound',
            'zoho_message_id' => $messageId,
            'from_address' => (string) ($summary['fromAddress'] ?? ''),
            'from_name' => (string) ($summary['sender'] ?? '') ?: null,
            'to_addresses' => (string) ($summary['toAddress'] ?? ''),
            'cc_addresses' => (string) ($summary['ccAddress'] ?? '') ?: null,
            'subject' => (string) ($summary['subject'] ?? ''),
            'mail_format' => $mailFormat,
            'content' => $content,
            'status' => 'received',
            'received_at' => isset($summary['receivedTime'])
                ? Carbon::createFromTimestampMs((int) $summary['receivedTime'])
                : now(),
            'zoho_payload' => $summary,
        ]);

        $thread->update(['last_message_at' => now()]);
        return true;
    }
}
