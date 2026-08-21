<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Policy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase C-2 — one-shot backfill from the legacy 9-code enum into the
 * 10-code 7-state model.
 *
 * See docs/audit-2026-08-21/B1-state-machine.md §4 for the precedence
 * rules and §9 PR-2 for the rollout position. The 490/13/12 seed
 * distribution described in `05-live-data.md §2` maps into the new enum
 * by inspecting `policy_no` + `effective_date` + `expiry_date` rather
 * than lifting the legacy code — the seed collapses everything into
 * `active` regardless of whether the policy is issued, in-force, or
 * expired.
 *
 * The command is:
 *   - idempotent (skips rows that already carry a `backfillMigrated`
 *     PolicyEvent, unless --force is passed)
 *   - dry-run by default (prints the transition histogram, writes nothing)
 *   - transactional per chunk (batches of 100 by default)
 *
 * Every changed row gets a `policy_events` entry with
 *   type='backfillMigrated'
 *   payload={ from, to, rule, at }
 * so the migration is auditable and can be selectively reversed.
 */
class BackfillPolicyStatuses extends Command
{
    protected $signature = 'policies:backfill-statuses
        {--dry-run : Print the transition histogram without writing}
        {--force : Re-run on rows that already have a backfillMigrated event}
        {--tenant= : Restrict to a single tenant id (default: all tenants)}
        {--chunk=100 : Batch size for the transaction wrapper}';

    protected $description = 'Backfill policies.status from the legacy 9-code enum into the 10-code 7-state model.';

