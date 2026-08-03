<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CarrierContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CarrierContact
 */
class CarrierContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'carrierId' => (string) $this->carrier_id,
            'firstName' => $this->first_name ?? '',
            'lastName' => $this->last_name ?? '',
            'phone' => $this->phone ?? '',
            'email' => $this->email ?? '',
            'isPrimary' => (bool) $this->is_primary,
            'sortOrder' => (int) $this->sort_order,
            'active' => (bool) $this->active,
        ];
    }
}
