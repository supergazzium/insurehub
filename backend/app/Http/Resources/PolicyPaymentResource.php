<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolicyPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PolicyPayment
 */
class PolicyPaymentResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'policyId' => (string) $this->policy_id,
            'paymentDate' => $this->payment_date?->toDateString() ?? '',
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'reference' => $this->reference ?? '',
            'recordedByUserId' => $this->recorded_by_user_id !== null ? (string) $this->recorded_by_user_id : null,
            // Fields populated by the legacy importer.
            'paymentInscompToId' => $this->payment_inscomp_to_id !== null ? (string) $this->payment_inscomp_to_id : null,
            'paymentInscompStatusId' => $this->payment_inscomp_status_id !== null ? (string) $this->payment_inscomp_status_id : null,
            'countSlip' => $this->count_slip !== null ? (int) $this->count_slip : null,
            'validateAmount' => $this->validate_amount ?? '',
        ];
    }
}
