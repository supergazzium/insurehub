<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Rank;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit an agent's สายงาน (team + upline) and level from the agent detail page.
 * Kept separate from the general AgentController@update so the hierarchy
 * concerns — cycle prevention, rank/level sync — live in one place.
 */
class AgentHierarchyController extends Controller
{
    /**
     * PATCH /agents/{agent}/hierarchy
     * Body (all optional): teamId, parentAgentId, level (l1..l10).
     */
    public function update(Request $request, Agent $agent): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        abort_unless((int) $agent->tenant_id === $tenantId, 404);

        $data = $request->validate([
            'teamId' => ['sometimes', 'nullable', 'integer'],
            'parentAgentId' => ['sometimes', 'nullable', 'integer'],
            'level' => ['sometimes', 'nullable', 'string', 'regex:/^l([1-9]|10)$/'],
        ]);

        $changes = [];

        // ── Team ────────────────────────────────────────────────────────────
        if (array_key_exists('teamId', $data)) {
            if ($data['teamId'] === null) {
                $changes['team_id'] = null;
            } else {
                $team = Team::query()->where('tenant_id', $tenantId)->find($data['teamId']);
                if ($team === null) {
                    throw ValidationException::withMessages(['teamId' => 'ไม่พบสายงานนี้']);
                }
                $changes['team_id'] = $team->id;
            }
        }

        // ── Upline (parent agent) with cycle prevention ─────────────────────
        if (array_key_exists('parentAgentId', $data)) {
            if ($data['parentAgentId'] === null) {
                $changes['parent_agent_id'] = null;
            } else {
                $parentId = (int) $data['parentAgentId'];
                if ($parentId === (int) $agent->id) {
                    throw ValidationException::withMessages(['parentAgentId' => 'ตั้งตัวเองเป็นต้นสายไม่ได้']);
                }
                $parent = Agent::query()->where('tenant_id', $tenantId)->find($parentId);
                if ($parent === null) {
                    throw ValidationException::withMessages(['parentAgentId' => 'ไม่พบตัวแทนต้นสาย']);
                }
                if ($this->isDescendant($agent->id, $parentId, $tenantId)) {
                    throw ValidationException::withMessages([
                        'parentAgentId' => 'ตั้งลูกสายเป็นต้นสายไม่ได้ (จะทำให้เกิดวงวน)',
                    ]);
                }
                $changes['parent_agent_id'] = $parentId;
            }
        }

        // ── Level (manual override) — keeps rank_id in sync ─────────────────
        if (array_key_exists('level', $data)) {
            if ($data['level'] === null) {
                $changes['level'] = null;
                $changes['rank_id'] = null;
            } else {
                $levelNum = (int) substr($data['level'], 1);
                $rank = Rank::query()->where('level', $levelNum)->first();
                if ($rank === null) {
                    throw ValidationException::withMessages(['level' => 'ไม่พบระดับนี้']);
                }
                $changes['level'] = $data['level'];
                $changes['rank_id'] = $rank->id;
            }
        }

        if ($changes !== []) {
            $agent->forceFill($changes)->save();
        }

