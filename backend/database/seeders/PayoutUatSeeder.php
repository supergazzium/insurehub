<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PayoutUatSeeder — give the bulk commission-payout screen something to pay.
 *
 * The payout landing page lists agents with OUTSTANDING commission, computed
 * by CommissionPayoutService::eligibleQuery():
 *   - policy status in {active, issued}, not cancelled, has writing_agent_id
 *   - policy_rebates.agent_rebate_status != 'paid'
 *   - not already in a LIVE payout batch
 *   - and an amount > 0 (resolveAmount): actual/calculated rebate, else
 *     policies.comm_hub_to_agent_amount
 *
 * Most seeded policies are 'active' but have comm_hub_to_agent_amount = 0, so
 * they show ฿0 and are not payable. This seeder fills a REALISTIC commission
 * (a percentage of main_premium) on eligible-but-zero policies for the first
 * N agents, so the bulk payout flow (preview → create batch → generate PDFs →
 * mark paid per agent → reverse/re-issue) can be exercised end to end.
 *
 * It only TOPS UP policies whose commission is currently zero — it never
 * overwrites a policy that already carries a commission.
 *
 * Reversibility / idempotency: each touched policy gets a one-line marker
 * appended to policies.notes that records the prior value, e.g.
 *   [PAYOUT_UAT_SEED prev=0]
 * The marker lives in `notes` (a column present since the first policies
 * migration) rather than policy_events, so this seeder runs on any branch /
 * any freshly-migrated database. Re-running is a no-op (markers already
 * present); rollback restores the prior value and strips the markers.
 *
 * Usage:
 *   php artisan db:seed --class=Database\\Seeders\\PayoutUatSeeder
 *   php artisan db:seed --class=Database\\Seeders\\PayoutUatSeeder   # re-run = no-op
 *
 * Rollback (restore original commissions, remove markers):
 *   SEED_ROLLBACK=1 php artisan db:seed --class=Database\\Seeders\\PayoutUatSeeder
 */
class PayoutUatSeeder extends Seeder
{
    /** How many distinct agents to give outstanding commission to. */
    private const AGENT_LIMIT = 40;

    /** Marker tag written into policies.notes so the effect is reversible. */
    private const MARKER = 'PAYOUT_UAT_SEED';

    /** Realistic agent-commission rates, cycled deterministically per policy. */
    private const RATES = [0.15, 0.18, 0.20, 0.22, 0.17];

    public function run(): void
    {
        if ((string) env('SEED_ROLLBACK', '') !== '') {
            $this->rollback();

            return;
        }

        // Idempotent guard: if we have already seeded (markers exist), do not
        // seed MORE agents. Re-running must be a no-op, not an accumulation —
        // otherwise each run picks a fresh batch of still-zero agents and the
        // outstanding set grows without bound. To re-seed from scratch, run
        // the rollback first (SEED_ROLLBACK=1).
        $existing = DB::table('policies')
            ->whereNull('deleted_at')
            ->where('notes', 'like', '%['.self::MARKER.'%')
            ->count();
        if ($existing > 0) {
            $this->command?->warn(sprintf(
                'PayoutUatSeeder: already seeded (%d marked policies). No-op. Run SEED_ROLLBACK=1 … to reset first.',
                $existing,
            ));

            return;
        }

        // Agents who hold eligible-but-unpaid policies that currently carry no
        // commission. Take the first N (ordered by agent id for determinism).
        $agentIds = $this->eligibleZeroQuery()
            ->distinct()
            ->orderBy('p.writing_agent_id')
            ->limit(self::AGENT_LIMIT)
            ->pluck('p.writing_agent_id')
            ->all();

        if ($agentIds === []) {
            $this->command?->warn('PayoutUatSeeder: no eligible-but-zero policies found — nothing to seed.');

            return;
        }

        $policies = $this->eligibleZeroQuery()
            ->whereIn('p.writing_agent_id', $agentIds)
            ->orderBy('p.id')
            ->get(['p.id', 'p.main_premium', 'p.notes']);

        $now = Carbon::now('Asia/Bangkok');
        $touched = 0;
        $totalComm = 0.0;

        DB::transaction(function () use ($policies, $now, &$touched, &$totalComm): void {
            foreach ($policies as $i => $p) {
                // Deterministic realistic rate per policy; round to satang.
                $rate = self::RATES[$i % count(self::RATES)];
                $commission = round(((float) $p->main_premium) * $rate, 2);
                if ($commission <= 0) {
                    continue;
                }

                $marker = '['.self::MARKER.' prev=0]';
                $notes = trim(((string) ($p->notes ?? '')).' '.$marker);

                DB::table('policies')
                    ->where('id', $p->id)
                    ->update([
                        'comm_hub_to_agent_amount' => $commission,
                        'notes' => $notes,
                        'updated_at' => $now,
                    ]);

                $touched++;
                $totalComm += $commission;
            }
        });

        $this->command?->info(sprintf(
            'PayoutUatSeeder: filled commission on %d policies across %d agents (total ฿%s). Open /commissions/payouts to test.',
            $touched,
            count($agentIds),
            number_format($totalComm, 2),
        ));
    }

