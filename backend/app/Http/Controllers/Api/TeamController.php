<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * สายงาน teams — list (for pickers + hierarchy grouping) and basic CRUD.
 */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);

        $teams = Team::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->get()
            ->map(fn (Team $t): array => [
                'id' => (string) $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'parentTeamId' => $t->parent_team_id !== null ? (string) $t->parent_team_id : null,
                'leaderAgentId' => $t->leader_agent_id !== null ? (string) $t->leader_agent_id : null,
                'active' => (bool) $t->active,
                'memberCount' => $t->agents()->count(),
            ]);

        return response()->json(['data' => $teams]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'parentTeamId' => ['sometimes', 'nullable', 'integer'],
            'leaderAgentId' => ['sometimes', 'nullable', 'integer'],
        ]);

        $team = Team::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'] ?? $data['code'],
            'parent_team_id' => $data['parentTeamId'] ?? null,
            'leader_agent_id' => $data['leaderAgentId'] ?? null,
            'active' => true,
        ]);

        return response()->json(['data' => ['id' => (string) $team->id]], 201);
    }
}
