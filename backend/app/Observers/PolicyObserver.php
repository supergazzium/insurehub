<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Policy;
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

            // updateQuietly: persist without re-firing observers (no infinite
            // loop, and no spurious `updated` event on a brand-new row).
            $policy->commission_snapshot = $snapshot;
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
}
