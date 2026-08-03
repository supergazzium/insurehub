<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\MotorActTariff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 9 — admin CRUD for compulsory-motor tariffs. Read is already public
 * (used by QuoteController::actTariffs); write is admin-gated inline.
 */
class MotorActTariffController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $rows = MotorActTariff::query()->orderBy('vehicle_type_code')->get();
        return response()->json([
            'data' => $rows->map(fn ($r) => $this->shape($r)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('motor_tariffs.manage');
        $data = $request->validate([
            'vehicleTypeCode' => ['required', 'string', 'max:8', 'unique:motor_act_tariffs,vehicle_type_code'],
            'labelTh' => ['required', 'string', 'max:128'],
            'labelEn' => ['sometimes', 'nullable', 'string', 'max:128'],
            'premium' => ['required', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $row = MotorActTariff::create([
            'vehicle_type_code' => $data['vehicleTypeCode'],
            'label_th' => $data['labelTh'],
            'label_en' => $data['labelEn'] ?? null,
            'premium' => $data['premium'],
            'active' => $data['active'] ?? true,
        ]);
        return response()->json(['data' => $this->shape($row)], 201);
    }

    public function update(Request $request, MotorActTariff $tariff): JsonResponse
    {
        Gate::authorize('motor_tariffs.manage');
        $data = $request->validate([
            'labelTh' => ['sometimes', 'string', 'max:128'],
            'labelEn' => ['sometimes', 'nullable', 'string', 'max:128'],
            'premium' => ['sometimes', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $map = ['labelTh' => 'label_th', 'labelEn' => 'label_en', 'premium' => 'premium', 'active' => 'active'];
        $updates = [];
        foreach ($map as $c => $s) if (array_key_exists($c, $data)) $updates[$s] = $data[$c];
        if ($updates !== []) $tariff->update($updates);
        return response()->json(['data' => $this->shape($tariff->fresh())]);
    }

    public function destroy(Request $request, MotorActTariff $tariff): JsonResponse
    {
        Gate::authorize('motor_tariffs.manage');
        $tariff->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /** @return array<string, mixed> */
    private function shape(MotorActTariff $r): array
    {
        return [
            'id' => (string) $r->id,
            'vehicleTypeCode' => $r->vehicle_type_code,
            'labelTh' => $r->label_th,
            'labelEn' => $r->label_en,
            'premium' => (float) $r->premium,
            'active' => (bool) $r->active,
        ];
    }
}