    /**
     * Restore the commission values this seeder changed and strip its markers.
     */
    private function rollback(): void
    {
        $marked = DB::table('policies')
            ->whereNull('deleted_at')
            ->where('notes', 'like', '%['.self::MARKER.'%')
            ->get(['id', 'notes']);

        if ($marked->isEmpty()) {
            $this->command?->warn('PayoutUatSeeder rollback: no seeded markers found.');

            return;
        }

        $now = Carbon::now('Asia/Bangkok');
        $restored = 0;

        DB::transaction(function () use ($marked, $now, &$restored): void {
            foreach ($marked as $p) {
                $notes = (string) ($p->notes ?? '');
                // Recover the prior value from the marker, then strip it.
                $prev = 0.0;
                if (preg_match('/\['.self::MARKER.' prev=([0-9.]+)\]/', $notes, $m) === 1) {
                    $prev = (float) $m[1];
                }
                $cleaned = trim((string) preg_replace('/\s*\['.self::MARKER.'[^\]]*\]/', '', $notes));

                DB::table('policies')
                    ->where('id', $p->id)
                    ->update([
                        'comm_hub_to_agent_amount' => $prev,
                        'notes' => $cleaned === '' ? null : $cleaned,
                        'updated_at' => $now,
                    ]);

                $restored++;
            }
        });

        $this->command?->info(sprintf('PayoutUatSeeder rollback: restored %d policies to their original commission.', $restored));
    }

    /**
     * Policies that are payout-eligible but currently carry zero commission.
     * Mirrors CommissionPayoutService::eligibleQuery() for the parts that
     * decide eligibility, plus the "commission is zero" condition.
     */
    private function eligibleZeroQuery()
    {
        return DB::table('policies as p')
            ->leftJoin('policy_rebates as r', 'r.policy_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.writing_agent_id')
            ->whereIn('p.status', ['active', 'issued'])
            ->where('p.main_premium', '>', 0)
            // No rebate-side commission yet.
            ->where(function ($w): void {
                $w->whereNull('r.actual_agent_amount')->orWhere('r.actual_agent_amount', '<=', 0);
            })
            ->where(function ($w): void {
                $w->whereNull('r.calculated_agent_amount')->orWhere('r.calculated_agent_amount', '<=', 0);
            })
            // Policy-side commission is zero/absent — the gap this seeder fills.
            ->where(function ($w): void {
                $w->whereNull('p.comm_hub_to_agent_amount')->orWhere('p.comm_hub_to_agent_amount', '<=', 0);
            })
            // Not already paid on the agent leg.
            ->where(function ($w): void {
                $w->whereNull('r.agent_rebate_status')
                    ->orWhere('r.agent_rebate_status', '')
                    ->orWhere('r.agent_rebate_status', '!=', 'paid');
            });
    }
}
