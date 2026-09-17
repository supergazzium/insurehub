<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\Rank;
use App\Models\RankPromotion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Applies the user decision on a pending rank promotion.
 *
 * Promotions are proposed by RankPromotionService (status='pending', agent's
 * rank untouched). A user must approve before the promotion takes effect:
 *   approve() → sets agents.rank_id + agents.level, stamps the row approved.
 *   reject()  → stamps the row rejected, agent unchanged.
 *
 * Non-demotion is re-checked at approval time in case the agent was promoted
 * by another path between proposal and approval.
 */
class RankPromotionApproval
{
    public function approve(RankPromotion $promotion, int $userId): RankPromotion
    {
        if ($promotion->status !== 'pending') {
            throw new RuntimeException('Only a pending promotion can be approved.');
        }

        return DB::transaction(function () use ($promotion, $userId): RankPromotion {
            $agent = Agent::query()->lockForUpdate()->find($promotion->agent_id);
            if ($agent === null) {
                throw new RuntimeException('Agent no longer exists.');
            }

            $toRank = Rank::query()->find($promotion->to_rank_id);
            if ($toRank === null) {
                throw new RuntimeException('Target rank no longer exists.');
            }

            // Non-demotion guard: skip the rank write if the agent is already
            // at or above the target level, but still resolve the row so it
            // leaves the queue.
            $currentLevel = $agent->rank_id !== null
                ? (int) (Rank::query()->where('id', $agent->rank_id)->value('level') ?? 0)
                : 0;

            if ((int) $toRank->level > $currentLevel) {
                $agent->rank_id = $toRank->id;
                $agent->level = 'l'.$toRank->level;   // keep the legacy string column in sync
                $agent->save();
            }

            $promotion->update([
                'status' => 'approved',
                'decided_by_user_id' => $userId,
                'decided_at' => Carbon::now(),
                'promoted_at' => Carbon::now(),
            ]);

            Log::info('MGM rank promotion APPROVED', [
                'promotion_id' => $promotion->id,
                'agent_id' => $agent->id,
                'to_rank_id' => $toRank->id,
                'decided_by' => $userId,
            ]);

            return $promotion->refresh();
        });
    }

    public function reject(RankPromotion $promotion, int $userId, ?string $note = null): RankPromotion
    {
        if ($promotion->status !== 'pending') {
            throw new RuntimeException('Only a pending promotion can be rejected.');
        }

        $promotion->update([
            'status' => 'rejected',
            'decided_by_user_id' => $userId,
            'decided_at' => Carbon::now(),
            'notes' => $note !== null && $note !== '' ? $note : $promotion->notes,
        ]);

        Log::info('MGM rank promotion REJECTED', [
            'promotion_id' => $promotion->id,
            'agent_id' => $promotion->agent_id,
            'decided_by' => $userId,
        ]);

        return $promotion->refresh();
    }
}
