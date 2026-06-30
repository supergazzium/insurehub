<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CustomerAssignmentHistoryResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerAssignmentHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerAssignmentController extends ApiController
{
    /** GET /customers/:id/assignments — history list. */
    public function index(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $this->authorizeTenant($request, $customer);
        return CustomerAssignmentHistoryResource::collection(
            $customer->assignmentHistory()->orderBy('occurred_at', 'desc')->get()
        );
    }

    /** POST /customers/:id/assignments — reassign + append history. */
    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'toAgentId' => ['required', Rule::exists('agents', 'id')->where('tenant_id', $tenantId)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($customer, $data, $request): void {
            $fromAgentId = $customer->assigned_agent_id;
            $customer->update(['assigned_agent_id' => $data['toAgentId']]);
            CustomerAssignmentHistory::create([
                'customer_id' => $customer->id,
                'from_agent_id' => $fromAgentId,
                'to_agent_id' => $data['toAgentId'],
                'reason' => $data['reason'] ?? null,
                'by_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);
        });

        return (new CustomerResource($customer->fresh()))->response();
    }

    private function authorizeTenant(Request $request, Customer $customer): void
    {
        if ((int) $customer->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
