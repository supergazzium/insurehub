<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RankPromotion;
use App\Services\Commission\RankPromotionApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Promotion approval queue. Promotions are proposed as 'pending' by the
 * engine; a user approves (applies the rank) or rejects them here.
 */
class RankPromotionController extends Controller
{
    public function __construct(private readonly RankPromotionApproval $approval)
    {
    }

    /**
     * GET /rank-promotions?status=pending (default). Newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        $status = (string) $request->input('status', 'pending');

        $q = RankPromotion::query()
            ->with(['agent:id,tenant_id,agent_code,first_name,last_name', 'fromRank:id,level,code,name_th', 'toRank:id,level,code,name_th'])
            ->whereHas('agent', fn ($a) => $a->where('tenant_id', $tenantId))
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        if ($status !== 'all') {
            $q->where('status', $status);
        }

        $rows = $q->limit(200)->get()->map(fn (RankPromotion $p): array => [
            'id' => (string) $p->id,
            'status' => $p->status,
            'trigger' => $p->trigger,
            'agentId' => (string) $p->agent_id,
            'agentCode' => $p->agent?->agent_code,
            'agentName' => trim(($p->agent?->first_name ?? '').' '.($p->agent?->last_name ?? '')),
            'fromLevel' => $p->fromRank?->level,
            'fromRankLabel' => $p->fromRank?->name_th ?? $p->fromRank?->code,
            'toLevel' => $p->toRank?->level,
            'toRankLabel' => $p->toRank?->name_th ?? $p->toRank?->code,
            'qualifyingVolume' => (float) $p->qualifying_rolling_3_month_volume,
            'qualifyingPeriod' => $p->qualifying_period_year_month,
            'requestedAt' => optional($p->requested_at)->toIso8601String(),
            'decidedAt' => optional($p->decided_at)->toIso8601String(),
            'promotedAt' => optional($p->promoted_at)->toIso8601String(),
            'notes' => $p->notes,
        ]);

        $pendingCount = RankPromotion::query()
            ->whereHas('agent', fn ($a) => $a->where('tenant_id', $tenantId))
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'data' => $rows,
            'meta' => ['pendingCount' => $pendingCount],
        ]);
    }

    public function approve(Request $request, RankPromotion $rankPromotion): JsonResponse
    {
        $this->authorizeTenant($request, $rankPromotion);
        try {
            $updated = $this->approval->approve($rankPromotion, (int) $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => (string) $updated->id, 'status' => $updated->status]]);
    }

    public function reject(Request $request, RankPromotion $rankPromotion): JsonResponse
    {
        $this->authorizeTenant($request, $rankPromotion);
        $note = (string) $request->input('note', '');
        try {
            $updated = $this->approval->reject($rankPromotion, (int) $request->user()->id, $note ?: null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => (string) $updated->id, 'status' => $updated->status]]);
    }

    private function authorizeTenant(Request $request, RankPromotion $promotion): void
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        abort_unless((int) ($promotion->agent?->tenant_id) === $tenantId, 404);
    }
}
