<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only, agent-scoped view of the follow-up (งานค้าง) tracker.
 *
 * Reuses the admin FollowUpController's entire query/aggregation logic but:
 *   - restricts every policy query to the signed-in agent's OWN policies
 *     (policies.writing_agent_id = the agent linked to users.agent_id), via the
 *     parent's $agentScopeId hook;
 *   - drops the `verify` category (pending-changes approval queue — an admin
 *     workflow with no per-agent ownership).
 *
 * This controller exposes NO mutating actions. The freelook / cancellation-review
 * / payout-override endpoints live on PolicyFollowUpActionController and are not
 * routed for agents, so the agent portal is strictly view-only.
 */
class AgentFollowUpController extends FollowUpController
{
    protected bool $allowVerify = false;

    public function index(Request $request): JsonResponse
    {
        $agentId = $request->user()->agent_id;
        if ($agentId === null) {
            abort(404, 'This account is not linked to an agent record.');
        }

        // Confirm the agent belongs to this tenant before scoping to it (mirrors
        // MeAgentController::currentAgent — an admin-with-no-agent gets 404 above).
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        $agent = Agent::query()
            ->where('id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();
        if ($agent === null) {
            abort(404, 'Agent record not found.');
        }

        $this->agentScopeId = (int) $agent->id;

        return parent::index($request);
    }
}
