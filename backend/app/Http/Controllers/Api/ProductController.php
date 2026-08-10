<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductResource;
use App\Models\Carrier;
use App\Models\Product;
use App\Services\Commission\ProductRateSeeder;
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

    public function store(ProductRequest $request, ProductRateSeeder $seeder): JsonResponse
    {
        $payload = $request->toModel() + ['tenant_id' => $this->tenantId($request)];
        $product = Product::create($payload);

        $this->applyRatePayload($request, $product, $seeder);

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Request $request, Product $product): ProductResource
    {
        $this->authorizeTenant($request, $product);

        return new ProductResource($product->load('carrier'));
    }

    public function update(ProductRequest $request, Product $product, ProductRateSeeder $seeder): ProductResource
    {
        $this->authorizeTenant($request, $product);
        $product->update($request->toModel());
        $this->applyRatePayload($request, $product, $seeder);

        return new ProductResource($product->fresh());
    }

    /**
     * Consume `commissionRates` (structured) or `commissionPercent` (legacy
     * shorthand) from the request and persist via the seeder. Structured
     * payload wins when both are present.
     */
    private function applyRatePayload(ProductRequest $request, Product $product, ProductRateSeeder $seeder): void
    {
        $structured = $request->input('commissionRates');
        if (is_array($structured)) {
            $seeder->seed($product, $structured);

            return;
        }
        $percent = $request->input('commissionPercent');
        if ($percent !== null && $percent !== '') {
            $seeder->seedFlatPercent($product, (float) $percent);
        }
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

    /**
     * GET /products/{product}/commission-rates — returns everything the rate
     * editor needs to prefill:
     *   data:  flat table rows (party × installment_term) from
     *          product_commission_rate_installments — used by the "flat" shape.
     *   years: per-year grid from product_commission_rates — used by the
     *          "per-year" shape. Populated only if that row exists.
     */
    public function commissionRates(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);

        $installments = DB::table('product_commission_rate_installments')
            ->where('product_id', $product->id)
            ->orderByRaw("FIELD(party, 'com', 'ag', 'in')")
            ->orderBy('installment_term')
            ->orderByRaw('COALESCE(min_sum_assure, 0)')
            ->get(['id', 'party', 'installment_term', 'min_sum_assure', 'max_sum_assure', 'rate']);

        // Wide table can now hold multiple rows per product — one per
        // entry-age bracket. Fetch all rows ordered so unbounded (both age
        // bounds NULL) is last; this way `$years` (a single-grid legacy field)
        // prefers the first specific bracket, which is what the drawer's
        // per-year preview needs. `$brackets` carries all rows for the new
        // age-year shape.
        $wideRows = DB::table('product_commission_rates')
            ->where('product_id', $product->id)
            ->orderByRaw('CASE WHEN min_age IS NULL AND max_age IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(min_age, 0)')
            ->orderByDesc('id')
            ->get();

        $extractYears = static function (object $row): array {
            $grid = [];
            foreach ([1, 2, 3, 4, 5, 6] as $y) {
                // Year 6 in the UI represents the "6+" bucket, which stores in
                // com_rate_yr_6 through yr_11up. Reading yr_6 back is fine
                // because the seeder writes the same value across the tail.
                $grid[$y] = [
                    'inh' => $row->{"com_rate_yr_{$y}"} !== null ? (float) $row->{"com_rate_yr_{$y}"} : null,
                    'ag' => $row->{"ag_rate_yr_{$y}"} !== null ? (float) $row->{"ag_rate_yr_{$y}"} : null,
                    'ov' => $row->{"in_rate_yr_{$y}"} !== null ? (float) $row->{"in_rate_yr_{$y}"} : null,
                ];
            }

            return $grid;
        };

        $brackets = [];
        foreach ($wideRows as $row) {
            $brackets[] = [
                'minAge' => $row->min_age !== null ? (int) $row->min_age : null,
                'maxAge' => $row->max_age !== null ? (int) $row->max_age : null,
                'years' => $extractYears($row),
            ];
        }

        // Backward-compat `years` field — used by the per-year prefill path.
        // Prefer the first bracket (which is either the only one, or the
        // "least specific after specifics" per the ORDER BY above).
        $years = $wideRows->isNotEmpty() ? $extractYears($wideRows->first()) : null;

        // Band reconstruction: bucket the installments rows by
        // (min, max, installment_term) so the drawer's band editor can
        // prefill without re-guessing the intent. Rows with both bounds null
        // are the "unbounded" flat rows and are still included — the editor
        // renders them as a band with min/max blank.
        $bandBuckets = [];
        foreach ($installments as $r) {
            $key = ($r->min_sum_assure ?? '').':'.($r->max_sum_assure ?? '').':'.($r->installment_term ?? '');
            if (! isset($bandBuckets[$key])) {
                $bandBuckets[$key] = [
                    'minSumAssure' => $r->min_sum_assure !== null ? (float) $r->min_sum_assure : null,
                    'maxSumAssure' => $r->max_sum_assure !== null ? (float) $r->max_sum_assure : null,
                    'installmentTerm' => $r->installment_term,
                    'inh' => null,
                    'ag' => null,
                    'ov' => null,
                ];
            }
            $partyKey = match ($r->party) {
                'com' => 'inh',
                'ag' => 'ag',
                'in' => 'ov',
                default => null,
            };
            if ($partyKey !== null) {
                $bandBuckets[$key][$partyKey] = (float) $r->rate;
            }
        }
        $bands = array_values($bandBuckets);

        return response()->json([
            'data' => $installments->map(fn ($r) => [
                'id' => (string) $r->id,
                'party' => $r->party,
                'installmentTerm' => $r->installment_term,
                'minSumAssure' => $r->min_sum_assure !== null ? (float) $r->min_sum_assure : null,
                'maxSumAssure' => $r->max_sum_assure !== null ? (float) $r->max_sum_assure : null,
                'rate' => (float) $r->rate,
            ]),
            'years' => $years,
            'bands' => $bands,
            'brackets' => $brackets,
        ]);
    }

    private function authorizeTenant(Request $request, Product $product): void
    {
        if ((int) $product->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
