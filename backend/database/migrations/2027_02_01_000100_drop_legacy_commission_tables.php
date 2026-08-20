<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Destructive: drops the legacy commission engine's tables and columns.
     *
     * Being replaced by the MGM commission system (see the design conversation
     * with the operator). Nothing is live in production; all five affected
     * tables + the four policies.main_com_rate_* columns are safe to drop.
     *
     * Dropped:
     *   - commission_transactions          (ledger)
     *   - commission_run_transactions      (many-to-many between runs and txns)
     *   - commission_runs                  (per-policy accrual groupings)
     *   - referral_bonus_configs           (unused referral bonus)
     *   - agent_payouts                    (settlement batches)
     *   - product_commission_rate_installments (rate lookup, tall shape)
     *   - product_commission_rates         (rate lookup, wide shape)
     *   - policies.main_com_rate_inh / main_com_amt_inh
     *     / main_com_rate_ag / main_com_amt_ag  (per-policy overrides)
     *
     * The new tables come with PR-A2 (commission_tiers), PR-A3 (product_types),
     * PR-A4 (carrier_product_type_rates), PR-D (commission_ledgers).
     * Life-side rates use PR #5's product_life_rates (kept open).
     *
     * `email_templates` stays — it was created in the same migration but is
     * unrelated to commissions.
     */
    public function up(): void
    {
        // FK order: children before parents.
        Schema::dropIfExists('commission_run_transactions');
        Schema::dropIfExists('commission_runs');
        Schema::dropIfExists('commission_transactions');
        Schema::dropIfExists('agent_payouts');
        Schema::dropIfExists('referral_bonus_configs');
        Schema::dropIfExists('product_commission_rate_installments');
        Schema::dropIfExists('product_commission_rates');

        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn([
                'main_com_rate_inh',
                'main_com_amt_inh',
                'main_com_rate_ag',
                'main_com_amt_ag',
            ]);
        });
    }

    /**
     * Intentionally not reversible. The MGM rewrite deletes the old engine
     * entirely; recreating these tables would leave the codebase referencing
     * models that no longer exist.
     *
     * If a rollback is truly needed, restore from a pre-migration database
     * dump — do not attempt to re-run this migration in reverse.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'This migration is not reversible. Restore from a pre-migration DB dump instead.'
        );
    }
};
