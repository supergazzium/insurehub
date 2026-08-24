<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Policy;
use App\Models\ProductCommissionRate;
use App\Services\Commission\CommissionSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * C-20: Freeze the product's commission basis onto the policy at creation.
 *
 * Fires on `created` for EVERY policy-creation path (store, storeDraft, and
 * any future path) exactly once. Capturing here — rather than in each
 * controller method — guarantees a single, consistent freeze point and can't
 * be forgotten by a new endpoint.
 *
 * The snapshot is what "commission for this new policy" means: the rate in
 * force the moment the operator creates the policy. Later edits to the
 * product's commission never touch already-created policies because the
 * resolvers prefer this frozen copy over the live product tables.
 *
 * Draft policies are snapshotted too: the operator's creation moment is when
 * they start the policy. A re-snapshot action (e.g. "refresh commission from
 * product") can be added later if the business wants drafts to track live
 * rates until promotion.
 *
 * Idempotent + defensive: skips when no product is attached or the product
 * carries no commission rows (nothing to freeze → resolvers fall back to
 * live, which is correct). Never throws into the create transaction — a
 * snapshot failure must not block policy creation; it degrades to live
 * resolution and logs.
 */
class PolicyObserver
{
    public function created(Policy $policy): void
    {
        if ($policy->product_id === null || $policy->commission_snapshot !== null) {
            return;
        }

        try {
            $product = $policy->product()->first();
            if ($product === null) {
                return;
            }

            $snapshot = CommissionSnapshot::capture($product);
            if ($snapshot === null) {
                return; // product has no commission basis to freeze
            }

            $policy->commission_snapshot = $snapshot;

            // Seed the editable per-policy commission (both directions) from the
            // resolved headline rate — but only where the operator hasn't
            // already supplied an override on the create request. Amount is
            // rate x net premium (year 1). The operator can edit these later;
            // when set, the accrual engine prefers them over the product rate.
            $reader = CommissionSnapshot::fromPolicy($policy);
            if ($reader !== null) {
                $sumAssured = (float) $policy->coverage;
                $entryAge = $this->entryAge($policy);
                $year = max(1, (int) $policy->policy_year);
                $premium = (float) ($policy->net_premium ?? 0);

                $h2a = $reader->headlineRate(ProductCommissionRate::DIRECTION_HUB_TO_AGENT, $sumAssured, $entryAge, $year);
                $c2h = $reader->headlineRate(ProductCommissionRate::DIRECTION_CARRIER_TO_HUB, $sumAssured, $entryAge, $year);

                if ($policy->comm_hub_to_agent_rate === null && $h2a !== null) {
                    $policy->comm_hub_to_agent_rate = $h2a;
                    $policy->comm_hub_to_agent_amount = round($premium * $h2a, 2);
                }
                if ($policy->comm_carrier_to_hub_rate === null && $c2h !== null) {
                    $policy->comm_carrier_to_hub_rate = $c2h;
                    $policy->comm_carrier_to_hub_amount = round($premium * $c2h, 2);
                }
            }

            // updateQuietly: persist without re-firing observers (no infinite
            // loop, and no spurious `updated` event on a brand-new row).
            $policy->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('Commission snapshot capture failed on policy.created', [
                'policy_id' => $policy->id,
                'product_id' => $policy->product_id,
                'error' => $e->getMessage(),
            ]);
            // Fall through — policy stays created, resolves commission live.
        }
    }

    /**
     * Insured entry age = effective_year - birth_year. Null when either date
     * is missing (unbounded-age bands still match). Mirrors LifeRateResolver.
     */
    private function entryAge(Policy $policy): ?int
    {
        $birth = $policy->insured_person_birth_date;
        $effective = $policy->effective_date;
        if ($birth === null || $effective === null) {
            return null;
        }

        return max(0, (int) $birth->diffInYears($effective));
    }
}
