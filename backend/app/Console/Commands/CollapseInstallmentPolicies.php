<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PolicyEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Collapse the legacy "one Application row per payment" artifact into the correct
 * "1 policy -> many payments" model.
 *
 * The 25/10/2026 Access export stored each INSTALLMENT / RENEWAL / ENDORSEMENT
 * payment of a policy as its OWN Application row (own application_code) sharing
 * the same Client_Code + Policy_Number + Product. The importer created one
 * POLICY per row, so a 73-payment health policy became 73 policies. This command
 * re-parents every member row's payment onto a single surviving policy and
 * soft-deletes the duplicates.
 *
 * Driven ENTIRELY by a reviewed manifest CSV (collapse_manifest.csv) built from
 * the source + the user's per-group verdicts (Patterns A/B unified rule + the
 * Pattern C overrides). This command does NOT re-derive groups itself — it
 * executes the approved decisions, so it is auditable and matches review.
 *
 * Per manifest row `action`:
 *   collapse            — unified rule: keeper survives, members' payments move,
 *                         members soft-deleted. Keeper = latest app_date (already
 *                         carries the latest year's premium — no recompute).
 *   collapse-datafix3   — same mechanics (the #3 iProtect/iCare one-policy fix);
 *                         the rider product-code correction is a separate step.
 *   endorsement-fold4   — fold a $N endorsement row into its base policy: move its
 *                         payment (flagged adjustment), bump endorsement_count,
 *                         soft-delete the endorsement policy. Other rows untouched.
 *   leave-asis          — co-insurance (#5): do NOTHING (kept separate on purpose).
 *
 * Child rows: the keeper keeps its own rebate/installments. Each soft-deleted
 * member's rebate/installment rows are soft-deleted WITH it (recoverable),
 * avoiding the UNIQUE(policy_id) rebate collision. Only policy_payments (the
 * real money) are moved to the keeper.
 *
 * Observer-safe: payment re-parent is a raw DB::table()->update(), so
 * PolicyPaymentObserver never fires (no MGM re-accrual on historical data).
 *
 * Idempotent: a member already soft-deleted, or a keeper that already owns the
 * member's payments, is skipped. Re-running is a no-op.
 *
 *   php artisan policies:collapse-installments --manifest=/path/to/collapse_manifest.csv \
 *       [--product-fixes=/path/to/collapse_product_fixes.csv] [--tenant=1] [--dry-run] [--force]
 */
class CollapseInstallmentPolicies extends Command
{
    protected $signature = 'policies:collapse-installments
        {--manifest= : path to collapse_manifest.csv (required)}
        {--product-fixes= : optional path to collapse_product_fixes.csv}
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}
        {--force : skip the destructive-write confirmation}
        {--cleanup-orphans : delete rebate/installment rows that point at soft-deleted policies, then exit}';

    protected $description = 'Collapse legacy per-payment duplicate policies into 1 policy + many payments (manifest-driven).';

    /** Child tables whose rows belong to ONE policy and must follow a soft-deleted member. */
    private const FOLLOW_SOFT_DELETE = ['policy_rebates', 'policy_installments'];

    public function handle(): int
    {
        $manifestPath = (string) $this->option('manifest');
        if ($manifestPath === '' || ! is_file($manifestPath)) {
            $this->error('--manifest=<collapse_manifest.csv> is required and must exist.');

            return self::FAILURE;
        }
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        if ((bool) $this->option('cleanup-orphans')) {
            return $this->cleanupOrphans($tenant, $dry);
        }

        $manifest = $this->readManifest($manifestPath);
        $this->info(($dry ? '[dry-run] ' : '').'Loaded '.count($manifest).' manifest groups.');

        if (! $dry && ! $this->option('force')) {
            if (! $this->confirm('This will SOFT-DELETE duplicate policies and re-parent their payments. Continue?')) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }
        }

        // Pre-flight money total (across every policy we will touch), to reconcile after.
        $before = $this->paymentUniverse($manifest, $tenant);

        $stats = [
            'groups_collapsed' => 0, 'groups_skipped' => 0, 'groups_leave_asis' => 0,
            'policies_softdeleted' => 0, 'payments_moved' => 0,
            'endorsements_folded' => 0, 'child_rows_softdeleted' => 0,
            'members_unmapped' => 0,
        ];

        $now = Carbon::now();

        foreach ($manifest as $g) {
            if ($g['action'] === 'leave-asis') {
                $stats['groups_leave_asis']++;

                continue;
            }

            $keeper = $this->livePolicy($g['keeper_app'], $tenant);
            if ($keeper === null) {
                // keeper never migrated (should not happen — manifest verified) — skip whole group
                $stats['groups_skipped']++;
                $this->warn("  skip group {$g['group_id']} ({$g['client_code']}/{$g['policy_no']}): keeper {$g['keeper_app']} not a live policy.");

                continue;
            }

            $memberIds = [];
            foreach ($g['member_apps'] as $app) {
                $m = $this->livePolicy($app, $tenant);
                if ($m === null) {
                    $stats['members_unmapped']++;   // e.g. dropped empty shell A2506140028
                    continue;
                }
                if ((int) $m->id === (int) $keeper->id) {
                    continue; // defensive: keeper listed as its own member
                }
                $memberIds[] = (int) $m->id;
            }

            if ($memberIds === []) {
                $stats['groups_skipped']++;   // already collapsed on a prior run, or all unmapped

                continue;
            }

            $isEndorsementFold = $g['action'] === 'endorsement-fold4';

            if ($dry) {
                $moved = DB::table('policy_payments')->whereIn('policy_id', $memberIds)->count();
                $stats['payments_moved'] += $moved;
                $stats['policies_softdeleted'] += count($memberIds);
                $stats['groups_collapsed']++;
                if ($isEndorsementFold) {
                    $stats['endorsements_folded'] += count($memberIds);
                }

                continue;
            }

            DB::transaction(function () use ($keeper, $memberIds, $isEndorsementFold, $now, &$stats, $g): void {
                // 1. move payments to keeper (raw update => observer silent)
                if ($isEndorsementFold) {
                    // flag the moved payment(s) as an endorsement adjustment
                    $moved = DB::table('policy_payments')->whereIn('policy_id', $memberIds)
                        ->update(['policy_id' => $keeper->id, 'adjustment_kind' => 'endorsement', 'updated_at' => $now]);
                    DB::table('policies')->where('id', $keeper->id)
                        ->update(['endorsement_count' => DB::raw('COALESCE(endorsement_count,0) + '.count($memberIds)), 'updated_at' => $now]);
                    $stats['endorsements_folded'] += count($memberIds);
                } else {
                    $moved = DB::table('policy_payments')->whereIn('policy_id', $memberIds)
                        ->update(['policy_id' => $keeper->id, 'updated_at' => $now]);
                }
                $stats['payments_moved'] += $moved;

                // 1b. Recompute the keeper's coverage span + installment term from
                //     the WHOLE contract. The legacy rows carry per-INSTALLMENT
                //     coverage windows (each ~1 month) in effective_date/expiry_date,
                //     and the keeper (last-installment row) often has degraded term
                //     data. The true annual policy coverage is MIN(effective) ..
                //     MAX(expiry) across all rows, and the real term is the payment
                //     count. Only for installment collapses, not endorsement-folds.
                if (! $isEndorsementFold) {
                    $allIds = array_merge([$keeper->id], $memberIds);
                    $span = DB::table('policies')->whereIn('id', $allIds)
                        ->selectRaw('MIN(effective_date) mn, MAX(expiry_date) mx')->first();
                    // The keeper's own app_date is this contract-year's write date and
                    // is the reliable coverage START — the per-installment
                    // effective_date rows often carry a stale inherited coverage_start
                    // (e.g. year-2 installments still stamped with year-1's start), so
                    // MIN(effective) would pull the span back a year. Prefer app_date;
                    // fall back to MIN(effective) only when app_date is missing.
                    $kp = DB::table('policies')->where('id', $keeper->id)->first(['app_date']);
                    $effective = ($kp && $kp->app_date !== null) ? $kp->app_date
                        : ($span->mn ?? null);
                    // installment term = number of payments now on the keeper
                    $payCount = (int) DB::table('policy_payments')->where('policy_id', $keeper->id)
                        ->whereNull('voided_at')->count();
                    $covUpd = ['updated_at' => $now];
                    if ($effective !== null) {
                        $covUpd['effective_date'] = $effective;
                    }
                    if ($span && $span->mx !== null) {
                        $covUpd['expiry_date'] = $span->mx;
                    }
                    if ($payCount > 1) {
                        $covUpd['installment_term'] = (string) $payCount;
                        $covUpd['premium_mode'] = $this->premiumModeFromCount($payCount);
                    }
                    DB::table('policies')->where('id', $keeper->id)->update($covUpd);
                }

                // 2. remove member policies' per-policy children (they belong to a
                //    policy that is going away; the keeper keeps its own). These
                //    tables have no deleted_at, so soft-delete if available else
                //    HARD-delete the orphan rows (no money lost — real amounts are
                //    all on the keeper's moved payments).
                foreach (self::FOLLOW_SOFT_DELETE as $t) {
                    if (DB::getSchemaBuilder()->hasColumn($t, 'deleted_at')) {
                        $c = DB::table($t)->whereIn('policy_id', $memberIds)->whereNull('deleted_at')
                            ->update(['deleted_at' => $now]);
                    } else {
                        $c = DB::table($t)->whereIn('policy_id', $memberIds)->delete();
                    }
                    $stats['child_rows_softdeleted'] += $c;
                }

                // 3. soft-delete the member policies themselves (breadcrumb in notes if present)
                $noteCol = DB::getSchemaBuilder()->hasColumn('policies', 'notes') ? 'notes' : null;
                foreach ($memberIds as $mid) {
                    $upd = ['deleted_at' => $now, 'updated_at' => $now];
                    if ($noteCol !== null) {
                        $upd[$noteCol] = DB::raw("TRIM(CONCAT(COALESCE($noteCol,''), '\n[collapsed into policy #{$keeper->id} — legacy per-payment dup, group {$g['group_id']}]'))");
                    }
                    DB::table('policies')->where('id', $mid)->update($upd);
                }
                $stats['policies_softdeleted'] += count($memberIds);

                // 4. refresh keeper total_premium_paid = sum of its (now complete) payments
                $sumPaid = (float) DB::table('policy_payments')->where('policy_id', $keeper->id)
                    ->whereNull('voided_at')->sum('amount');
                DB::table('policies')->where('id', $keeper->id)
                    ->update(['total_premium_paid' => $sumPaid, 'updated_at' => $now]);

                // 5. audit event on keeper
                PolicyEvent::create([
                    'policy_id' => $keeper->id,
                    'type' => 'installmentCollapse',
                    'occurred_at' => $now,
                    'by_user_id' => null,
                    'payload' => [
                        'collapsedPolicyIds' => $memberIds,
                        'paymentsMoved' => $moved,
                        'action' => $g['action'],
                        'group' => $g['group_id'],
                        'source' => 'legacy-collapse',
                    ],
                ]);
            });

            $stats['groups_collapsed']++;
        }

        // Product-fixes (#4 health->accident) — optional companion CSV.
        $pfPath = (string) $this->option('product-fixes');
        $pfApplied = 0;
        if ($pfPath !== '' && is_file($pfPath)) {
            $pfApplied = $this->applyProductFixes($pfPath, $tenant, $dry, $now);
        }

        // Renumber policy_year sequentially per (customer, policy_no, product) by
        // coverage start. The legacy renewal chain (ref_app_to_id) is not linked
        // across these yearly contracts, so the source Policy_Year was inconsistent
        // (e.g. 1,2,1,2); collapsing the installments exposes it. A straight
        // ordinal over the surviving contracts is correct and deterministic.
        $yearsFixed = 0;
        if (! $dry) {
            $yearsFixed = DB::update(
                'UPDATE policies p '.
                'JOIN ( SELECT id, ROW_NUMBER() OVER ( '.
                '  PARTITION BY customer_id, policy_no, product_id '.
                '  ORDER BY COALESCE(effective_date, app_date), id ) AS seq '.
                '  FROM policies '.
                "  WHERE deleted_at IS NULL AND policy_no IS NOT NULL AND policy_no<>'' AND tenant_id = ? ".
                ') r ON r.id = p.id '.
                'SET p.policy_year = r.seq, p.updated_at = NOW() '.
                "WHERE p.deleted_at IS NULL AND p.policy_no IS NOT NULL AND p.policy_no<>'' AND p.tenant_id = ? ".
                'AND p.policy_year <> r.seq',
                [$tenant, $tenant]
            );
        }

        // Refresh customers' cached policy counts — the soft-deletes make the
        // cached total/active counts stale. Derived recompute, safe to re-run.
        $countsFixed = 0;
        if (! $dry) {
            $countsFixed = DB::update(
                'UPDATE customers c SET '.
                'c.total_policy_count = (SELECT COUNT(*) FROM policies p WHERE p.customer_id=c.id AND p.deleted_at IS NULL), '.
                'c.active_policy_count = (SELECT COUNT(*) FROM policies p WHERE p.customer_id=c.id AND p.deleted_at IS NULL AND p.status NOT IN (?,?)) '.
                'WHERE c.deleted_at IS NULL AND c.tenant_id = ?',
                ['cancelled', 'expired', $tenant]
            );
        }

        $after = $this->paymentUniverse($manifest, $tenant);

        $this->newLine();
        $this->table(['metric', 'value'], [
            ['groups collapsed', $stats['groups_collapsed']],
            ['groups leave-as-is', $stats['groups_leave_asis']],
            ['groups skipped (already/empty)', $stats['groups_skipped']],
            ['policies soft-deleted', $stats['policies_softdeleted']],
            ['payments moved to keepers', $stats['payments_moved']],
            ['endorsements folded ($N)', $stats['endorsements_folded']],
            ['child rows soft-deleted', $stats['child_rows_softdeleted']],
            ['members unmapped (dropped shells)', $stats['members_unmapped']],
            ['product-fixes applied', $pfApplied],
            ['policy_year renumbered', $yearsFixed],
            ['customer counts refreshed', $countsFixed],
            ['payment $ before (universe)', number_format($before, 2)],
            ['payment $ after  (universe)', number_format($after, 2)],
            ['payment $ delta (MUST be 0.00)', number_format($after - $before, 2)],
        ]);

        if (! $dry && abs($after - $before) > 0.01) {
            $this->error('MONEY MISMATCH — payment total changed. Review before trusting this run.');

            return self::FAILURE;
        }

        $this->info($dry ? '[dry-run] no writes.' : 'Collapse applied.');

        return self::SUCCESS;
    }

    /**
     * One-time (idempotent) cleanup: rebate/installment rows orphaned onto a
     * soft-deleted policy by the collapse. These tables have no deleted_at, so
     * the collapse left them dangling. No money is lost — the real payment
     * amounts are all on the surviving keeper's moved payment rows.
     */
    private function cleanupOrphans(int $tenant, bool $dry): int
    {
        $tables = ['policy_rebates', 'policy_installments'];
        $rows = [];
        $total = 0;
        foreach ($tables as $t) {
            if (DB::getSchemaBuilder()->hasColumn($t, 'deleted_at')) {
                // soft-deletable: would have been handled in the main pass; skip.
                $rows[] = [$t, 'has deleted_at — skipped'];

                continue;
            }
            $q = DB::table($t)
                ->join('policies as p', 'p.id', '=', $t.'.policy_id')
                ->where('p.tenant_id', $tenant)
                ->whereNotNull('p.deleted_at');
            $n = (clone $q)->count();
            $total += $n;
            $rows[] = [$t, ($dry ? 'would delete ' : 'deleted ').$n];
            if (! $dry && $n > 0) {
                // delete() on a join builder is unsafe across drivers; use whereIn on ids.
                $ids = (clone $q)->pluck($t.'.id');
                DB::table($t)->whereIn('id', $ids)->delete();
            }
        }
        $this->table(['table', 'result'], $rows);
        $this->info($dry ? "[dry-run] orphan rows that would be deleted: $total" : "Orphan child rows deleted: $total");

        return self::SUCCESS;
    }

    /** Premium mode from the payment count per year (mirrors the importer's
     *  premiumModeFromTerm): 12→monthly, 4→quarterly, 2→semiannual, other>1→monthly. */
    private function premiumModeFromCount(int $n): string
    {
        return match ($n) {
            12 => 'monthly',
            4 => 'quarterly',
            2 => 'semiannual',
            0, 1 => 'annual',
            default => $n > 1 ? 'monthly' : 'annual',
        };
    }

    /** @return array<int,array{group_id:string,client_code:string,policy_no:string,keeper_app:string,member_apps:array<int,string>,action:string}> */
    private function readManifest(string $path): array
    {
        $out = [];
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $idx = array_flip($header);
        while (($r = fgetcsv($fh)) !== false) {
            $members = array_values(array_filter(explode(';', $r[$idx['member_apps']] ?? '')));
            $out[] = [
                'group_id' => (string) $r[$idx['group_id']],
                'client_code' => (string) $r[$idx['client_code']],
                'policy_no' => (string) $r[$idx['policy_no']],
                'keeper_app' => (string) $r[$idx['keeper_app']],
                'member_apps' => $members,
                'action' => (string) $r[$idx['action']],
            ];
        }
        fclose($fh);

        return $out;
    }

    /** Live (not soft-deleted) policy by legacy application_no within the tenant. */
    private function livePolicy(string $appNo, int $tenant): ?object
    {
        if ($appNo === '') {
            return null;
        }

        return DB::table('policies')->where('tenant_id', $tenant)
            ->where('application_no', $appNo)->whereNull('deleted_at')->first(['id']);
    }

    /** Sum of all payments across every policy referenced by the manifest (keepers + members),
     *  following the application_no linkage — invariant across the collapse (payments move, not vanish). */
    private function paymentUniverse(array $manifest, int $tenant): float
    {
        $apps = [];
        foreach ($manifest as $g) {
            if ($g['keeper_app'] !== '') {
                $apps[] = $g['keeper_app'];
            }
            foreach ($g['member_apps'] as $m) {
                $apps[] = $m;
            }
        }
        $apps = array_values(array_unique($apps));
        if ($apps === []) {
            return 0.0;
        }

        // payments attached to any policy (live OR soft-deleted) whose application_no is in the set
        return (float) DB::table('policy_payments as pp')
            ->join('policies as p', 'p.id', '=', 'pp.policy_id')
            ->where('p.tenant_id', $tenant)
            ->whereIn('p.application_no', $apps)
            ->whereNull('pp.voided_at')
            ->sum('pp.amount');
    }

    /** @return int number of policies whose product note was corrected */
    private function applyProductFixes(string $path, int $tenant, bool $dry, Carbon $now): int
    {
        $n = 0;
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $idx = array_flip($header);
        $hasProductNote = DB::getSchemaBuilder()->hasColumn('policies', 'product_note');
        while (($r = fgetcsv($fh)) !== false) {
            $app = (string) $r[$idx['application_code']];
            $corrected = (string) $r[$idx['corrected_product']];
            $p = $this->livePolicy($app, $tenant);
            if ($p === null) {
                continue;
            }
            $n++;
            if (! $dry) {
                // Record the correction as an audit event; if a product_note column exists, set it.
                $upd = ['updated_at' => $now];
                if ($hasProductNote) {
                    $upd['product_note'] = $corrected;
                }
                DB::table('policies')->where('id', $p->id)->update($upd);
                PolicyEvent::create([
                    'policy_id' => $p->id,
                    'type' => 'productCorrection',
                    'occurred_at' => $now,
                    'by_user_id' => null,
                    'payload' => ['corrected' => $corrected, 'source' => 'legacy-collapse', 'application' => $app],
                ]);
            }
        }
        fclose($fh);

        return $n;
    }
}
