<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\ProductTypeResource;
use App\Models\CommissionTier;
use App\Models\ProductType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin CRUD for MGM product_types — the "column headers" of the carrier ×
 * product-type matrix (PR-A4). Full CRUD unlike CommissionTierController
 * (which is fixed at 3) — admins add product types as new business lines
 * come online.
 *
 * The main admin operation is reassigning a type to a different tier.
 * Historical policies keep their historical rates via ledger snapshotting
 * (PR-D), so reassigning is safe.
 */
class ProductTypeController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = ProductType::query()
            ->with('tier')
            ->where('tenant_id', $this->tenantId($request))
            ->orderBy('sort_order');

        if ($request->boolean('activeOnly')) {
            $q->where('active', true);
        }

        return ProductTypeResource::collection($q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, isUpdate: false);
        $this->assertTierInTenant($request, $data['tier_id']);

        $productType = ProductType::create([
            'tenant_id' => $this->tenantId($request),
            ...$data,
        ]);

        return (new ProductTypeResource($productType->fresh('tier')))->response()->setStatusCode(201);
    }

    public function update(Request $request, ProductType $productType): ProductTypeResource
    {
        $this->authorizeType($request, $productType);
        $data = $this->validated($request, isUpdate: true);
        if (isset($data['tier_id'])) {
            $this->assertTierInTenant($request, $data['tier_id']);
        }
        $productType->update($data);

        return new ProductTypeResource($productType->fresh('tier'));
    }

    public function destroy(Request $request, ProductType $productType): JsonResponse
    {
        $this->authorizeType($request, $productType);
        // Refuse deletion if any product still references this type. Admin
        // must reassign products first — matches how carriers → products is
        // handled elsewhere.
        $inUse = $productType->products()->exists();
        if ($inUse) {
            abort(422, 'ยังมีสินค้าอ้างอิงประเภทนี้อยู่ — ต้องเปลี่ยนประเภทของสินค้าก่อน');
        }
        $productType->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $isUpdate): array
    {
        $sometimes = $isUpdate ? 'sometimes' : 'required';
        $v = $request->validate([
            'code' => [$sometimes, 'string', 'max:64'],
            'nameTh' => [$sometimes, 'string', 'max:128'],
            'nameEn' => [$sometimes, 'string', 'max:128'],
            'subOf' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tierId' => [$sometimes, 'integer', 'exists:commission_tiers,id'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $map = [
            'code' => 'code', 'nameTh' => 'name_th', 'nameEn' => 'name_en',
            'subOf' => 'sub_of', 'tierId' => 'tier_id', 'sortOrder' => 'sort_order',
            'active' => 'active', 'notes' => 'notes',
        ];
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }

        return $out;
    }

    private function authorizeType(Request $request, ProductType $type): void
    {
        if ((int) $type->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    private function assertTierInTenant(Request $request, int $tierId): void
    {
        $tier = CommissionTier::query()->find($tierId);
        if ($tier === null || (int) $tier->tenant_id !== $this->tenantId($request)) {
            abort(422, 'Tier not in tenant.');
        }
    }
}
