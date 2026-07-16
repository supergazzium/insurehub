<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\ProductKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean list-row shape for /api/v1/products index.
 * Joins carrier code + name so no follow-up lookup is needed.
 *
 * @mixin \stdClass
 */
class ProductListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'commissionCode' => $this->commission_code ?? '',
            'name' => $this->name,
            'nameEn' => $this->name_en ?? '',
            'carrierId' => (string) $this->carrier_id,
            'carrierCode' => $this->carrier_code,
            'carrierName' => $this->carrier_name,
            'type' => $this->type ?? '',
            'category' => $this->category ?? '',
            'subCategory' => $this->sub_category ?? '',
            'subCategory2' => $this->sub_category_2 ?? '',
            'mainRider' => $this->main_rider ?? '',
            'productKind' => ProductKind::derive($this->type ?? '', $this->category ?? '', $this->sub_category_2 ?? ''),
            'minAge' => (int) $this->min_age,
            'maxAge' => (int) $this->max_age,
            'minSumAssure' => $this->min_sum_assure !== null ? (float) $this->min_sum_assure : null,
            'maxSumAssure' => $this->max_sum_assure !== null ? (float) $this->max_sum_assure : null,
            'validStart' => $this->valid_start,
            'validEnd' => $this->valid_end,
            'active' => (bool) $this->active,
        ];
    }

}
