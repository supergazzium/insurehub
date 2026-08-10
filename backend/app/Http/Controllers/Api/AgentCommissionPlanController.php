<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\AgentCommissionPlanResource;
use App\Models\Agent;
use App\Models\AgentCommissionPlan;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD for Layer 2 (firm -> agent) commission overrides. See
 * database/migrations/2027_01_09_000100_create_agent_commission_plans.php and
 * CommissionEngine::resolveRates() for how these rows feed accrual.
 */
class AgentCommissionPlanController extends ApiController
{
    public function index(Request $request, Agent $agent): AnonymousResourceCollection
    {
        $this->authorizeAgent($request, $agent);
        $plans = $agent->commissionPlans()
            ->orderByDesc('valid_start')
            ->orderByDesc('id')
            ->get();

        return AgentCommissionPlanResource::collection($plans);
    }

    public function store(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request, $agent);
        $data = $this->validated($request, isUpdate: false);
        $this->assertProductInTenant($request, $data['product_id'] ?? null);

        $plan = $agent->commissionPlans()->create([
            'tenant_id' => $this->tenantId($request),
            ...$data,
        ]);

        return (new AgentCommissionPlanResource($plan->fresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Agent $agent, AgentCommissionPlan $commissionPlan): AgentCommissionPlanResource
    {
        $this->authorizeAgent($request, $agent);
        $this->authorizePlan($agent, $commissionPlan);
        $data = $this->validated($request, isUpdate: true);
        $this->assertProductInTenant(
            $request,
            array_key_exists('product_id', $data) ? $data['product_id'] : $commissionPlan->product_id,
        );

        $commissionPlan->update($data);

        return new AgentCommissionPlanResource($commissionPlan->fresh());
    }

    public function destroy(Request $request, Agent $agent, AgentCommissionPlan $commissionPlan): JsonResponse
    {
        $this->authorizeAgent($request, $agent);
        $this->authorizePlan($agent, $commissionPlan);
        $commissionPlan->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $isUpdate): array
    {
        $sometimes = $isUpdate ? 'sometimes' : 'nullable';
        $required = $isUpdate ? 'sometimes' : 'required';

        $v = $request->validate([
            'productId' => [$sometimes, 'integer', 'exists:products,id'],
            'category' => [$sometimes, 'string', 'max:64'],
            'agRate' => [$sometimes, 'numeric', 'gte:0', 'lte:1'],
            'inhRate' => [$sometimes, 'numeric', 'gte:0', 'lte:1'],
            'overrideRate' => [$sometimes, 'numeric', 'gte:0', 'lte:1'],
            'validStart' => [$required, 'date'],
            'validEnd' => [$sometimes, 'date', 'after_or_equal:validStart'],
            'note' => [$sometimes, 'string', 'max:255'],
        ]);

        // Reject empty plans that don't actually override any party. A row with
        // all-null rates does nothing and will silently break "specific wins"
        // resolution — force the caller to be explicit.
        $anyRate = array_key_exists('agRate', $v)
            || array_key_exists('inhRate', $v)
            || array_key_exists('overrideRate', $v);
        if (! $isUpdate && ! $anyRate) {
            abort(422, 'At least one of agRate / inhRate / overrideRate must be set.');
        }

        $map = [
            'productId' => 'product_id',
            'category' => 'category',
            'agRate' => 'ag_rate',
            'inhRate' => 'inh_rate',
            'overrideRate' => 'override_rate',
            'validStart' => 'valid_start',
            'validEnd' => 'valid_end',
            'note' => 'note',
        ];
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }

        return $out;
    }

    private function authorizeAgent(Request $request, Agent $agent): void
    {
        if ((int) $agent->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    private function authorizePlan(Agent $agent, AgentCommissionPlan $plan): void
    {
        if ((int) $plan->agent_id !== (int) $agent->id) {
            abort(404);
        }
    }

    private function assertProductInTenant(Request $request, mixed $productId): void
    {
        if ($productId === null) {
            return;
        }
        $product = Product::query()->find($productId);
        if ($product === null || (int) $product->tenant_id !== $this->tenantId($request)) {
            abort(422, 'Product not in tenant.');
        }
    }
}
