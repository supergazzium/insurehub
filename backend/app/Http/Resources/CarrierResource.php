<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Carrier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Carrier
 */
class CarrierResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'nameEn' => $this->name_en ?? '',
            'nicknameTh' => $this->nickname_th ?? '',
            'type' => $this->insure_type ?? '',
            'subType' => $this->sub_type ?? '',
            'compInsureCode' => $this->comp_insure_code ?? '',
            'oicInsureComCode' => $this->oic_insure_com_code ?? '',
            'oicLicense' => $this->oic_license ?? '',
            'taxId' => $this->tax_id ?? '',
            'phone' => $this->phone ?? '',
            'email' => $this->email ?? '',
            'website' => $this->website ?? '',
            'address' => $this->address ?? '',
            'logoUrl' => $this->logo_url,
            'productCount' => (int) ($this->products_count ?? $this->products()->count()),
            'contractCount' => (int) ($this->contracts_count ?? $this->contracts()->count()),
            'since' => $this->since ?? '',
            'active' => (bool) $this->active,
        ];
    }
}
