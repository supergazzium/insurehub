<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\MemberVolumeAccumulation;
use App\Models\PolicyPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the member_volume_accumulations table.
 *
 * Two entry points:
 *
 *   accumulateForPayment(payment) — called by PolicyPaymentObserver on
 *     every new payment. Recomputes the writing_agent's month AND every
 *     upline's month for that period. Rank promotion (PR-C) is fired
 *     downstream by the caller — this service only produces the volumes.
 *
 *   reconcileMonth(tenantId, yearMonth) — nightly artisan command
 *     (`mgm:reconcile-volumes`) rebuilds every agent's row for the
 *     given month from first principles. Catches missed observer fires
 *     and gives ops a "if in doubt, run this" button.
 *
 * Excel Sheet2 rule 3: team volume counts only downlines whose CURRENT
 * rank is strictly lower than the agent's own current rank. Same-rank
 * or higher-rank downlines don't contribute to their upline's team
 * volume. This is enforced in computeTeamVolume() at query time.
 */
class VolumeAccumulator
{
    /**
     * Called by PolicyPaymentObserver on `created`. Idempotent — running
     * twice for the same payment produces the same numbers.
     */
    public function accumulateForPayment(PolicyPayment $payment): void
    {
        $policy = $payment->policy()->first();
        if ($policy === null || $policy->writing_agent_id === null) {
            return;
        }
        $sellerId = (int) $policy->writing_agent_id;
        $tenantId = (int) $policy->tenant_id;
        $yearMonth = $this->yearMonth($payment->payment_date);

        // Recompute the seller's row + walk their upline chain and recompute
        // each. Upline team_sales_volume depends on downline personal_sales
        // so a payment to the seller potentially shifts every upline's
        // total for the same month.
        $this->recomputeChain($tenantId, $sellerId, $yearMonth);
    }

    /**
     * Full rebuild for a given month. Used by the nightly reconciliation
     * command and by admins after a data fix. Wipes and rewrites every
     * agent's row for the month.
     */
    public function reconcileMonth(int $tenantId, string $yearMonth): int
    {
        // Every agent that received a payment in this month OR whose current
        // rank makes them a potential team-volume aggregator. Simplest:
        // rebuild for every agent in the tenant. Cost is bounded by
        // agent-count × downline-depth; the tree is shallow (Lv1-Lv10) in
        // practice.
        $agentIds = Agent::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->pluck('id');

        DB::transaction(function () use ($tenantId, $agentIds, $yearMonth): void {
            foreach ($agentIds as $agentId) {
                $this->recomputeForAgent($tenantId, (int) $agentId, $yearMonth);
            }
        });

        return $agentIds->count();
    }

    /**
     * Walk from an agent up through their upline chain, recomputing each
     * agent's row for the same month. Stops on null parent, cycles
     * (defensive), or a chain depth of 20 (safety valve).
     */
    private function recomputeChain(int $tenantId, int $agentId, string $yearMonth): void
    {
        $seen = [];
        $current = $agentId;
        $depth = 0;
        while ($current !== null && $depth < 20) {
            if (isset($seen[$current])) {
                // Cycle detected — surface as a warning in dev logs later.
                break;
            }
            $seen[$current] = true;
            $this->recomputeForAgent($tenantId, $current, $yearMonth);

            $parent = Agent::query()
                ->where('id', $current)
                ->value('parent_agent_id');
            $current = $parent !== null ? (int) $parent : null;
            $depth++;
        }
    }

