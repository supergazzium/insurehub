<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PolicyPayment;
use App\Services\Policy\InstallmentSchedule;
use Illuminate\Support\Facades\Log;

/**
 * Keeps ตารางงวดชำระ in step with the money: any payment created, edited or
 * deleted re-derives amount_paid / status for the policy's งวด. Separate from
 * PolicyPaymentObserver (the MGM commission pipeline) so neither can break
 * the other, and a failure here never blocks recording the payment.
 */
class PolicyPaymentInstallmentObserver
{
    public function created(PolicyPayment $payment): void
    {
        $this->reallocate($payment);
    }

    public function updated(PolicyPayment $payment): void
    {
        if ($payment->wasChanged(['amount', 'payment_date', 'policy_installment_id'])) {
            $this->reallocate($payment);
        }
    }

    public function deleted(PolicyPayment $payment): void
    {
        $this->reallocate($payment);
    }

    private function reallocate(PolicyPayment $payment): void
    {
        try {
            $policy = $payment->policy()->first();
            if ($policy !== null) {
                app(InstallmentSchedule::class)->reallocate($policy);
            }
        } catch (\Throwable $e) {
            Log::warning('Installment reallocation failed', [
                'payment_id' => $payment->id,
                'policy_id' => $payment->policy_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
