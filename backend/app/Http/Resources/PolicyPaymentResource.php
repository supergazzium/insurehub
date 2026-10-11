<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolicyPayment;
use App\Support\PolicyPeriodLabel;
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
        $policy = $this->policy;
        $inst = $this->policy_installment_id !== null
            ? $this->installment
            : ($policy !== null ? PolicyPeriodLabel::installmentCoveredBy($this->resource, $policy) : null);

        return [
            'id' => (string) $this->id,
            'policyId' => (string) $this->policy_id,
            'policyInstallmentId' => $this->policy_installment_id !== null ? (string) $this->policy_installment_id : null,
            'installmentNo' => $inst !== null ? (int) $inst->installment_no : null,
            'periodLabel' => $policy !== null ? PolicyPeriodLabel::format(
                (int) $policy->policy_year,
                PolicyPeriodLabel::endorsementNo($policy->id, $this->payment_date),
                $inst !== null ? (int) $inst->installment_no : null,
                $inst !== null ? (int) $inst->installment_total : null,
            ) : null,
            'paymentDate' => $this->payment_date?->toDateString() ?? '',
            'amount' => (float) $this->amount,
            'netAmount' => $this->net_amount !== null ? (float) $this->net_amount : null,
            'dutyAmount' => $this->duty_amount !== null ? (float) $this->duty_amount : null,
            'vatAmount' => $this->vat_amount !== null ? (float) $this->vat_amount : null,
            'adjustmentAmount' => (float) $this->adjustment_amount,
            'adjustmentKind' => $this->adjustment_kind,
            'creditAmount' => $this->creditAmount(),
            'method' => $this->method,
            'payee' => $this->payee,
            'payMode' => $this->pay_mode,
            'reference' => $this->reference ?? '',
            'note' => $this->note ?? '',
            'recordedByUserId' => $this->recorded_by_user_id !== null ? (string) $this->recorded_by_user_id : null,
            'recordedByName' => $this->user?->name,
            'recordedAt' => $this->created_at?->toIso8601String(),
            'proof' => $this->proof !== null
                ? ['documentId' => (string) $this->proof->id, 'fileName' => $this->proof->file_name]
                : null,
            'voidedAt' => $this->voided_at?->toIso8601String(),
            'voidedByName' => $this->voidedBy?->name,
            'voidReason' => $this->void_reason,
            'paymentInscompToId' => $this->payment_inscomp_to_id !== null ? (string) $this->payment_inscomp_to_id : null,
            'paymentInscompStatusId' => $this->payment_inscomp_status_id !== null ? (string) $this->payment_inscomp_status_id : null,
            'countSlip' => $this->count_slip !== null ? (int) $this->count_slip : null,
            'validateAmount' => $this->validate_amount ?? '',
        ];
    }
}
