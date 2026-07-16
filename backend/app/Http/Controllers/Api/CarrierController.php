<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\CarrierRequest;
use App\Http\Resources\CarrierListResource;
use App\Http\Resources\CarrierResource;
use App\Models\Carrier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CarrierController extends ApiController
{
    /**
     * Paginated carrier list — returns the lean CarrierListResource shape.
     * Full detail (with contact groups etc.) stays on CarrierResource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(Carrier::query(), $request)
            ->withCount(['products', 'contracts']);

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('name_en', 'like', $like)
                    ->orWhere('nickname_th', 'like', $like)
                    ->orWhere('tax_id', 'like', $like);
            });
        }
        if ($request->boolean('activeOnly')) {
            $q->where('active', true);
        }
        if ($insureType = $request->input('insureType')) {
            $q->where('insure_type', $insureType);
        }

        // Order by id desc so newly-created rows land on page 1.
        return CarrierListResource::collection($q->orderBy('id', 'desc')->paginate($this->perPage($request)));
    }

    public function store(CarrierRequest $request): JsonResponse
    {
        $payload = $request->toModel() + ['tenant_id' => $this->tenantId($request)];
        $carrier = Carrier::create($payload);
        return (new CarrierResource($carrier->load('bankAccounts')->loadCount(['products', 'contracts'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, Carrier $carrier): CarrierResource
    {
        $this->authorizeTenant($request, $carrier);
        return new CarrierResource($carrier->load('bankAccounts')->loadCount(['products', 'contracts']));
    }

    public function update(CarrierRequest $request, Carrier $carrier): CarrierResource
    {
        $this->authorizeTenant($request, $carrier);
        $carrier->update($request->toModel());
        return new CarrierResource($carrier->fresh()->loadCount(['products', 'contracts']));
    }

    public function destroy(Request $request, Carrier $carrier): JsonResponse
    {
        $this->authorizeTenant($request, $carrier);
        $carrier->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeTenant(Request $request, Carrier $carrier): void
    {
        if ((int) $carrier->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
