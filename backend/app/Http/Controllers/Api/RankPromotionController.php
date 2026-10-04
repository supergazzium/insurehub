<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Rank;
use App\Models\RankPromotion;
use App\Services\Commission\RankPromotionApproval;
use App\Services\Commission\RankPromotionException;
use App\Services\Commission\RankPromotionService;
use App\Support\AgentLicense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Promotion approval queue. Promotions are proposed as 'pending' by the
 * engine; a user approves (applies the rank) or rejects them here.
 */
class RankPromotionController extends Controller
{
    public function __construct(
        private readonly RankPromotionApproval $approval,
        private readonly RankPromotionService $service,
    ) {
    }

    /**
     * GET /rank-promotions?status=pending (default). Newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $status = (string) $request->input('status', 'pending');

        $q = RankPromotion::query()
            ->with([
                'agent:id,tenant_id,agent_code,first_name,last_name,rank_id,license_life_no,license_life_expiry,license_non_life_no,license_non_life_expiry',
                'agent.rank:id,level,code,name_th',
                'fromRank:id,level,code,name_th',
                'toRank:id,level,code,name_th,license_required',
                'requestedBy:id,name',
                'decidedBy:id,name',
            ])
            ->whereHas('agent', fn ($a) => $a->where('tenant_id', $tenantId))
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($request->filled('agentId')) {
            $q->where('agent_id', (int) $request->input('agentId'));
        }

        $rows = $q->limit(200)->get()->map(fn (RankPromotion $p): array => $this->row($p));

        $pendingCount = RankPromotion::query()
            ->whereHas('agent', fn ($a) => $a->where('tenant_id', $tenantId))
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'data' => $rows,
            'meta' => ['pendingCount' => $pendingCount],
        ]);
    }

    public function requestChange(Request $request, Agent $agent): JsonResponse
    {
        abort_unless((int) $agent->tenant_id === $this->tenantId($request), 404);
        Gate::authorize('agents.update');

        $data = $request->validate([
            'toLevel' => ['required', 'integer', 'between:1,10'],
            'note' => ['required', 'string', 'max:1000'],
        ], [
            'note.required' => 'กรุณาระบุเหตุผลที่ขอปรับระดับ',
        ]);

        $toRank = Rank::query()->where('level', (int) $data['toLevel'])->first();
        abort_if($toRank === null, 422, 'ไม่พบระดับนี้');

        $promotion = $this->service->requestManual($agent, $toRank, (int) $request->user()->id, trim($data['note']));
        $promotion = $this->load($promotion);
        $this->audit($request, 'agent.rank_requested', $promotion);

        return response()->json(['data' => $this->row($promotion)], 201);
    }

    public function approve(Request $request, RankPromotion $rankPromotion): JsonResponse
    {
        $this->authorizeTenant($request, $rankPromotion);
        Gate::authorize('agents.rank_approve');

        try {
            $updated = $this->approval->approve($rankPromotion, (int) $request->user()->id);
        } catch (RankPromotionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }
        $updated = $this->load($updated);
        $this->audit($request, 'agent.rank_approved', $updated);

        return response()->json(['data' => $this->row($updated)]);
    }

    public function reject(Request $request, RankPromotion $rankPromotion): JsonResponse
    {
        $this->authorizeTenant($request, $rankPromotion);
        Gate::authorize('agents.rank_approve');

        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ], [
            'note.required' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ',
        ]);

        try {
            $updated = $this->approval->reject($rankPromotion, (int) $request->user()->id, trim($data['note']));
        } catch (RankPromotionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }
        $updated = $this->load($updated);
        $this->audit($request, 'agent.rank_rejected', $updated);

        return response()->json(['data' => $this->row($updated)]);
    }

    private function load(RankPromotion $p): RankPromotion
    {
        return $p->fresh(['agent', 'agent.rank', 'fromRank', 'toRank', 'requestedBy', 'decidedBy']);
    }

    private function row(RankPromotion $p): array
    {
        $agent = $p->agent;

        return [
            'id' => (string) $p->id,
            'status' => $p->status,
            'trigger' => $p->trigger,
            'agentId' => (string) $p->agent_id,
            'agentCode' => $agent?->agent_code,
            'agentName' => trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')),
            'currentLevel' => $agent?->rank?->level,
            'licenseValid' => $agent !== null && AgentLicense::isValid($agent),
            'fromLevel' => $p->fromRank?->level,
            'fromRankLabel' => $p->fromRank?->name_th ?? $p->fromRank?->code,
            'toLevel' => $p->toRank?->level,
            'toRankLabel' => $p->toRank?->name_th ?? $p->toRank?->code,
            'toLicenseRequired' => (bool) $p->toRank?->license_required,
            'qualifyingVolume' => (float) $p->qualifying_rolling_3_month_volume,
            'qualifyingPeriod' => $p->qualifying_period_year_month,
            'requestedAt' => optional($p->requested_at)->toIso8601String(),
            'requestedByName' => $p->requestedBy?->name,
            'requestNote' => $p->request_note,
            'decidedAt' => optional($p->decided_at)->toIso8601String(),
            'decidedByName' => $p->decidedBy?->name,
            'promotedAt' => optional($p->promoted_at)->toIso8601String(),
            'notes' => $p->notes,
        ];
    }

    private function audit(Request $request, string $action, RankPromotion $p): void
    {
        AuditEntry::create([
            'tenant_id' => $p->agent?->tenant_id ?? $this->tenantId($request),
            'user_id' => $request->user()->id,
            'occurred_at' => now(),
            'actor' => $request->user()->name,
            'action' => $action,
            'target' => 'agent:'.$p->agent_id,
            'ip' => $request->ip(),
            'result' => 'success',
            'metadata' => [
                'promotion_id' => $p->id,
                'trigger' => $p->trigger,
                'from_level' => $p->fromRank?->level,
                'to_level' => $p->toRank?->level,
                'note' => $action === 'agent.rank_rejected' ? $p->notes : $p->request_note,
            ],
        ]);
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
    }

    private function authorizeTenant(Request $request, RankPromotion $promotion): void
    {
        abort_unless((int) ($promotion->agent?->tenant_id) === $this->tenantId($request), 404);
    }
}
