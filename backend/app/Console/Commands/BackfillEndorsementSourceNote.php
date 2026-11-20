<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Attach the ORIGINAL source note (Access Internal_Note) to each migrated
 * endorsement event, so a user can see the real reason the endorsement was
 * raised — e.g. "แจ้งเพิ่ม 371 คน", "แจ้งเข้า 16 คน", "IN260677_นาวิน จ่าย 6%".
 *
 * WHY
 * ----
 * Migrated endorsements live as policy_events of type 'endorsement.premium_change'.
 * Their payload carries a generic system `reason` ("สลักหลัง (เปลี่ยนแปลง…)") but
 * NOT the specific per-endorsement note the staff typed in the source. Each event
 * records which source Application row it came from (payload.fromApplication), and
 * that row's Internal_Note is the original reason. This command copies it in under
 * a new `sourceNote` key so the frontend (which passes the whole payload through)
 * can surface it — the system `reason` is left untouched.
 *
 * SCOPE
 *   endorsement.premium_change events, not voided, whose payload.fromApplication
 *   resolves to a legacy applications row with a non-empty internal_note and which
 *   do not already carry a sourceNote. ~252 of 335 events have a source note; the
 *   rest had no note in the source and are left as-is.
 *
 * SAFETY
 *   - Only writes the payload JSON of matched events (adds one key); touches no
 *     money, premium, or payment data.
 *   - Raw update → no observers fire.
 *   - Idempotent: an event that already has sourceNote is skipped.
 *   - --dry-run reports counts + samples, writes nothing.
 *
 *   php artisan policies:backfill-endorsement-note [--tenant=1] [--dry-run]
 */
class BackfillEndorsementSourceNote extends Command
{
    protected $signature = 'policies:backfill-endorsement-note
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}';

    protected $description = 'Attach the original Access Internal_Note to migrated endorsement events (payload.sourceNote).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Source notes keyed by application_code (from the legacy staging DB).
        $notes = DB::connection('insurehub_legacy')
            ->table('applications')
            ->whereNotNull('internal_note')
            ->where('internal_note', '<>', '')
            ->pluck('internal_note', 'application_code');

        $events = DB::table('policy_events')
            ->where('type', 'endorsement.premium_change')
            ->whereNull('voided_at')
            ->get(['id', 'payload']);

        $updated = 0;
        $skippedNoNote = 0;
        $skippedHasNote = 0;
        $samples = [];

        foreach ($events as $e) {
            $payload = is_string($e->payload) ? (json_decode($e->payload, true) ?: []) : (array) $e->payload;

            if (array_key_exists('sourceNote', $payload)) {
                $skippedHasNote++;

                continue;
            }

            $fromApp = $payload['fromApplication'] ?? null;
            $note = $fromApp !== null ? ($notes[$fromApp] ?? null) : null;
            if ($note === null || trim((string) $note) === '') {
                $skippedNoNote++;

                continue;
            }

            $payload['sourceNote'] = (string) $note;

            if (count($samples) < 8) {
                $samples[] = [$fromApp, mb_substr((string) $note, 0, 48)];
            }

            if (! $dry) {
                DB::table('policy_events')->where('id', $e->id)->update([
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
            $updated++;
        }

        $this->info(sprintf(
            '%s%d endorsement events enriched with sourceNote  (skipped: %d no source note, %d already had one).',
            $dry ? '[dry-run] ' : '',
            $updated, $skippedNoNote, $skippedHasNote
        ));
        if ($samples) {
            $this->table(['fromApplication', 'sourceNote (truncated)'], $samples);
        }
        if ($dry) {
            $this->warn('DRY RUN — nothing written.');
        }

        return self::SUCCESS;
    }
}
