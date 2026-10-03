<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolicyInstallment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PolicyInstallment
 */
class PolicyInstallmentResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'policyId' => (string) $this->policy_id,
            'installmentNo' => (int) $this->installment_no,
            'installmentTotal' => (int) $this->installment_total,
            'label' => $this->installment_no.'/'.$this->installment_total,
            'dueDate' => $this->due_date?->toDateString(),
            'amountDue' => (float) $this->amount_due,
            'amountPaid' => (float) $this->amount_paid,
            'outstanding' => round(max(0, (float) $this->amount_due - (float) $this->amount_paid), 2),
            'status' => $this->status,
            'paidDate' => $this->paid_date?->toDateString(),
        ];
    }
}
