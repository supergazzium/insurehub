<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Policy;
use App\Services\Commission\CommissionEngine;
use Illuminate\Support\Facades\Log;

/**
 * Phase 7b — auto-reverse commissions when a policy transitions to
 * `cancelled`. Runs on `updated` (post-save), checks the original vs.
 * new status, then asks the engine to emit reversal txns.
 */
class PolicyObserver
{
    public function __construct(private readonly CommissionEngine $engine)
    {
    }

    public function updated(Policy $policy): void
    {
        if (! $policy->wasChanged('status')) {
            return;
        }
        if ($policy->status !== 'cancelled') {
            return;
        }
        try {
            $this->engine->reverseFor($policy, 'policy.cancelled');
        } catch (\Throwable $e) {
            Log::error('Commission reversal failed on policy cancellation', [
                'policy_id' => $policy->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
