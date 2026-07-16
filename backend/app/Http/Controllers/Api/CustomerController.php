<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerListResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends ApiController
{
    /**
     * Paginated customer list — server-side search + filters, with joined
     * assigned-agent display columns. Returns the lean CustomerListResource
     * shape (heavy detail stays on CustomerResource for show/update).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->tenantId($request);

        $q = DB::table('customers as c')
            ->leftJoin('agents as a', 'a.id', '=', 'c.assigned_agent_id')
            ->where('c.tenant_id', $tenantId)
            ->whereNull('c.deleted_at')
            ->select([
                'c.id', 'c.customer_code', 'c.customer_type', 'c.first_name', 'c.last_name',
                'c.nickname', 'c.id_card', 'c.phone', 'c.email', 'c.province',
                'c.assigned_agent_id', 'c.active_policy_count', 'c.total_policy_count',
                'c.active', 'c.registered_at',
                'a.agent_code as assigned_agent_code',
                'a.first_name as assigned_agent_first_name',
                'a.last_name as assigned_agent_last_name',
            ]);

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('c.customer_code', 'like', $like)
                    ->orWhere('c.first_name', 'like', $like)
                    ->orWhere('c.last_name', 'like', $like)
                    ->orWhere('c.email', 'like', $like)
                    ->orWhere('c.id_card', 'like', $like)
                    ->orWhere('c.phone', 'like', $like);
            });
        }
        if ($agentId = $request->input('assignedAgentId')) {
            $q->where('c.assigned_agent_id', $agentId);
        }
        if ($request->boolean('unassigned')) {
            $q->whereNull('c.assigned_agent_id');
        }
        if ($request->has('active')) {
            $q->where('c.active', $request->boolean('active'));
        }
        if ($request->boolean('withPolicies')) {
            $q->where('c.active_policy_count', '>', 0);
        }
        if ($ctype = $request->input('customerType')) {
            $q->where('c.customer_type', $ctype);
        }

        // Order by id desc so newly-created rows land at the top of page 1,
        // where users expect to see them after clicking "+ New Customer".
        $paginator = $q->orderBy('c.id', 'desc')->paginate($this->perPage($request));

        return CustomerListResource::collection($paginator);
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
