<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill policies.annual_premium to the TRUE per-period gross premium.
 *
 * Fixes two migration defects, both of which left annual_premium understated so
 * that SUM(policy_payments.amount) read as a huge "ชำระเกินยอด / overpay" on the
 * policy edit page:
 *
 *  BUG B — annual_premium = Main_Premium only (ignored riders)
 *  --------------------------------------------------------------
 *  InsureHubImporter set `annual_premium => (float) $row->main_premium`. For a
 *  life policy with riders, the source's real gross premium is
 *      Main_Premium + Σ Rider1..5_Premium   (== Total_Premium_Paid per period)
 *  but only Main_Premium (the main-coverage component, often < 2% of the gross)
 *  landed in annual_premium. The rider premiums were imported into policy_riders,
 *  so the correct gross is reconstructable in-DB as
 *      annual_premium = main_premium + SUM(policy_riders.premium)
 *  Verified against the source xlsx: this equals the source Total_Premium_Paid
 *  on every sampled row. ~267 policies.
 *
 *  BUG A — group policies collapsed as installment streams
 *  --------------------------------------------------------------
 *  The 25/10/2026 Access export stored each GROUP MEMBER as its own Application
 *  row sharing one Policy_Number. CollapseInstallmentPolicies re-parented every
 *  member's payment onto one keeper but — per its "keeper already carries the
 *  premium, no recompute" assumption — left the keeper's annual_premium at ONE
 *  member's premium while it now holds ALL members' payments. For a group policy
 *  (all members Policy_Year 1, each a distinct insured) the true annual premium
 *  is the SUM of every member's gross, which equals SUM of the moved payments
 *  for the policy period. 9 keeper policies (manifest groups 108,148,256,430,
 *  473,474,475,476,477).
 *
 * Correct annual_premium by policy class:
 *   - group keeper (Bug A): SUM(policy_payments.amount) for the keeper
 *     (== full group per-year premium == Σ member gross)
 *   - individual with riders (Bug B): main_premium + Σ policy_riders.premium
 *   - individual, riders not imported but single payment: total_premium_paid
 *     (the source's own per-period gross)
 *   - everything else: unchanged (already correct)
 *
 * SAFETY
 *   - Only writes policies.annual_premium. Never touches payments, riders,
 *     net_premium, vat, duty_stamp, commissions, or any money ledger.
 *   - Raw DB::table()->update() → no model observer fires (no MGM re-accrual).
 *   - Idempotent: recomputes the target each run; re-running is a no-op once
 *     values are correct.
 *   - --dry-run reports every change with before/after and writes nothing.
 *
 *   php artisan policies:backfill-premium-gross [--tenant=1] [--dry-run] [--force]
 */
class BackfillPremiumGross extends Command
{
    protected $signature = 'policies:backfill-premium-gross
        {--tenant=1 : tenant id}
        {--dry-run : report every change, write nothing}
        {--force : skip the write confirmation}';

    protected $description = 'Backfill annual_premium to the true per-period gross (fixes Bug A group-collapse + Bug B rider-drop overpays).';

    /**
     * The 9 group-collapse keepers (Bug A), identified by policy_no.
     * For these, annual_premium = SUM of the keeper's own payments.
     * These are the manifest groups whose members are distinct insureds under
     * one Policy_Year-1 group contract (NOT a single insured's installments).
     */
    private const GROUP_KEEPER_POLICY_NOS = [
        'GM 62', 'GM 63', 'GM 64', 'GM 65',
        '23272215', '21523527', '15145364', '511-0040608', '20967270',
    ];

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        if (! $dry && ! (bool) $this->option('force')) {
            if (! $this->confirm('This will UPDATE policies.annual_premium for Bug A + Bug B policies. Continue?')) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }
        }

        // Rider-premium sums per policy (Bug B reconstruction).
        // policy_riders has no soft-delete column — a soft-deleted policy's rider
        // rows are hard-removed by the collapse step, so a live policy's riders
        // are exactly the rows present.
        $riderSums = DB::table('policy_riders')
            ->select('policy_id', DB::raw('SUM(premium) AS rsum'))
            ->groupBy('policy_id')
            ->pluck('rsum', 'policy_id');

        // Payment sums per policy (Bug A group premium = Σ moved payments).
        $paySums = DB::table('policy_payments')
            ->whereNull('voided_at')
            ->select('policy_id', DB::raw('SUM(amount) AS psum'))
            ->groupBy('policy_id')
            ->pluck('psum', 'policy_id');

        $changes = [];   // [id => [policy_no, class, old, new]]

        // ---- Bug A: 9 group keepers ----
        $groupKeepers = DB::table('policies')
            ->where('tenant_id', $tenant)
            ->whereNull('deleted_at')
            ->whereIn('policy_no', self::GROUP_KEEPER_POLICY_NOS)
            ->get(['id', 'policy_no', 'annual_premium']);

        $groupKeeperIds = [];
        foreach ($groupKeepers as $p) {
            $groupKeeperIds[(int) $p->id] = true;
            $new = round((float) ($paySums[$p->id] ?? 0), 2);
            if ($new <= 0) {
                // No live payments (fully refunded?) — leave as-is, flag it.
                $this->warn("  group keeper {$p->policy_no} (id {$p->id}) has no live payments — skipped.");

                continue;
            }
            if (abs($new - (float) $p->annual_premium) > 0.01) {
                $changes[(int) $p->id] = [$p->policy_no, 'group-keeper', (float) $p->annual_premium, $new];
            }
        }

        // ---- Bug B: individual policies where annual_premium (= main_premium)
        //      understates the gross because riders were dropped. ----
        //
        // Candidate filter mirrors the audit: paid materially exceeds the stored
        // annual, AND the source itself shows a larger figure (net_premium or
        // total_premium_paid > annual). Exclude the group keepers (handled above).
        // NOTE: exclude the group keepers by ID, not by policy_no — many Bug B
        // policies have a NULL policy_no, and `policy_no NOT IN (...)` evaluates
        // to NULL (i.e. excludes the row) for those, silently skipping them.
        $candidates = DB::table('policies as p')
            ->where('p.tenant_id', $tenant)
            ->whereNull('p.deleted_at')
            ->where('p.annual_premium', '>', 0)
            ->when($groupKeeperIds !== [], fn ($q) => $q->whereNotIn('p.id', array_keys($groupKeeperIds)))
            ->where(function ($q) {
                $q->whereColumn('p.net_premium', '>', 'p.annual_premium')
                    ->orWhereRaw('p.total_premium_paid > p.annual_premium * 1.5');
            })
            ->get(['p.id', 'p.policy_no', 'p.annual_premium', 'p.main_premium',
                'p.net_premium', 'p.total_premium_paid']);

        foreach ($candidates as $p) {
            if (isset($groupKeeperIds[(int) $p->id])) {
                continue;
            }
            $paid = round((float) ($paySums[$p->id] ?? 0), 2);
            // Only treat as an overpay-bug row when payments actually exceed the
            // stored annual by >50% (otherwise annual is already sane).
            if ($paid <= (float) $p->annual_premium * 1.5) {
                continue;
            }

            $rsum = (float) ($riderSums[$p->id] ?? 0);
            $recon = round((float) $p->main_premium + $rsum, 2);

            if ($rsum > 0) {
                // Riders present → gross = main + riders (per-period).
                $new = $recon;
            } else {
                // Riders not imported. If this is a single-period policy (one
                // payment), the source's total_premium_paid IS that period's
                // gross — use it. Otherwise fall back to the reconstructed value
                // (== main_premium) and leave the multi-period paid alone.
                $payCount = (int) DB::table('policy_payments')
                    ->where('policy_id', $p->id)->whereNull('voided_at')->count();
                $tpp = round((float) ($p->total_premium_paid ?? 0), 2);
                $new = ($payCount <= 1 && $tpp > 0) ? $tpp : $recon;
            }

            if ($new > 0 && abs($new - (float) $p->annual_premium) > 0.01) {
                $changes[(int) $p->id] = [$p->policy_no, $rsum > 0 ? 'rider-gross' : 'total-paid', (float) $p->annual_premium, $new];
            }
        }

        if ($changes === []) {
            $this->info('No policies need correction — annual_premium already matches the gross.');

            return self::SUCCESS;
        }

        // Report.
        $this->info(sprintf('%d policies to correct:', count($changes)));
        $this->table(
            ['policy_id', 'policy_no', 'class', 'old_annual', 'new_annual'],
            collect($changes)->map(fn ($c, $id) => [
                $id, $c[0], $c[1], number_format($c[2], 2), number_format($c[3], 2),
            ])->values()->all()
        );

        if ($dry) {
            $this->warn('DRY RUN — no rows written.');

            return self::SUCCESS;
        }

        $now = now();
        $written = 0;
        foreach ($changes as $id => $c) {
            DB::table('policies')->where('id', $id)->update([
                'annual_premium' => $c[3],
                'updated_at' => $now,
            ]);
            $written++;
        }

        $this->info("Updated annual_premium on {$written} policies.");

        return self::SUCCESS;
    }
}
