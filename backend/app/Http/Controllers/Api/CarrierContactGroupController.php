<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\CarrierContactGroupRequest;
use App\Http\Resources\CarrierContactGroupResource;
use App\Models\Carrier;
use App\Models\CarrierContactGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CarrierContactGroupController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(CarrierContactGroup::query(), $request)->with('carrier');
        if ($code = $request->input('carrierCode')) {
            $q->whereHas('carrier', fn ($w) => $w->where('code', $code));
        }
        if ($department = $request->input('department')) {
            $q->where('department', $department);
        }
        return CarrierContactGroupResource::collection($q->orderBy('id')->paginate($this->perPage($request)));
    }

    public function store(CarrierContactGroupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $carrier = Carrier::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('code', $data['carrierCode'])
            ->firstOrFail();
        $group = CarrierContactGroup::create([
            'tenant_id' => $this->tenantId($request),
            'carrier_id' => $carrier->id,
            'name' => $data['name'],
            'emails' => $data['emails'],
            'department' => $data['department'],
            'insurance_types' => $data['insuranceTypes'],
            'is_default' => (bool) ($data['isDefault'] ?? false),
            'notes' => $data['notes'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
        ]);
        return (new CarrierContactGroupResource($group->load('carrier')))->response()->setStatusCode(201);
    }

    public function show(Request $request, CarrierContactGroup $contactGroup): CarrierContactGroupResource
    {
        $this->authorizeTenant($request, $contactGroup);
        return new CarrierContactGroupResource($contactGroup->load('carrier'));
    }

    public function update(CarrierContactGroupRequest $request, CarrierContactGroup $contactGroup): CarrierContactGroupResource
    {
        $this->authorizeTenant($request, $contactGroup);
        $data = $request->validated();
        $updates = array_filter([
            'name' => $data['name'] ?? null,
            'emails' => $data['emails'] ?? null,
            'department' => $data['department'] ?? null,
            'insurance_types' => $data['insuranceTypes'] ?? null,
            'is_default' => array_key_exists('isDefault', $data) ? (bool) $data['isDefault'] : null,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : null,
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : null,
        ], static fn ($v) => $v !== null);
        $contactGroup->update($updates);
        return new CarrierContactGroupResource($contactGroup->fresh()->load('carrier'));
    }

    public function destroy(Request $request, CarrierContactGroup $contactGroup): JsonResponse
    {
        $this->authorizeTenant($request, $contactGroup);
        $contactGroup->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeTenant(Request $request, CarrierContactGroup $g): void
    {
        if ((int) $g->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
