<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\Rank;
use App\Models\RankPromotion;
use App\Support\AgentLicense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies the user decision on a pending rank promotion.
 *
 * Promotions are proposed by RankPromotionService (status='pending', agent's
 * rank untouched). A user must approve before the promotion takes effect:
 *   approve() → sets agents.rank_id + agents.level, stamps the row approved.
 *   reject()  → stamps the row rejected, agent unchanged.
 */
class RankPromotionApproval
{
    public function __construct(private readonly VolumeAccumulator $accumulator)
    {
    }

    public function approve(RankPromotion $promotion, int $userId): RankPromotion
    {
        if ($promotion->status !== 'pending') {
            throw new RankPromotionException('not_pending', 'คำขอนี้ไม่ได้อยู่ในสถานะรออนุมัติ');
        }

        $changed = false;
        $result = DB::transaction(function () use ($promotion, $userId, &$changed): RankPromotion {
            $agent = Agent::query()->lockForUpdate()->find($promotion->agent_id);
            if ($agent === null) {
                throw new RankPromotionException('agent_missing', 'ไม่พบตัวแทน');
            }

            $toRank = Rank::query()->find($promotion->to_rank_id);
            if ($toRank === null) {
                throw new RankPromotionException('rank_missing', 'ไม่พบระดับเป้าหมาย');
            }

            if ($toRank->license_required && ! AgentLicense::isValid($agent)) {
                throw new RankPromotionException('license_required', "ระดับ {$toRank->level} ต้องมีใบอนุญาตชีวิตหรือวินาศที่ยังไม่หมดอายุ");
            }

            $currentLevel = $agent->rank_id !== null
                ? (int) (Rank::query()->where('id', $agent->rank_id)->value('level') ?? 0)
                : 0;

            $applies = $promotion->trigger === 'manual'
                ? (int) $toRank->level !== $currentLevel
                : (int) $toRank->level > $currentLevel;

            if ($applies) {
                $agent->rank_id = $toRank->id;
                $agent->level = 'l'.$toRank->level;
                $agent->save();
                $changed = true;
            }

            $now = Carbon::now();
            $promotion->update([
                'status' => 'approved',
                'decided_by_user_id' => $userId,
                'decided_at' => $now,
                'promoted_at' => $now,
            ]);

            $newLevel = $applies ? (int) $toRank->level : $currentLevel;
            RankPromotion::query()
                ->where('rank_promotions.agent_id', $agent->id)
                ->where('rank_promotions.status', 'pending')
                ->where('rank_promotions.id', '!=', $promotion->id)
                ->whereIn('rank_promotions.to_rank_id', Rank::query()->where('level', '<=', $newLevel)->select('id'))
                ->update(['status' => 'superseded', 'decided_by_user_id' => $userId, 'decided_at' => $now]);

            Log::info('MGM rank promotion APPROVED', [
                'promotion_id' => $promotion->id,
                'agent_id' => $agent->id,
                'to_rank_id' => $toRank->id,
                'decided_by' => $userId,
            ]);

            return $promotion->refresh();
        });

        if ($changed) {
            $agent = Agent::query()->find($promotion->agent_id);
            if ($agent !== null) {
                $this->accumulator->recomputeCurrentMonth((int) $agent->tenant_id, (int) $agent->id);
            }
        }

        return $result;
    }

    public function reject(RankPromotion $promotion, int $userId, string $note): RankPromotion
    {
        if ($promotion->status !== 'pending') {
            throw new RankPromotionException('not_pending', 'คำขอนี้ไม่ได้อยู่ในสถานะรออนุมัติ');
        }

        $promotion->update([
            'status' => 'rejected',
            'decided_by_user_id' => $userId,
            'decided_at' => Carbon::now(),
            'notes' => $note,
        ]);

        Log::info('MGM rank promotion REJECTED', [
            'promotion_id' => $promotion->id,
            'agent_id' => $promotion->agent_id,
            'decided_by' => $userId,
        ]);

        return $promotion->refresh();
    }
}
