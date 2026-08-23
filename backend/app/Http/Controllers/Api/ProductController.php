<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductResource;
use App\Models\Carrier;
use App\Models\Product;
use App\Models\ProductCommissionBand;
use App\Models\ProductCommissionRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProductController extends ApiController
{
    /**
     * Paginated product list — server-side search + filters + joined carrier
     * display columns. Returns lean ProductListResource rows.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->tenantId($request);

        $q = DB::table('products as pr')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'pr.carrier_id')
            ->where('pr.tenant_id', $tenantId)
            ->whereNull('pr.deleted_at')
            ->select([
                'pr.id', 'pr.code', 'pr.commission_code', 'pr.name', 'pr.name_en',
                'pr.carrier_id', 'pr.type', 'pr.category', 'pr.sub_category',
                'pr.sub_category_2', 'pr.main_rider',
                'pr.min_age', 'pr.max_age', 'pr.min_sum_assure', 'pr.max_sum_assure',
                'pr.valid_start', 'pr.valid_end', 'pr.active',
                'ca.code as carrier_code',
                'ca.name as carrier_name',
            ]);

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('pr.code', 'like', $like)
                    ->orWhere('pr.name', 'like', $like)
                    ->orWhere('pr.name_en', 'like', $like)
                    ->orWhere('pr.commission_code', 'like', $like)
                    ->orWhere('ca.code', 'like', $like)
                    ->orWhere('ca.name', 'like', $like);
            });
        }
        if ($carrierId = $request->input('carrierId')) {
            $q->where('pr.carrier_id', $carrierId);
        }
        if ($type = $request->input('type')) {
            $q->where('pr.type', $type);
        }
        if ($category = $request->input('category')) {
            $q->where('pr.category', 'like', "%{$category}%");
        }
        if ($mainRider = $request->input('mainRider')) {
            $q->where('pr.main_rider', $mainRider);
        }
        // Insurance-type filter — narrows by the carrier's insure_type
        // (life / non-life / tax). Composes with carrierId, type, mainRider.
        // The `type` param above filters pr.type (Life/Motor/etc.) — a
        // different column, so no collision.
        if ($insureType = $request->input('insureType')) {
            $q->where('ca.insure_type', $insureType);
        }
        if ($request->boolean('activeOnly')) {
            $q->where('pr.active', true);
        }

        // Order by id desc so newly-created rows land on page 1.
        $paginator = $q->orderBy('pr.id', 'desc')->paginate($this->perPage($request));

        return ProductListResource::collection($paginator);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $payload = $request->toModel() + ['tenant_id' => $tenantId];
        $validated = $request->validated();
        $rates = $validated['commissionRates'] ?? null;
        $bands = $validated['commissionBands'] ?? null;

        $product = DB::transaction(function () use ($payload, $rates, $bands, $tenantId): Product {
            $product = Product::create($payload);
            $this->upsertCommissionRates($tenantId, $product, $rates);
            $this->replaceCommissionBands($tenantId, $product, $bands);

            return $product;
        });

        return (new ProductResource($product->load(['commissionRates', 'commissionBands', 'productType'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Product $product): ProductResource
    {
        $this->authorizeTenant($request, $product);

        return new ProductResource($product->load(['carrier', 'commissionRates', 'commissionBands', 'productType']));
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $this->authorizeTenant($request, $product);
        $tenantId = $this->tenantId($request);
        $validated = $request->validated();
        $rates = $validated['commissionRates'] ?? null;
        $bands = $validated['commissionBands'] ?? null;

        DB::transaction(function () use ($request, $product, $rates, $bands, $tenantId): void {
            $product->update($request->toModel());
            $this->upsertCommissionRates($tenantId, $product, $rates);
            $this->replaceCommissionBands($tenantId, $product, $bands);
        });

        return new ProductResource($product->fresh(['commissionRates', 'commissionBands', 'productType']));
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);
        $product->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * GET /carriers/{carrier}/products/next-code — return the next available
     * product code for the given carrier following the "PD{carrierCode}{NNNN}"
     * scheme. Uses max(numeric-suffix) + 1 across active *and* trashed rows so
     * codes never collide with previously-deleted products. Padding grows
     * naturally past 9999.
     */
    public function nextCode(Request $request, Carrier $carrier): JsonResponse
    {
        if ((int) $carrier->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        $prefix = 'PD'.$carrier->code;
        $prefixLen = strlen($prefix);

        // Match every existing product code for this carrier that follows the
        // scheme, active or soft-deleted, so we always increment past the
        // highest number ever assigned.
        $maxNum = (int) DB::table('products')
            ->where('tenant_id', $this->tenantId($request))
            ->where('carrier_id', $carrier->id)
            ->where('code', 'like', $prefix.'%')
            ->whereRaw('SUBSTRING(code, ?) REGEXP \'^[0-9]+$\'', [$prefixLen + 1])
            ->max(DB::raw('CAST(SUBSTRING(code, '.($prefixLen + 1).') AS UNSIGNED)'));

        $next = $maxNum + 1;
        $padded = str_pad((string) $next, 4, '0', STR_PAD_LEFT);

        return response()->json([
            'code' => $prefix.$padded,
            'carrierCode' => $carrier->code,
            'next' => $next,
        ]);
    }

    private function authorizeTenant(Request $request, Product $product): void
    {
        if ((int) $product->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    /**
     * Upsert both commission-rate rows for a product. Scheme is derived from
     * the current product group so it stays in sync when the operator changes
     * the group. `flat_rate` is used only for scheme='flat'; the per-year
     * columns only for scheme='life_years'. Any incoming value for the wrong
     * scheme is dropped, so switching a product from Life to Non-Life doesn't
     * leak stale per-year rates onto the flat row.
     *
     * Passing $rates=null leaves existing rows untouched (partial updates on
     * PATCH). Passing an empty {carrierToHub:{}, hubToAgent:{}} clears all
     * fields on that direction — the operator explicitly blanked the panel.
     */
    private function upsertCommissionRates(int $tenantId, Product $product, ?array $rates): void
    {
        if ($rates === null) {
            return;
        }

        $scheme = in_array($product->type, ['Life', 'Rider'], true)
            ? ProductCommissionRate::SCHEME_LIFE_YEARS
            : ProductCommissionRate::SCHEME_FLAT;

        foreach ([
            'carrierToHub' => ProductCommissionRate::DIRECTION_CARRIER_TO_HUB,
            'hubToAgent' => ProductCommissionRate::DIRECTION_HUB_TO_AGENT,
        ] as $requestKey => $direction) {
            if (! array_key_exists($requestKey, $rates)) {
                continue;
            }

            $panel = $rates[$requestKey] ?? [];
            $values = [
                'flat_rate' => $scheme === ProductCommissionRate::SCHEME_FLAT
                    ? ($panel['flatRate'] ?? null)
                    : null,
                'yr_1' => $scheme === ProductCommissionRate::SCHEME_LIFE_YEARS
                    ? ($panel['yr1'] ?? null)
                    : null,
                'yr_2' => $scheme === ProductCommissionRate::SCHEME_LIFE_YEARS
                    ? ($panel['yr2'] ?? null)
                    : null,
                'yr_3' => $scheme === ProductCommissionRate::SCHEME_LIFE_YEARS
                    ? ($panel['yr3'] ?? null)
                    : null,
                'yr_4' => $scheme === ProductCommissionRate::SCHEME_LIFE_YEARS
                    ? ($panel['yr4'] ?? null)
                    : null,
                'yr_5' => $scheme === ProductCommissionRate::SCHEME_LIFE_YEARS
                    ? ($panel['yr5'] ?? null)
                    : null,
                'yr_6_10' => $scheme === ProductCommissionRate::SCHEME_LIFE_YEARS
                    ? ($panel['yr6_10'] ?? null)
                    : null,
                'yr_11_up' => $scheme === ProductCommissionRate::SCHEME_LIFE_YEARS
                    ? ($panel['yr11Up'] ?? null)
                    : null,
                'scheme' => $scheme,
            ];

            ProductCommissionRate::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    'direction' => $direction,
                    'effective_from' => null,
                ],
                $values,
            );
        }
    }

    /**
     * Replace-all semantics for banded rates. Passing null leaves existing
     * bands untouched (partial update). Passing an explicit {carrierToHub:[]}
     * wipes all bands for that direction; a non-empty array wipes and
     * rewrites. Bands are only meaningful for Life/Rider products but the
     * server doesn't enforce that — the resolver won't read them for flat-
     * scheme products anyway, so extra data is harmless.
     */
    private function replaceCommissionBands(int $tenantId, Product $product, ?array $bands): void
    {
        if ($bands === null) {
            return;
        }

        foreach ([
            'carrierToHub' => ProductCommissionBand::DIRECTION_CARRIER_TO_HUB,
            'hubToAgent' => ProductCommissionBand::DIRECTION_HUB_TO_AGENT,
        ] as $requestKey => $direction) {
            if (! array_key_exists($requestKey, $bands)) {
                continue;
            }

            $incoming = $bands[$requestKey] ?? [];
            ProductCommissionBand::where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->where('direction', $direction)
                ->delete();

            foreach (array_values($incoming) as $i => $row) {
                ProductCommissionBand::create([
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    'direction' => $direction,
                    'band_seq' => $i + 1,
                    'sum_assured_min' => $row['sumAssuredMin'] ?? null,
                    'sum_assured_max' => $row['sumAssuredMax'] ?? null,
                    'entry_age_min' => $row['entryAgeMin'] ?? null,
                    'entry_age_max' => $row['entryAgeMax'] ?? null,
                    'yr_1' => $row['yr1'] ?? null,
                    'yr_2' => $row['yr2'] ?? null,
                    'yr_3' => $row['yr3'] ?? null,
                    'yr_4' => $row['yr4'] ?? null,
                    'yr_5' => $row['yr5'] ?? null,
                    'yr_6_up' => $row['yr6Up'] ?? null,
                    'effective_from' => null,
                ]);
            }
        }
    }
}
