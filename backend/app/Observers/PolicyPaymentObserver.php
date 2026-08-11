<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PolicyPayment;
use App\Services\Commission\VolumeAccumulator;
use Illuminate\Support\Facades\Log;

/**
 * Fires the MGM volume accumulator on every new payment.
 *
 * This is the same observer name as the one deleted in PR-0. The old
 * observer called CommissionEngine::accrueForPayment (deleted).
 * This one calls VolumeAccumulator::accumulateForPayment. When PR-D
 * lands, this observer will also fire the MgmCommissionEngine.
 *
 * Wired via #[ObservedBy] on the PolicyPayment model — but that
 * attribute was removed in PR-0. Re-adding it here.
 *
 * Failures are logged but non-fatal — a payment must succeed even if
 * the volume observer crashes. Nightly reconciliation
 * (`mgm:reconcile-volumes`) will catch anything the observer missed.
 */
class PolicyPaymentObserver
{
    public function __construct(private readonly VolumeAccumulator $accumulator) {}

    public function created(PolicyPayment $payment): void
    {
        try {
            $this->accumulator->accumulateForPayment($payment);
        } catch (\Throwable $e) {
            Log::error('MGM volume accumulation failed on payment.created', [
                'payment_id' => $payment->id,
                'policy_id' => $payment->policy_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
