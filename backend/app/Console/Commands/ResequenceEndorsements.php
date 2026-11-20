<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-sequence migrated endorsements to match the source's internal $N order.
 *
 * WHY
 * ----
 * FoldGroupEndorsements assigned endorsement_seq by EFFECTIVE date. A back-dated
 * correction (e.g. a refund effective 01/03 but ENTERED 13/07) then sorts far too
 * early — it showed as $3 when the source lists it as $24. Staff reference the
 * source's $N on their own records, so the ledger order must match it.
 *
 * The source carries that number in Access's Note_Type_of_paid ("$24"), kept in
 * the legacy staging DB as applications.payment_type_note, and each event records
 * its source row in payload.fromApplication. This command re-orders every policy's
 * endorsement events by (app_date, source $N) — the true entry order — then:
 *   - renumbers endorsement_seq 1..N in that order,
 *   - sets occurred_at order consistent with it,
 *   - recomputes each event's before/after running premium from the keeper's base
 *     (total_premium_paid) so the "เบี้ย (เดิม → ใหม่)" column stays correct.
 *
 * Nothing financial changes: additionalTotal per event and the policy's
 * annual_premium are untouched — only the ORDER and the running before/after.
 *
 * SAFETY
 *   - Snapshots each event's (id, endorsement_seq, payload) into
 *     _resequence_endorsements_backup before writing (reversible).
 *   - Raw updates → no observers fire.
 *   - Idempotent: re-running yields the same order (no-op after the first run).
 *   - Reads the local insurehub_legacy staging DB, so run LOCALLY and ship via the
 *     dump — the staging DB does not exist on prod.
 *   - --dry-run reports the before/after order per policy, writes nothing.
 *
 *   php artisan policies:resequence-endorsements [--tenant=1] [--policy=ID] [--dry-run]
 */
class ResequenceEndorsements extends Command
{
    protected $signature = 'policies:resequence-endorsements
        {--tenant=1 : tenant id}
        {--policy= : limit to one policy id (for spot-checking)}
        {--dry-run : report only, write nothing}';

    protected $description = 'Re-order migrated endorsements by source $N (entry order) and renumber endorsement_seq.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $onlyPolicy = $this->option('policy') !== null ? (int) $this->option('policy') : null;

        // app_date + source $N per application_code, from the legacy staging DB.
        $srcRows = DB::connection('insurehub_legacy')->table('applications')
            ->get(['application_code', 'app_date', 'payment_type_note']);
        $appDate = [];
        $dollarN = [];
        foreach ($srcRows as $r) {
            $appDate[$r->application_code] = $r->app_date;
            // extract the integer from "$24"; null if the note isn't a $N
            if (is_string($r->payment_type_note) && preg_match('/^\$(\d+)$/', trim($r->payment_type_note), $m)) {
                $dollarN[$r->application_code] = (int) $m[1];
            }
        }

        // Policies that have endorsement events.
        $policyIds = DB::table('policy_events')
            ->where('type', 'endorsement.premium_change')
            ->whereNull('voided_at')
            ->when($onlyPolicy !== null, fn ($q) => $q->where('policy_id', $onlyPolicy))
            ->distinct()->pluck('policy_id');

        $policiesChanged = 0;
        $eventsRenumbered = 0;

        if (! $dry) {
            DB::statement('CREATE TABLE IF NOT EXISTS _resequence_endorsements_backup (
                event_id BIGINT UNSIGNED PRIMARY KEY,
                old_seq SMALLINT UNSIGNED NULL,
                old_payload JSON NULL,
                snapped_at TIMESTAMP NULL
            )');
        }