    /**
     * Write one member_volume_accumulations row for (agent, month).
     * personal + team + rolling_3 are all recomputed from source truth.
     */
    private function recomputeForAgent(int $tenantId, int $agentId, string $yearMonth): void
    {
        $personal = $this->computePersonalVolume($tenantId, $agentId, $yearMonth);
        $team = $this->computeTeamVolume($tenantId, $agentId, $yearMonth);
        $rolling3 = $this->computeRolling3Month($tenantId, $agentId, $yearMonth, $team);

        MemberVolumeAccumulation::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'agent_id' => $agentId,
                'period_year_month' => $yearMonth,
            ],
            [
                'personal_sales_volume' => $personal,
                'team_sales_volume' => $team,
                'rolling_3_month_volume' => $rolling3,
                'recomputed_at' => now(),
            ],
        );
    }

    /**
     * Sum of policy_payments.amount for payments in the given month on
     * policies written by this agent. Skips deleted policies.
     */
    private function computePersonalVolume(int $tenantId, int $agentId, string $yearMonth): float
    {
        [$from, $to] = $this->monthRange($yearMonth);

        return (float) DB::table('policy_payments as pp')
            ->join('policies as p', 'p.id', '=', 'pp.policy_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->where('p.writing_agent_id', $agentId)
            ->whereBetween('pp.payment_date', [$from, $to])
            ->sum('pp.amount');
    }

    /**
     * Personal volume + sum of personal volume of every downline whose
     * CURRENT rank_level is strictly lower than this agent's.
     *
     * Uses a recursive CTE to walk the tree. MySQL 8+ supports this
     * (schema is MySQL 8.4 per compose.yaml). If the runtime is older
     * MySQL, fall back to a limited-depth iterative walk.
     */
    private function computeTeamVolume(int $tenantId, int $agentId, string $yearMonth): float
    {
        $selfRankLevel = $this->rankLevelFor($agentId);
        if ($selfRankLevel === null) {
            // No rank yet — team is just personal.
            return $this->computePersonalVolume($tenantId, $agentId, $yearMonth);
        }

        $downlineIds = $this->downlineIdsAtLowerRank($tenantId, $agentId, $selfRankLevel);
        if ($downlineIds === []) {
            return $this->computePersonalVolume($tenantId, $agentId, $yearMonth);
        }

        [$from, $to] = $this->monthRange($yearMonth);

        $downlinesSum = (float) DB::table('policy_payments as pp')
            ->join('policies as p', 'p.id', '=', 'pp.policy_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereIn('p.writing_agent_id', $downlineIds)
            ->whereBetween('pp.payment_date', [$from, $to])
            ->sum('pp.amount');

        return $this->computePersonalVolume($tenantId, $agentId, $yearMonth) + $downlinesSum;
    }

    /**
     * Rolling 3-month total = team_sales_volume for this month + prior 2.
     * Cheap because it's a 3-row aggregate.
     */
    private function computeRolling3Month(int $tenantId, int $agentId, string $yearMonth, float $thisMonthTeam): float
    {
        $now = CarbonImmutable::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $prior = [
            $now->subMonth()->format('Y-m'),
            $now->subMonths(2)->format('Y-m'),
        ];
        $priorSum = (float) MemberVolumeAccumulation::query()
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->whereIn('period_year_month', $prior)
            ->sum('team_sales_volume');

        return $thisMonthTeam + $priorSum;
    }

    /**
     * Recursively collect downline agent_ids whose current rank_level is
     * strictly lower than $selfRankLevel. Uses MySQL 8+ recursive CTE.
     *
     * @return list<int>
     */
    private function downlineIdsAtLowerRank(int $tenantId, int $agentId, int $selfRankLevel): array
    {
        // Recursive CTE — walks parent_agent_id downward, filtering to the
        // subtree of $agentId. Then joins ranks to gate by level.
        $sql = <<<'SQL'
            WITH RECURSIVE downline AS (
                SELECT id, parent_agent_id, rank_id
                FROM agents
                WHERE parent_agent_id = ? AND tenant_id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT a.id, a.parent_agent_id, a.rank_id
                FROM agents a
                INNER JOIN downline d ON a.parent_agent_id = d.id
                WHERE a.tenant_id = ? AND a.deleted_at IS NULL
            )
            SELECT d.id
            FROM downline d
            LEFT JOIN ranks r ON r.id = d.rank_id
            WHERE COALESCE(r.level, 0) < ?
        SQL;

        $rows = DB::select($sql, [$agentId, $tenantId, $tenantId, $selfRankLevel]);

        return array_map(static fn ($r) => (int) $r->id, $rows);
    }

    private function rankLevelFor(int $agentId): ?int
    {
        $row = DB::table('agents as a')
            ->leftJoin('ranks as r', 'r.id', '=', 'a.rank_id')
            ->where('a.id', $agentId)
            ->value('r.level');

        return $row !== null ? (int) $row : null;
    }

    private function yearMonth(Carbon|string|null $date): string
    {
        if ($date === null) {
            return now()->format('Y-m');
        }
        if ($date instanceof Carbon) {
            return $date->format('Y-m');
        }

        return Carbon::parse($date)->format('Y-m');
    }

    /**
     * @return array{0: string, 1: string} ISO date bounds for the month.
     */
    private function monthRange(string $yearMonth): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $yearMonth)->startOfMonth();

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }
}
