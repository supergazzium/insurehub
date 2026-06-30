<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CarrierContactGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CarrierContactGroup
 */
class CarrierContactGroupResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'carrierCode' => $this->carrier?->code ?? '',
            'name' => $this->name,
            'emails' => $this->emails ?? [],
            'department' => $this->department,
            'insuranceTypes' => $this->insurance_types ?? [],
            'isDefault' => (bool) $this->is_default,
            'notes' => $this->notes,
            'active' => (bool) $this->active,
        ];
    }
}
