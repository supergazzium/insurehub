<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'nameEn' => $this->name_en ?? '',
            'carrierId' => (string) $this->carrier_id,
            'type' => $this->type ?? '',
            'category' => $this->category ?? '',
            'subCategory' => $this->sub_category ?? '',
            'subCategory2' => $this->sub_category_2 ?? '',
            'summary' => $this->summary ?? '',
            'coverage' => (float) $this->coverage,
            'durationYears' => (int) $this->duration_years,
            'payYears' => (int) $this->pay_years,
            'premiumMode' => $this->premium_mode,
            'minPremium' => (float) $this->min_premium,
            'maxPremium' => (float) $this->max_premium,
            'minAge' => (int) $this->min_age,
            'maxAge' => (int) $this->max_age,
            'minSumAssure' => $this->min_sum_assure !== null ? (float) $this->min_sum_assure : null,
            'maxSumAssure' => $this->max_sum_assure !== null ? (float) $this->max_sum_assure : null,
            'gender' => $this->gender,
            'requireMedical' => (bool) $this->require_medical,
            'smokerAccepted' => (bool) $this->smoker_accepted,
            'preexistingExcluded' => (bool) $this->preexisting_excluded,
            'occupationClasses' => $this->occupation_classes ?? [],
            'notes' => $this->notes ?? '',
            'active' => (bool) $this->active,
        ];
    }
}