        return response()->json([
            'data' => [
                'id' => (string) $agent->id,
                'teamId' => $agent->team_id !== null ? (string) $agent->team_id : null,
                'parentAgentId' => $agent->parent_agent_id !== null ? (string) $agent->parent_agent_id : null,
                'level' => $agent->level,
                'rankId' => $agent->rank_id !== null ? (string) $agent->rank_id : null,
            ],
        ]);
    }

    /**
     * GET /agents/{agent}/relations — the agent's upline chain (self → top) and
     * direct downline, for the detail page's สายงาน panel.
     */
    public function relations(Request $request, Agent $agent): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        abort_unless((int) $agent->tenant_id === $tenantId, 404);

        $fields = ['id', 'agent_code', 'first_name', 'last_name', 'level', 'active', 'parent_agent_id'];

        // Upline chain: walk parent_agent_id up to the top (cycle-guarded).
        $upline = [];
        $seen = [];
        $cur = $agent->parent_agent_id;
        while ($cur !== null && ! isset($seen[$cur])) {
            $seen[$cur] = true;
            $up = Agent::query()->where('tenant_id', $tenantId)->whereKey($cur)->first($fields);
            if ($up === null) {
                break;
            }
            $upline[] = $this->relRow($up);
            $cur = $up->parent_agent_id;
        }

        $downline = Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('parent_agent_id', $agent->id)
            ->orderBy('agent_code')
            ->get($fields)
            ->map(fn (Agent $a): array => $this->relRow($a));

        return response()->json([
            'self' => $this->relRow($agent),
            'upline' => $upline,
            'downline' => $downline,
        ]);
    }

    /** @return array<string, mixed> */
    private function relRow(Agent $a): array
    {
        return [
            'id' => (string) $a->id,
            'agentCode' => $a->agent_code,
            'name' => trim(($a->first_name ?? '') . ' ' . ($a->last_name ?? '')),
            'level' => $a->level,
            'active' => (bool) $a->active,
        ];
    }

    /**
     * GET /agents/hierarchy-rollup
     * Per-agent สายงาน rollup for the tree view: team code, own written
     * premium + policy count, and the subtree (self + all downline) premium +
     * count. Uses a recursive CTE so the aggregate is one query, not N walks.
     *
     * Returns a flat map keyed by agent id so the frontend tree can annotate
     * each node without extra round-trips.
     */
    public function rollup(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);

        // Own written premium + policy count per agent (active policies only).
        $own = DB::table('policies')
            ->select('writing_agent_id', DB::raw('COUNT(*) as pc'), DB::raw('COALESCE(SUM(annual_premium),0) as prem'))
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereNotNull('writing_agent_id')
            ->groupBy('writing_agent_id')
            ->get()
            ->keyBy('writing_agent_id');

        // Every agent + its team code.
        $agents = DB::table('agents as a')
            ->leftJoin('teams as t', 't.id', '=', 'a.team_id')
            ->where('a.tenant_id', $tenantId)
            ->whereNull('a.deleted_at')
            ->select('a.id', 'a.parent_agent_id', 't.code as team_code')
            ->get();

        // Build parent → children adjacency, then post-order sum the subtree.
        $childrenOf = [];
        foreach ($agents as $ag) {
            $childrenOf[$ag->parent_agent_id ?? 0][] = (int) $ag->id;
        }
        $ownPrem = [];
        $ownCount = [];
        foreach ($agents as $ag) {
            $row = $own->get($ag->id);
            $ownPrem[(int) $ag->id] = $row ? (float) $row->prem : 0.0;
            $ownCount[(int) $ag->id] = $row ? (int) $row->pc : 0;
        }

        // Iterative post-order subtree aggregation (tree is shallow but this is
        // cycle-safe and depth-independent).
        $subPrem = [];
        $subCount = [];
        $memoPrem = function (int $id) use (&$memoPrem, &$subPrem, &$subCount, $childrenOf, $ownPrem, $ownCount, &$visiting): void {
            if (isset($subPrem[$id]) || ($visiting[$id] ?? false)) {
                return;
            }
            $visiting[$id] = true;
            $p = $ownPrem[$id] ?? 0.0;
            $c = $ownCount[$id] ?? 0;
            foreach ($childrenOf[$id] ?? [] as $childId) {
                $memoPrem($childId);
                $p += $subPrem[$childId] ?? 0.0;
                $c += $subCount[$childId] ?? 0;
            }
            $subPrem[$id] = $p;
            $subCount[$id] = $c;
            $visiting[$id] = false;
        };
        $visiting = [];
        foreach ($agents as $ag) {
            $memoPrem((int) $ag->id);
        }

        $out = [];
        foreach ($agents as $ag) {
            $id = (int) $ag->id;
            $out[(string) $id] = [
                'teamCode' => $ag->team_code,
                'ownPremium' => $ownPrem[$id] ?? 0.0,
                'ownPolicyCount' => $ownCount[$id] ?? 0,
                'subtreePremium' => $subPrem[$id] ?? 0.0,
                'subtreePolicyCount' => $subCount[$id] ?? 0,
            ];
        }

        return response()->json(['data' => $out]);
    }

    /**
     * Is $candidateId somewhere in the downline of $rootId? Used to reject
     * setting an agent's upline to one of its own descendants (which would
     * create a cycle). Bounded walk so a pre-existing cycle can't loop forever.
     */
    private function isDescendant(int $rootId, int $candidateId, int $tenantId): bool
    {
        $frontier = [$rootId];
        $seen = [$rootId => true];
        $guard = 0;
        while ($frontier !== [] && $guard < 5000) {
            $children = DB::table('agents')
                ->where('tenant_id', $tenantId)
                ->whereIn('parent_agent_id', $frontier)
                ->pluck('id')
                ->all();
            $next = [];
            foreach ($children as $childId) {
                $childId = (int) $childId;
                if ($childId === $candidateId) {
                    return true;
                }
                if (! isset($seen[$childId])) {
                    $seen[$childId] = true;
                    $next[] = $childId;
                }
                $guard++;
            }
            $frontier = $next;
        }

        return false;
    }
}
