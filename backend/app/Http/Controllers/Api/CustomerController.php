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

        // Live policy counts via correlated subqueries. The customers table
        // *does* store denormalized `active_policy_count` / `total_policy_count`
        // fields, but they were never backfilled and drift out of sync — see
        // customer C9901503 which had 2 policies but stored 0/0. Computing
        // here on every list read is O(N) subqueries per page (25–100 rows)
        // and the customers/policies indexes make each subquery ~1ms, so the
        // simpler approach beats a stale denormalized counter.
        $q = DB::table('customers as c')
            ->leftJoin('agents as a', 'a.id', '=', 'c.assigned_agent_id')
            ->where('c.tenant_id', $tenantId)
            ->whereNull('c.deleted_at')
            ->select([
                'c.id', 'c.customer_code', 'c.customer_type', 'c.first_name', 'c.last_name',
                'c.nickname', 'c.id_card', 'c.phone', 'c.email', 'c.province',
                'c.assigned_agent_id',
                'c.active', 'c.registered_at',
                'a.agent_code as assigned_agent_code',
                'a.first_name as assigned_agent_first_name',
                'a.last_name as assigned_agent_last_name',
                DB::raw("(SELECT COUNT(*) FROM policies p WHERE p.customer_id = c.id AND p.deleted_at IS NULL) AS total_policy_count"),
                DB::raw("(SELECT COUNT(*) FROM policies p WHERE p.customer_id = c.id AND p.deleted_at IS NULL AND p.status = 'active') AS active_policy_count"),
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
            // Real check — the denormalized counter is unreliable (see the
            // subquery above), so we probe the policies table directly.
            $q->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('policies')
                    ->whereColumn('policies.customer_id', 'c.id')
                    ->whereNull('policies.deleted_at');
            });
        }
        if ($ctype = $request->input('customerType')) {
            $q->where('c.customer_type', $ctype);
        }

        // Sortable columns — whitelisted so callers can't order by arbitrary
        // SQL. Default preserves the "newly-created rows on page 1" behavior.
        $sortMap = [
            'customerCode' => 'c.customer_code',
            'firstName' => 'c.first_name',
            'lastName' => 'c.last_name',
            'province' => 'c.province',
            'registeredAt' => 'c.registered_at',
            'newest' => 'c.id',
        ];
        $sortBy = $sortMap[$request->input('sortBy', 'newest')] ?? 'c.id';
        $defaultDir = $sortBy === 'c.id' ? 'desc' : 'asc';
        $sortDir = strtolower((string) $request->input('sortDir', $defaultDir)) === 'desc' ? 'desc' : 'asc';

        $paginator = $q->orderBy($sortBy, $sortDir)
            ->orderBy('c.id', 'desc') // deterministic tiebreak
            ->paginate($this->perPage($request));

        return CustomerListResource::collection($paginator);
    }

    /**
     * GET /customers/next-code — return the next available customer_code
     * following the `C0000000` scheme (max numeric suffix + 1, zero-padded
     * to 7 digits). Mirrors the legacy Access `GetNextClientCode()` VBA.
     *
     * Scans both active and soft-deleted rows so codes never collide with
     * previously-deleted customers. Padding grows naturally past 9,999,999.
     */
    public function nextCode(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        // Match only strict `C\d+` codes; ignore historical rows with
        // legacy schemes like `CUS-2020-042` so max() picks the right one.
        $maxNum = (int) DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('customer_code', 'like', 'C%')
            ->whereRaw("SUBSTRING(customer_code, 2) REGEXP '^[0-9]+$'")
            ->max(DB::raw('CAST(SUBSTRING(customer_code, 2) AS UNSIGNED)'));

        $next = $maxNum + 1;
        $padded = str_pad((string) $next, 7, '0', STR_PAD_LEFT);

        return response()->json([
            'code' => 'C'.$padded,
            'next' => $next,
        ]);
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
        return new CustomerResource($customer->load(['kycDocs', 'assignmentHistory', 'assignedAgent']));
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
