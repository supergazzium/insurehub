<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PolicyPayment;
use App\Services\Commission\CommissionEngine;
use Illuminate\Support\Facades\Log;

/**
 * On payment create, ask the CommissionEngine to accrue commission. We swallow
 * any exception + log — a broken commission run must not block payment recording
 * (an admin can re-trigger accrual via /commissions/reaccrue-payment later).
 */
class PolicyPaymentObserver
{
    public function __construct(private readonly CommissionEngine $engine)
    {
    }

    public function created(PolicyPayment $payment): void
    {
        try {
            $this->engine->accrueForPayment($payment);
        } catch (\Throwable $e) {
            Log::error('Commission accrual failed on payment.created', [
                'payment_id' => $payment->id,
                'policy_id' => $payment->policy_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
