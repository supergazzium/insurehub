<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contract
 */
class ContractResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'contractNo' => $this->contract_no,
            'carrierId' => (string) $this->carrier_id,
            'effectiveFrom' => $this->effective_from?->toDateString() ?? '',
            'effectiveTo' => $this->effective_to?->toDateString(),
            'schedule' => $this->whenLoaded(
                'scheduleRows',
                fn () => $this->scheduleRows->map(fn ($row) => [
                    'productId' => (string) $row->product_id,
                    'firstYearRate' => (float) $row->first_year_rate,
                    'renewalRate' => (float) $row->renewal_rate,
                ]),
                fn () => [],
            ),
            'notes' => $this->notes ?? '',
            'active' => (bool) $this->active,
        ];
    }
}
