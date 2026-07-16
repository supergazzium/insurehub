<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Carrier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean list-row shape for /api/v1/carriers index.
 *
 * @mixin Carrier
 */
class CarrierListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'nameEn' => $this->name_en ?? '',
            'nicknameTh' => $this->nickname_th ?? '',
            'insureType' => $this->insure_type ?? '',
            'subType' => $this->sub_type ?? '',
            'oicInsureComCode' => $this->oic_insure_com_code ?? '',
            'compInsureCode' => $this->comp_insure_code ?? '',
            'taxId' => $this->tax_id ?? '',
            'phone' => $this->phone ?? '',
            'email' => $this->email ?? '',
            'website' => $this->website ?? '',
            'active' => (bool) $this->active,
            'productCount' => (int) ($this->products_count ?? 0),
            'contractCount' => (int) ($this->contracts_count ?? 0),
        ];
    }
}
