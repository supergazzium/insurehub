<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Carrier;
use App\Models\CarrierProductTypeRate;
use App\Models\ProductType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin CRUD for the (carrier × product-type) standard commission matrix.
 * This is what the MGM engine (PR-D) reads to compute base commission for
 * non-life products.
 *
 * The natural admin surface is a 2D grid: rows = carriers, columns =
 * product-types, cells = standard_rate. The index endpoint returns the
 * grid pre-shaped so the admin UI can render without further pivoting.
 * PATCH updates a single cell; POST creates a new cell for a (carrier,
 * type) pair that had no row yet; DELETE removes a cell (which the
 * engine interprets as "not sold" — same as null standard_rate).
 */
class CarrierProductTypeRateController extends ApiController
{
    /**
     * Return the full matrix pre-shaped for the admin grid.
     *
     * Response:
     *   carriers:      [{id, code, name, insureType}]
     *   productTypes:  [{id, code, nameTh, nameEn, subOf, tierId, sortOrder}]
     *   rates:         [{id, carrierId, productTypeId, standardRate, validStart}]
     *
     * Frontend renders as a grid; each cell is a rate id or null if no
     * row exists for that (carrier, type) yet.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $carriers = Carrier::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'insure_type']);

        $productTypes = ProductType::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name_th', 'name_en', 'sub_of', 'tier_id', 'sort_order']);

        $rates = CarrierProductTypeRate::query()
            ->where('tenant_id', $tenantId)
            ->get(['id', 'carrier_id', 'product_type_id', 'standard_rate', 'valid_start']);

        return response()->json([
            'carriers' => $carriers->map(fn ($c) => [
                'id' => (string) $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'insureType' => $c->insure_type,
            ]),
            'productTypes' => $productTypes->map(fn ($t) => [
                'id' => (string) $t->id,
                'code' => $t->code,
                'nameTh' => $t->name_th,
                'nameEn' => $t->name_en,
                'subOf' => $t->sub_of,
                'tierId' => (string) $t->tier_id,
                'sortOrder' => (int) $t->sort_order,
            ]),
            'rates' => $rates->map(fn ($r) => [
                'id' => (string) $r->id,
                'carrierId' => (string) $r->carrier_id,
                'productTypeId' => (string) $r->product_type_id,
                'standardRate' => $r->standard_rate !== null ? (float) $r->standard_rate : null,
                'validStart' => optional($r->valid_start)->toDateString(),
            ]),
        ]);
    }

    /**
     * Create a new rate cell. Fails if (carrier, type, valid_start=null)
     * already exists — use update instead.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'carrierId' => ['required', 'integer', 'exists:carriers,id'],
            'productTypeId' => ['required', 'integer', 'exists:product_types,id'],
            'standardRate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $this->assertCarrierInTenant($request, (int) $data['carrierId']);
        $this->assertTypeInTenant($request, (int) $data['productTypeId']);

        $rate = CarrierProductTypeRate::create([
            'tenant_id' => $tenantId,
            'carrier_id' => $data['carrierId'],
            'product_type_id' => $data['productTypeId'],
            'standard_rate' => $data['standardRate'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'id' => (string) $rate->id,
            'carrierId' => (string) $rate->carrier_id,
            'productTypeId' => (string) $rate->product_type_id,
            'standardRate' => $rate->standard_rate !== null ? (float) $rate->standard_rate : null,
        ], 201);
    }

    public function update(Request $request, CarrierProductTypeRate $rate): JsonResponse
    {
        $this->authorizeRate($request, $rate);
        $data = $request->validate([
            'standardRate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $payload = [];
        if (array_key_exists('standardRate', $data)) {
            $payload['standard_rate'] = $data['standardRate'];
        }
        if (array_key_exists('notes', $data)) {
            $payload['notes'] = $data['notes'];
        }
        $rate->update($payload);

        return response()->json([
            'id' => (string) $rate->id,
            'carrierId' => (string) $rate->carrier_id,
            'productTypeId' => (string) $rate->product_type_id,
            'standardRate' => $rate->standard_rate !== null ? (float) $rate->standard_rate : null,
        ]);
    }

    public function destroy(Request $request, CarrierProductTypeRate $rate): JsonResponse
    {
        $this->authorizeRate($request, $rate);
        $rate->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeRate(Request $request, CarrierProductTypeRate $rate): void
    {
        if ((int) $rate->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    private function assertCarrierInTenant(Request $request, int $carrierId): void
    {
        $carrier = Carrier::query()->find($carrierId);
        if ($carrier === null || (int) $carrier->tenant_id !== $this->tenantId($request)) {
            abort(422, 'Carrier not in tenant.');
        }
    }

    private function assertTypeInTenant(Request $request, int $typeId): void
    {
        $type = ProductType::query()->find($typeId);
        if ($type === null || (int) $type->tenant_id !== $this->tenantId($request)) {
            abort(422, 'Product type not in tenant.');
        }
    }
}
