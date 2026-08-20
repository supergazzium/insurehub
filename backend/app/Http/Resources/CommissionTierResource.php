<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CommissionTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin CommissionTier
 */
class CommissionTierResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $rankRates = $this->whenLoaded('rankRates');

        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'nameTh' => $this->name_th,
            'nameEn' => $this->name_en,
            'colorHex' => $this->color_hex,
            'sortOrder' => (int) $this->sort_order,
            'notes' => $this->notes,
            'rankRates' => $rankRates instanceof Collection
                ? $rankRates->map(fn ($rate) => [
                    'id' => (string) $rate->id,
                    'rankId' => (string) $rate->rank_id,
                    'rankLevel' => (int) ($rate->rank->level ?? 0),
                    'mgmtFeeRate' => (float) $rate->mgmt_fee_rate,
                    'referralFeeRate' => (float) $rate->referral_fee_rate,
                    'validStart' => optional($rate->valid_start)->toDateString(),
                    'validEnd' => optional($rate->valid_end)->toDateString(),
                ])->values()
                : null,
        ];
    }
}
