<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(Customer::query(), $request);

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('customer_code', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('id_card', 'like', $like);
            });
        }
        if ($agentId = $request->input('assignedAgentId')) {
            $q->where('assigned_agent_id', $agentId);
        }
        if ($request->boolean('unassigned')) {
            $q->whereNull('assigned_agent_id');
        }

        return CustomerResource::collection($q->orderBy('customer_code')->paginate($this->perPage($request)));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $payload = $request->toModel() + ['tenant_id' => $this->tenantId($request)];
        if (! array_key_exists('registered_at', $payload)) {
            $payload['registered_at'] = now();
        }
        $customer = Customer::create($payload);
        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function show(Request $request, Customer $customer): CustomerResource
    {
        $this->authorizeTenant($request, $customer);
        return new CustomerResource($customer->load(['kycDocs', 'assignmentHistory']));
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->authorizeTenant($request, $customer);
        $customer->update($request->toModel());
        return new CustomerResource($customer->fresh());
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);
        $customer->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * POST /customers/:id/merge — fold a duplicate customer into this one.
     * The duplicate's KYC docs are re-parented; counts are summed; the
     * duplicate is soft-deleted.
     */
    public function merge(Request $request, Customer $customer): CustomerResource
    {
        $this->authorizeTenant($request, $customer);
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'duplicateId' => [
                'required',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
        ]);
        $dup = Customer::where('tenant_id', $tenantId)->findOrFail($data['duplicateId']);
        if ((int) $dup->id === (int) $customer->id) {
            abort(422, 'Cannot merge a customer with itself.');
        }

        DB::transaction(function () use ($customer, $dup): void {
            // Re-parent KYC docs + history to the primary.
            $dup->kycDocs()->update(['customer_id' => $customer->id]);
            $dup->assignmentHistory()->update(['customer_id' => $customer->id]);
            // Sum policy counts.
            $customer->update([
                'active_policy_count' => $customer->active_policy_count + $dup->active_policy_count,
                'total_policy_count' => $customer->total_policy_count + $dup->total_policy_count,
                'notes' => trim(($customer->notes ?? '')."\n— merged from {$dup->customer_code} —\n".($dup->notes ?? '')),
            ]);
            $dup->delete();
        });

        return new CustomerResource($customer->fresh()->load(['kycDocs', 'assignmentHistory']));
    }

    private function authorizeTenant(Request $request, Customer $customer): void
    {
        if ((int) $customer->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
