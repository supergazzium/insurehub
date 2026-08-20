<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductType
 */
class ProductTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'nameTh' => $this->name_th,
            'nameEn' => $this->name_en,
            'subOf' => $this->sub_of,
            'tierId' => (string) $this->tier_id,
            'tierCode' => $this->tier?->code,
            'tierNameTh' => $this->tier?->name_th,
            'sortOrder' => (int) $this->sort_order,
            'active' => (bool) $this->active,
            'notes' => $this->notes,
        ];
    }
}
