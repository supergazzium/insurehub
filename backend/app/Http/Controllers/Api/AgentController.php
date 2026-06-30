<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\AgentRequest;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(Agent::query(), $request);

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('agent_code', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }
        if ($request->boolean('activeOnly')) {
            $q->where('active', true);
        }
        if ($parent = $request->input('parentAgentId')) {
            $q->where('parent_agent_id', $parent);
        }

        return AgentResource::collection($q->orderBy('agent_code')->paginate($this->perPage($request)));
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
