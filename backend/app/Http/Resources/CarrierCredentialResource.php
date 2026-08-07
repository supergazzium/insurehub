<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CarrierCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Portal login credential for a carrier — URL / username / password / label.
 * Password is emitted decrypted; the caller (drawer) masks by default and
 * has a show/copy toggle.
 *
 * @mixin CarrierCredential
 */
class CarrierCredentialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'carrierId' => (string) $this->carrier_id,
            'url' => $this->url ?? '',
            'username' => $this->username ?? '',
            'password' => $this->password ?? '',
            'label' => $this->label ?? '',
            'sortOrder' => (int) $this->sort_order,
        ];
    }
}
