<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'nameEn' => $this->name_en,
            'taxId' => $this->tax_id,
            'oicLicense' => $this->oic_license,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'address' => $this->address,
            'district' => $this->district,
            'amphoe' => $this->amphoe,
            'province' => $this->province,
            'postcode' => $this->postcode,
            'commissionMode' => $this->commission_mode,
            'payout' => [
                'cycle' => $this->payout_cycle,
                'minBalance' => (float) $this->payout_min_balance,
                'autoApprove' => (bool) $this->payout_auto_approve,
            ],
            'brandColor' => $this->brand_color,
            'emailSignature' => $this->email_signature,
            'active' => (bool) $this->active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
