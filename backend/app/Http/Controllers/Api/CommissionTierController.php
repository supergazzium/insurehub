<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CommissionTierResource;
use App\Models\CommissionTier;
use App\Models\CommissionTierRankRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin CRUD for the 3 MGM commission tiers.
 *
 * Only rename + rate edits are exposed — the number of tiers is fixed at 3
 * (matches the source Excel and the MGM engine's assumptions). There's no
 * store() or destroy() route.
 */
class CommissionTierController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tiers = CommissionTier::query()
            ->where('tenant_id', $this->tenantId($request))
            ->with(['rankRates.rank'])
            ->orderBy('sort_order')
            ->get();

        return CommissionTierResource::collection($tiers);
    }

    /**
     * Update tier metadata (name_th, name_en, color_hex, notes).
     * Rate cells are updated separately via updateRate().
     */
    public function update(Request $request, CommissionTier $commissionTier): CommissionTierResource
    {
        $this->authorizeTier($request, $commissionTier);
        $data = $request->validate([
            'nameTh' => ['sometimes', 'string', 'max:64'],
            'nameEn' => ['sometimes', 'string', 'max:64'],
            'colorHex' => ['sometimes', 'nullable', 'string', 'max:7'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $map = ['nameTh' => 'name_th', 'nameEn' => 'name_en', 'colorHex' => 'color_hex', 'notes' => 'notes'];
        $payload = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $payload[$snake] = $data[$camel];
            }
        }
        $commissionTier->update($payload);

        return new CommissionTierResource($commissionTier->fresh(['rankRates.rank']));
    }

    /**
     * Update a single (tier × rank) rate cell.
     * PATCH /commission-tiers/{tier}/rates/{rate}
     */
    public function updateRate(Request $request, CommissionTier $commissionTier, CommissionTierRankRate $rate): JsonResponse
    {
        $this->authorizeTier($request, $commissionTier);
        if ((int) $rate->tier_id !== (int) $commissionTier->id) {
            abort(404);
        }
        $data = $request->validate([
            'mgmtFeeRate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'referralFeeRate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);
        $payload = [];
        if (array_key_exists('mgmtFeeRate', $data)) {
            $payload['mgmt_fee_rate'] = $data['mgmtFeeRate'];
        }
        if (array_key_exists('referralFeeRate', $data)) {
            $payload['referral_fee_rate'] = $data['referralFeeRate'];
        }
        $rate->update($payload);

        return response()->json([
            'id' => (string) $rate->id,
            'tierId' => (string) $rate->tier_id,
            'rankId' => (string) $rate->rank_id,
            'mgmtFeeRate' => (float) $rate->mgmt_fee_rate,
            'referralFeeRate' => (float) $rate->referral_fee_rate,
        ]);
    }

    private function authorizeTier(Request $request, CommissionTier $tier): void
    {
        if ((int) $tier->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
