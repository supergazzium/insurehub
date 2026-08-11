<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductResource;
use App\Models\Carrier;
use App\Models\Product;
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
        $payload = $request->toModel() + ['tenant_id' => $this->tenantId($request)];
        $product = Product::create($payload);

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Request $request, Product $product): ProductResource
    {
        $this->authorizeTenant($request, $product);

        return new ProductResource($product->load('carrier'));
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $this->authorizeTenant($request, $product);
        $product->update($request->toModel());

        return new ProductResource($product->fresh());
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
}