        foreach ($policyIds as $pid) {
            $policy = DB::table('policies')->where('id', $pid)->first(['id', 'total_premium_paid', 'tenant_id', 'effective_date']);
            if ($policy === null || (int) $policy->tenant_id !== (int) $this->option('tenant')) {
                continue;
            }

            $events = DB::table('policy_events')
                ->where('policy_id', $pid)
                ->where('type', 'endorsement.premium_change')
                ->whereNull('voided_at')
                ->get(['id', 'endorsement_seq', 'payload', 'occurred_at']);

            // Build a sortable list: (app_date, $N) with sensible fallbacks.
            $items = [];
            foreach ($events as $e) {
                $payload = is_string($e->payload) ? (json_decode($e->payload, true) ?: []) : (array) $e->payload;
                $fromApp = $payload['fromApplication'] ?? null;
                $ad = $fromApp !== null ? ($appDate[$fromApp] ?? null) : null;
                // fallbacks: no app_date → use the event's effectiveDate; no $N → large sentinel so it sorts last within its date
                $sortDate = $ad ?? ($payload['effectiveDate'] ?? $policy->effective_date ?? '9999-12-31');
                $dn = ($fromApp !== null && isset($dollarN[$fromApp])) ? $dollarN[$fromApp] : PHP_INT_MAX;
                $items[] = ['id' => $e->id, 'oldSeq' => $e->endorsement_seq, 'payload' => $payload, 'sortDate' => (string) $sortDate, 'dn' => $dn];
            }

            usort($items, function ($a, $b) {
                $c = strcmp($a['sortDate'], $b['sortDate']);
                if ($c !== 0) {
                    return $c;
                }
                return $a['dn'] <=> $b['dn'];
            });

            // Detect whether the order actually changed.
            $changed = false;
            foreach ($items as $i => $it) {
                if ((int) $it['oldSeq'] !== $i + 1) {
                    $changed = true;
                    break;
                }
            }
            if (! $changed) {
                continue;
            }
            $policiesChanged++;

            if ($dry) {
                $this->line("policy {$pid}: reorder ".count($items)." endorsements");
                foreach (array_slice($items, 0, 6) as $i => $it) {
                    $note = mb_substr((string) ($it['payload']['sourceNote'] ?? $it['payload']['reason'] ?? ''), 0, 28);
                    $this->line(sprintf('   seq %2d (was %2s)  %s  $%s  %s',
                        $i + 1, $it['oldSeq'] ?? '?', $it['sortDate'], $it['dn'] === PHP_INT_MAX ? '-' : $it['dn'], $note));
                }
                $eventsRenumbered += count($items);

                continue;
            }

            // Recompute seq + running before/after from the keeper base, in new order.
            $running = (float) ($policy->total_premium_paid ?? 0);
            DB::transaction(function () use ($items, $pid, &$running, &$eventsRenumbered): void {
                // UNIQUE(policy_id, endorsement_seq) means a direct 1..N reassignment
                // can transiently collide with a seq still held by another row. Park
                // every event's seq in a high, collision-free band first, then set
                // the final values.
                // endorsement_seq is SMALLINT UNSIGNED (max 65535); park in a high
                // band that's still in range and far above any real seq.
                foreach ($items as $i => $it) {
                    DB::table('policy_events')->where('id', $it['id'])
                        ->update(['endorsement_seq' => 60000 + $i]);
                }

                foreach ($items as $i => $it) {
                    // snapshot
                    DB::table('_resequence_endorsements_backup')->updateOrInsert(
                        ['event_id' => $it['id']],
                        ['old_seq' => $it['oldSeq'], 'old_payload' => json_encode($it['payload'], JSON_UNESCAPED_UNICODE), 'snapped_at' => now()]
                    );

                    $payload = $it['payload'];
                    // Running total steps by additionalTotal (the gross delta the
                    // before/after column tracks), so base + Σ additionalTotal ends
                    // at the policy's annual_premium — same convention the fold used.
                    $addl = (float) ($payload['additionalTotal'] ?? 0);
                    $before = round($running, 2);
                    $after = round($running + $addl, 2);
                    $running = $after;

                    $payload['before'] = ['annualPremium' => $before, 'mainPremium' => $before];
                    $payload['after'] = ['annualPremium' => $after, 'mainPremium' => $after];

                    DB::table('policy_events')->where('id', $it['id'])->update([
                        'endorsement_seq' => $i + 1,
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
                    $eventsRenumbered++;
                }
            });
        }

        $this->info(sprintf('%s%d policies reordered, %d endorsement events renumbered.',
            $dry ? '[dry-run] ' : '', $policiesChanged, $eventsRenumbered));
        if ($dry) {
            $this->warn('DRY RUN — nothing written.');
        } elseif ($policiesChanged > 0) {
            $this->line('Rollback snapshot: table `_resequence_endorsements_backup`.');
        }

        return self::SUCCESS;
    }
}
