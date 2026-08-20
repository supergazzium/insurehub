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

    // POST/PATCH/DELETE are frozen — the (carrier × type) matrix is now a
    // read-only view; commission rates are edited per-product via
    // ProductController and land in product_commission_rates. Returning 410
    // rather than removing the routes so any stale caller gets a clear
    // signal instead of a 404.

    public function store(Request $request): JsonResponse
    {
        abort(410, 'The carrier × product-type matrix is read-only. Edit commission rates on the product.');
    }

    public function update(Request $request, CarrierProductTypeRate $rate): JsonResponse
    {
        abort(410, 'The carrier × product-type matrix is read-only. Edit commission rates on the product.');
    }

    public function destroy(Request $request, CarrierProductTypeRate $rate): JsonResponse
    {
        abort(410, 'The carrier × product-type matrix is read-only. Edit commission rates on the product.');
    }

}
