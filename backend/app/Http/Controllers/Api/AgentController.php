<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\AgentRequest;
use App\Http\Resources\AgentListResource;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AgentController extends ApiController
{
    /**
     * Paginated agent list — server-side search + filters + joined parent
     * agent info. Returns the lean AgentListResource; heavy detail lives on
     * AgentResource for show/update.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->tenantId($request);

        $q = DB::table('agents as a')
            ->leftJoin('agents as parent', 'parent.id', '=', 'a.parent_agent_id')
            ->where('a.tenant_id', $tenantId)
            ->whereNull('a.deleted_at')
            ->select([
                'a.id', 'a.agent_code', 'a.agent_type',
                'a.first_name', 'a.last_name', 'a.nickname',
                'a.email', 'a.phone', 'a.level',
                'a.team', 'a.team_no', 'a.head_status',
                'a.license_life_no', 'a.license_life_expiry',
                'a.license_non_life_no', 'a.license_non_life_expiry',
                'a.parent_agent_id', 'a.joined_at', 'a.active',
                'parent.agent_code as parent_agent_code',
                'parent.first_name as parent_agent_first_name',
                'parent.last_name as parent_agent_last_name',
            ]);

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('a.agent_code', 'like', $like)
                    ->orWhere('a.first_name', 'like', $like)
                    ->orWhere('a.last_name', 'like', $like)
                    ->orWhere('a.email', 'like', $like)
                    ->orWhere('a.phone', 'like', $like);
            });
        }
        if ($request->boolean('activeOnly')) {
            $q->where('a.active', true);
        }
        if ($parent = $request->input('parentAgentId')) {
            $q->where('a.parent_agent_id', $parent);
        }
        if ($level = $request->input('level')) {
            $q->where('a.level', $level);
        }
        if ($atype = $request->input('agentType')) {
            $q->where('a.agent_type', $atype);
        }
        if ($licenseStatus = $request->input('licenseStatus')) {
            $today = now()->toDateString();
            match ($licenseStatus) {
                'valid' => $q->where(function ($w) use ($today): void {
                    $w->where('a.license_life_expiry', '>=', $today)
                        ->orWhere('a.license_non_life_expiry', '>=', $today);
                }),
                'expired' => $q->where(function ($w) use ($today): void {
                    $w->whereNotNull('a.license_life_expiry')
                        ->where('a.license_life_expiry', '<', $today)
                        ->orWhere(function ($x) use ($today): void {
                            $x->whereNotNull('a.license_non_life_expiry')
                                ->where('a.license_non_life_expiry', '<', $today);
                        });
                }),
                'expiring60d' => $q->where(function ($w) use ($today): void {
                    $sixty = now()->addDays(60)->toDateString();
                    $w->whereBetween('a.license_life_expiry', [$today, $sixty])
                        ->orWhereBetween('a.license_non_life_expiry', [$today, $sixty]);
                }),
                default => null,
            };
        }

        // Order by id desc so newly-created rows land on page 1.
        $paginator = $q->orderBy('a.id', 'desc')->paginate($this->perPage($request));

        return AgentListResource::collection($paginator);
    }

    public function store(AgentRequest $request): JsonResponse
    {
        $payload = $request->toModel() + ['tenant_id' => $this->tenantId($request)];
        $agent = Agent::create($payload);
        return (new AgentResource($agent))->response()->setStatusCode(201);
    }

    public function show(Request $request, Agent $agent): AgentResource
    {
        $this->authorizeTenant($request, $agent);
        return new AgentResource($agent);
    }

    public function update(AgentRequest $request, Agent $agent): AgentResource
    {
        $this->authorizeTenant($request, $agent);
        $agent->update($request->toModel());
        return new AgentResource($agent->fresh());
    }

    public function destroy(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeTenant($request, $agent);
        $agent->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeTenant(Request $request, Agent $agent): void
    {
        if ((int) $agent->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
