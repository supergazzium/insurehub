<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\MemberVolumeAccumulation;
use App\Models\Rank;
use App\Models\RankPromotion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates agents for rank promotion based on their volume accumulations.
 *
 * Rules (from Excel Sheet2 footnotes):
 *   1. Non-demotion: once promoted, never demoted. If volume drops, rank
 *      stays. This service never DEMOTES.
 *   3. Volume for promotion = self + downlines at strictly LOWER rank
 *      (enforced upstream by VolumeAccumulator).
 *   4. Instant + skip-level: an agent qualifying for Lv7 goes STRAIGHT
 *      to Lv7 from wherever they are, no month-end wait, no one-step-
 *      at-a-time cap.
 *   Lv7+ require agents.has_license = true.
 *
 * Qualification metric: rolling_3_month_volume vs ranks.three_month_accum_target
 * (see design conversation with the operator — the two threshold columns on
 * ranks are equivalent, and the 3-month rolling window is more stable than
 * a single-month spike).
 *
 * Entry points:
 *   evaluateForChain(agentId) — walks from an agent up through uplines,
 *     evaluating each. Called from PolicyPaymentObserver AFTER
 *     VolumeAccumulator has run.
 *   evaluateForAgent(agentId) — evaluates a single agent. Called by
 *     evaluateForChain and by the nightly reconciliation.
 */
class RankPromotionService
{
    private const MAX_CHAIN_DEPTH = 20;

    /**
     * Walk from an agent up through uplines, evaluating each for
     * promotion. Called on payment.created — the seller might qualify,
     * and every upline's team volume just grew so they might qualify too.
     *
     * @return list<RankPromotion> Promotions written during this call
     */
    public function evaluateForChain(int $agentId): array
    {
        $promotions = [];
        $seen = [];
        $current = $agentId;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_CHAIN_DEPTH) {
            if (isset($seen[$current])) {
                break;  // cycle guard
            }
            $seen[$current] = true;

            $promotion = $this->evaluateForAgent($current);
            if ($promotion !== null) {
                $promotions[] = $promotion;
            }

            $parent = Agent::query()->where('id', $current)->value('parent_agent_id');
            $current = $parent !== null ? (int) $parent : null;
            $depth++;
        }

        return $promotions;
    }

    /**
     * Evaluate one agent. Returns the RankPromotion row if a promotion
     * fired, null otherwise (already at max qualifying rank, or nothing
     * qualified).
     *
     * Non-demotion enforced here — we never write a promotion where
     * to_rank.level < from_rank.level.
     */
    public function evaluateForAgent(int $agentId): ?RankPromotion
    {
        $agent = Agent::query()->find($agentId);
        if ($agent === null || $agent->deleted_at !== null) {
            return null;
        }

        // Current-month rolling volume. If there's no accumulator row for
        // this month yet, the agent hasn't been active — nothing to
        // promote against.
        $currentMonth = now()->format('Y-m');
        $volume = MemberVolumeAccumulation::query()
            ->where('tenant_id', $agent->tenant_id)
            ->where('agent_id', $agentId)
            ->where('period_year_month', $currentMonth)
            ->value('rolling_3_month_volume');

        if ($volume === null) {
            return null;
        }
        $volume = (float) $volume;

        $currentLevel = $this->currentLevel($agent);
        $qualifiedRank = $this->highestQualifyingRank($agent, $volume);

        // No promotion needed if agent is already at (or above) the highest
        // rank they qualify for.
        if ($qualifiedRank === null || $qualifiedRank->level <= $currentLevel) {
            return null;
        }

        return $this->promote($agent, $qualifiedRank, $volume, $currentMonth);
    }

    /**
     * The highest rank an agent qualifies for based on their volume and
     * license status. Returns null if not even Lv1's threshold is met
     * (which is 0, so this only happens if there are no ranks seeded).
     */
    private function highestQualifyingRank(Agent $agent, float $volume): ?Rank
    {
        $q = Rank::query()
            ->where('three_month_accum_target', '<=', $volume)
            ->orderByDesc('level');

        // Lv7+ require a license. If the agent doesn't have one, cap at
        // the highest non-licensed rank.
        if (! $agent->has_license) {
            $q->where('license_required', false);
        }

        return $q->first();
    }

    private function currentLevel(Agent $agent): int
    {
        if ($agent->rank_id === null) {
            return 0;  // never promoted — any rank ≥ Lv1 is an upgrade
        }
        $level = Rank::query()->where('id', $agent->rank_id)->value('level');

        return $level !== null ? (int) $level : 0;
    }

    /**
     * Perform the promotion: set agents.rank_id + write the audit row.
     * Wrapped in a transaction so a mid-write crash doesn't leave the
     * audit and current-state out of sync.
     */
    private function promote(Agent $agent, Rank $toRank, float $volume, string $yearMonth): RankPromotion
    {
        return DB::transaction(function () use ($agent, $toRank, $volume, $yearMonth): RankPromotion {
            $fromRankId = $agent->rank_id;
            $agent->rank_id = $toRank->id;
            $agent->save();

            $promotion = RankPromotion::create([
                'agent_id' => $agent->id,
                'from_rank_id' => $fromRankId,
                'to_rank_id' => $toRank->id,
                'qualifying_rolling_3_month_volume' => $volume,
                'qualifying_period_year_month' => $yearMonth,
                'trigger' => 'auto',
                'promoted_at' => Carbon::now(),
            ]);

            Log::info('MGM rank promotion', [
                'agent_id' => $agent->id,
                'from_rank_id' => $fromRankId,
                'to_rank_id' => $toRank->id,
                'to_rank_code' => $toRank->code,
                'volume' => $volume,
            ]);

            return $promotion;
        });
    }
}
