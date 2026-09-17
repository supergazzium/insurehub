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
