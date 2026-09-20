<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\MemberVolumeAccumulation;
use App\Models\Rank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Level-progress readouts for the agent detail page and a fleet "who is close
 * to promotion" board.
 *
 * Progress metric = the agent's current-month rolling_3_month_volume vs the
 * NEXT rank's three_month_accum_target (the same figure RankPromotionService
 * qualifies on). With no payment history the volume is 0, so bars read 0% until
 * payments start flowing — then they light up automatically.
 */
class LevelProgressController extends Controller
{
    /** GET /agents/{agent}/level-progress */
    public function show(Request $request, Agent $agent): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        abort_unless((int) $agent->tenant_id === $tenantId, 404);

        return response()->json(['data' => $this->progressFor($agent, $tenantId)]);
    }

    /**
     * GET /agents/level-progress-board — every agent's progress toward their
     * next level, sorted by how close they are (highest % first). Powers the
     * "ใกล้เลื่อนระดับ" dashboard.
     */
    public function board(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        $month = now()->format('Y-m');

        $ranks = Rank::query()->orderBy('level')->get();
        $volumes = MemberVolumeAccumulation::query()
            ->where('tenant_id', $tenantId)
            ->where('period_year_month', $month)
            ->pluck('rolling_3_month_volume', 'agent_id');

        $agents = Agent::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('active', true)
            ->get(['id', 'agent_code', 'first_name', 'last_name', 'rank_id', 'has_license']);

        $rows = [];
        foreach ($agents as $a) {
            $vol = (float) ($volumes[$a->id] ?? 0);
            $p = $this->computeProgress($a, $vol, $ranks);
            if ($p['nextLevel'] === null) {
                continue; // already at top — not "climbing"
            }
            $rows[] = [
                'agentId' => (string) $a->id,
                'agentCode' => $a->agent_code,
                'agentName' => trim(($a->first_name ?? '').' '.($a->last_name ?? '')),
                ...$p,
            ];
        }

        usort($rows, fn ($x, $y) => $y['progressPct'] <=> $x['progressPct']);

        return response()->json([
            'data' => array_slice($rows, 0, 100),
            'meta' => ['month' => $month, 'agentCount' => count($rows)],
        ]);
    }

    private function progressFor(Agent $agent, int $tenantId): array
    {
        $month = now()->format('Y-m');
        $vol = (float) (MemberVolumeAccumulation::query()
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agent->id)
            ->where('period_year_month', $month)
            ->value('rolling_3_month_volume') ?? 0);

        $ranks = Rank::query()->orderBy('level')->get();

        return ['month' => $month, ...$this->computeProgress($agent, $vol, $ranks)];
    }

    /**
     * @param  \Illuminate\Support\Collection<int,Rank>  $ranks
     * @return array{currentLevel:int,currentRankLabel:?string,nextLevel:?int,nextRankLabel:?string,currentVolume:float,target:float,progressPct:int,remaining:float,nextRequiresLicense:bool,licenseBlocked:bool}
     */
    private function computeProgress(Agent $agent, float $volume, $ranks): array
    {
        $currentLevel = $agent->rank_id !== null
            ? (int) ($ranks->firstWhere('id', $agent->rank_id)?->level ?? 0)
            : 0;

        $next = $ranks->first(fn (Rank $r) => (int) $r->level === $currentLevel + 1);
        $current = $ranks->firstWhere('level', $currentLevel);

        if ($next === null) {
            return [
                'currentLevel' => $currentLevel,
                'currentRankLabel' => $current?->name_th ?? $current?->code,
                'nextLevel' => null, 'nextRankLabel' => null,
                'currentVolume' => $volume, 'target' => 0.0,
                'progressPct' => 100, 'remaining' => 0.0,
                'nextRequiresLicense' => false, 'licenseBlocked' => false,
            ];
        }

        $target = (float) $next->three_month_accum_target;
        $pct = $target > 0 ? (int) min(100, floor(($volume / $target) * 100)) : 0;
        $licenseBlocked = (bool) $next->license_required && ! (bool) $agent->has_license;

        return [
            'currentLevel' => $currentLevel,
            'currentRankLabel' => $current?->name_th ?? $current?->code,
            'nextLevel' => (int) $next->level,
            'nextRankLabel' => $next->name_th ?? $next->code,
            'currentVolume' => $volume,
            'target' => $target,
            'progressPct' => $pct,
            'remaining' => max(0.0, $target - $volume),
            'nextRequiresLicense' => (bool) $next->license_required,
            'licenseBlocked' => $licenseBlocked,
        ];
    }
}