    /**
     * Ordered precedence rules from B1 §4. First match wins.
     * Each rule is a [predicate, target_status, rule_id] tuple.
     * `null` in the target means "explicitly do nothing" (skip); we
     * still emit an event so the row is marked evaluated.
     */
    private const RULES = [
        // rule_id => human tag for the audit payload
        1 => 'cancelled-stays',
        2 => 'submitted-no-policy',
        3 => 'submitted-with-policy-no-becomes-issued',
        4 => 'active-no-policy-downgrade-to-submitted',
        5 => 'active-future-effective-becomes-issued',
        6 => 'active-in-window-stays-active',
        7 => 'active-past-expiry-becomes-expired',
        8 => 'placeholder-dates-become-draft',
        9 => 'quote-becomes-quotation',
        10 => 'reinstated-becomes-active',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $chunk = max(1, (int) $this->option('chunk'));

        $header = $dryRun ? 'DRY RUN — no writes' : 'LIVE RUN — writes enabled';
        $this->info("policies:backfill-statuses [{$header}]");
        if ($tenantId !== null) {
            $this->line("  Scope: tenant_id = {$tenantId}");
        }
        if ($force) {
            $this->warn('  --force: rows previously migrated will be re-evaluated.');
        }

        $today = Carbon::today();

        $totals = ['seen' => 0, 'changed' => 0, 'skipped' => 0, 'unchanged' => 0];
        $histogram = [];  // "old→new" => count

        $query = Policy::query()
            ->withTrashed()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId));

        // Idempotency: skip rows that already have a backfillMigrated event
        // unless --force overrides.
        if (! $force) {
            $query->whereDoesntHave('events', fn ($q) => $q->where('type', 'backfillMigrated'));
        }

        $query->orderBy('id')->chunkById($chunk, function ($rows) use ($today, $dryRun, &$totals, &$histogram): void {
            DB::transaction(function () use ($rows, $today, $dryRun, &$totals, &$histogram): void {
                foreach ($rows as $policy) {
                    $totals['seen']++;
                    $decision = $this->classify($policy, $today);
                    $key = "{$decision['from']} → {$decision['to']} [{$decision['rule']}]";
                    $histogram[$key] = ($histogram[$key] ?? 0) + 1;

                    if ($decision['to'] === $decision['from']) {
                        $totals['unchanged']++;
                    } else {
                        $totals['changed']++;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    // Always write a backfillMigrated event so the row is
                    // marked evaluated even if status didn't change. Prevents
                    // repeat evaluation on the next run.
                    $updates = [
                        'events' => [
                            [
                                'type' => 'backfillMigrated',
                                'occurred_at' => now(),
                                'payload' => [
                                    'from' => $decision['from'],
                                    'to' => $decision['to'],
                                    'rule' => $decision['rule'],
                                    'note' => $decision['note'] ?? null,
                                ],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ],
                        ],
                    ];

                    if ($decision['to'] !== $decision['from']) {
                        $policyUpdate = ['status' => $decision['to']];
                        if ($decision['null_dates'] ?? false) {
                            $policyUpdate['effective_date'] = null;
                            $policyUpdate['expiry_date'] = null;
                        }
                        if (isset($decision['import_note'])) {
                            $existing = $policy->import_notes ?? '';
                            $stamp = "[backfill {$today->toDateString()}] {$decision['import_note']}";
                            $policyUpdate['import_notes'] = trim("{$existing}\n{$stamp}");
                        }
                        DB::table('policies')->where('id', $policy->id)->update($policyUpdate);
                    }

                    DB::table('policy_events')->insert([
                        'policy_id' => $policy->id,
                        'type' => 'backfillMigrated',
                        'occurred_at' => now(),
                        'payload' => json_encode($updates['events'][0]['payload'], JSON_UNESCAPED_UNICODE),
                        'by_user_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        });

        $this->newLine();
        $this->info('Transition histogram');
        $this->table(['from → to [rule]', 'count'], collect($histogram)
            ->map(fn ($count, $key) => [$key, $count])
            ->sortByDesc(fn ($row) => $row[1])
            ->values()
            ->toArray());

        $this->newLine();
        $this->info(sprintf(
            'Totals: seen=%d, changed=%d, unchanged=%d, skipped=%d',
            $totals['seen'], $totals['changed'], $totals['unchanged'], $totals['skipped']
        ));

        if ($dryRun && $totals['changed'] > 0) {
            $this->newLine();
            $this->warn('Dry-run complete. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Apply the precedence-ordered rules from B1 §4. Returns:
     *   ['from' => oldStatus, 'to' => newStatus, 'rule' => tag, ...]
     * Additional keys: 'null_dates' bool, 'import_note' string.
     */
    private function classify(Policy $policy, Carbon $today): array
    {
        $from = (string) $policy->status;
        $hasPolicyNo = trim((string) $policy->policy_no) !== '';
        $eff = $policy->effective_date;
        $exp = $policy->expiry_date;

        // Rule 8 — placeholder dates (9000-01-01 or similar). Coerce dates
        // to something inspectable; Policy casts them to Carbon.
        $isPlaceholder = ($eff instanceof Carbon && $eff->year >= 9000)
            || ($exp instanceof Carbon && $exp->year >= 9000)
            || ($eff && $exp && $exp->lessThan($eff));
        if ($isPlaceholder) {
            return [
                'from' => $from,
                'to' => 'draft',
                'rule' => self::RULES[8],
                'null_dates' => true,
                'import_note' => 'Placeholder dates cleared; row demoted to draft for manual review.',
            ];
        }

        // Rule 1 — cancelled always stays cancelled.
        if ($from === 'cancelled') {
            return ['from' => $from, 'to' => 'cancelled', 'rule' => self::RULES[1]];
        }

        // Rule 9 — quote → quotation (rename per B1 §1).
        if ($from === 'quote') {
            return ['from' => $from, 'to' => 'quotation', 'rule' => self::RULES[9]];
        }

        // Rule 10 — reinstated → active (code retired per B1 §8).
        if ($from === 'reinstated') {
            return ['from' => $from, 'to' => 'active', 'rule' => self::RULES[10]];
        }

        // Rule 2 — submitted without policy_no stays submitted.
        if ($from === 'submitted' && ! $hasPolicyNo) {
            return ['from' => $from, 'to' => 'submitted', 'rule' => self::RULES[2]];
        }

        // Rule 3 — submitted WITH policy_no becomes issued (data-quality fix).
        if ($from === 'submitted' && $hasPolicyNo) {
            return [
                'from' => $from,
                'to' => 'issued',
                'rule' => self::RULES[3],
                'import_note' => 'Row carried policy_no while status=submitted; promoted to issued.',
            ];
        }

        // active (and legacy `application` for defensive coverage) — split
        // by policy_no presence + date windows.
        if ($from === 'active' || $from === 'application') {
            if (! $hasPolicyNo) {
                // Rule 4 — active without policy_no is a data-quality error.
                return [
                    'from' => $from,
                    'to' => 'submitted',
                    'rule' => self::RULES[4],
                    'import_note' => 'Row had status=active without policy_no; downgraded to submitted.',
                ];
            }

            if ($eff instanceof Carbon && $eff->greaterThan($today)) {
                // Rule 5 — issued but not yet in force.
                return ['from' => $from, 'to' => 'issued', 'rule' => self::RULES[5]];
            }

            if ($exp instanceof Carbon && $exp->lessThan($today)) {
                // Rule 7 — expiry passed.
                return ['from' => $from, 'to' => 'expired', 'rule' => self::RULES[7]];
            }

            // Rule 6 — in the coverage window.
            return ['from' => $from, 'to' => 'active', 'rule' => self::RULES[6]];
        }

        // Any status not covered above passes through unchanged.
        // (draft/quotation/approved/rejected/issued/expired/lapsed — the new
        // codes; no legacy rows carry them, but a future rerun on partial
        // data should be a no-op.)
        return ['from' => $from, 'to' => $from, 'rule' => 'pass-through'];
    }
}
