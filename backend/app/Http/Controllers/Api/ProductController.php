<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(Product::query(), $request);

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('code', 'like', $like)->orWhere('name', 'like', $like);
            });
        }
        if ($carrierId = $request->input('carrierId')) {
            $q->where('carrier_id', $carrierId);
        }
        if ($type = $request->input('type')) {
            $q->where('type', $type);
        }
        if ($request->boolean('activeOnly')) {
            $q->where('active', true);
        }

        return ProductResource::collection($q->orderBy('code')->paginate($this->perPage($request)));
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
        return new ProductResource($product);
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

    private function authorizeTenant(Request $request, Product $product): void
    {
        if ((int) $product->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
