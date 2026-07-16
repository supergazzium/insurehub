<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CarrierBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CarrierBankAccount
 */
class CarrierBankAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'carrierId' => (string) $this->carrier_id,
            'bankId' => $this->bank_id !== null ? (string) $this->bank_id : null,
            'bankName' => $this->bank_name ?? '',
            'branch' => $this->branch ?? '',
            'accountNo' => $this->account_no ?? '',
            'accountName' => $this->account_name ?? '',
            'isPrimary' => (bool) $this->is_primary,
            'sortOrder' => (int) $this->sort_order,
            'active' => (bool) $this->active,
        ];
    }
}
