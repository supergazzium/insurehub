<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * commission_ledgers — itemized payout records for the MGM engine.
     *
     * One row per (payment, payout_type, beneficiary). A single premium
     * payment can produce N rows:
     *   - 1× DIRECT_COMMISSION for the writing agent
     *   - 1× REFERRAL_FEE for the seller's direct upline (PR-E)
     *   - N× MANAGEMENT_DIFFERENTIAL, one per upline in the chain (PR-F)
     *
     * Rates are SNAPSHOTTED at accrual time — admin editing tier rates or
     * matrix cells later does NOT rewrite historical rows. This is the
     * whole point of the ledger vs. computing on the fly.
     *
     * idempotency_key = "payment:{payment_id}:{payout_type}:{beneficiary_id}"
     * so replays (retried job, admin recompute) never double-count.
     */
    public function up(): void
    {
        Schema::create('commission_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // What kind of payout this is. Matches your MGM design spec:
            //   DIRECT_COMMISSION       — paid to the seller (this PR)
            //   REFERRAL_FEE            — paid to seller's direct upline (PR-E)
            //   MANAGEMENT_DIFFERENTIAL — paid to an upline in the chain (PR-F)
            //   BREAKOFF_OVERRIDE       — reserved, not built (spec incomplete)
            $table->string('payout_type', 32);

            // Who gets paid. Always set; PR-0's old engine had InH rows with
            // null agent_id, but MGM's DIRECT/REFERRAL/DIFFERENTIAL are all
            // agent-scoped.
            $table->foreignId('beneficiary_agent_id')->constrained('agents')->cascadeOnDelete();

            // Which policy + payment this row is for.
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('policy_payment_id')->constrained('policy_payments')->cascadeOnDelete();

            // For DIFFERENTIAL: the downline whose sale generated this payout.
            // (Same as writing_agent for DIRECT/REFERRAL.) Kept explicit so
            // reports can answer "how much did agent X earn from downline Y?"
            $table->foreignId('source_agent_id')->nullable()->constrained('agents')->nullOnDelete();

            // Base amount the rate is applied to (policy_payments.amount).
            // Stored for auditability — recomputing amount from rate+base
            // must reproduce this row's amount within rounding.
            $table->decimal('base_premium', 15, 2);
            $table->decimal('rate_applied', 8, 5);     // e.g. 0.09000, 0.02500
            $table->decimal('amount', 15, 2);          // = base_premium × rate_applied

            // Rate-source snapshot. For DIRECT the "standard rate" comes from
            // either the carrier×type matrix (non-life) or product_life_rates
            // (Life, PR-H later). Store both to keep the audit self-describing.
            $table->decimal('standard_rate', 8, 5)->nullable();
            $table->decimal('mgmt_fee_rate', 8, 5)->nullable();
            // Which tier / rank the mgmt fee came from. Non-normative history.
            $table->foreignId('tier_id')->nullable()->constrained('commission_tiers')->nullOnDelete();
            $table->foreignId('rank_id_at_accrual')->nullable()->constrained('ranks')->nullOnDelete();

            // Payer — INSURER (rate portion the insurer absorbs) or BROKER
            // (rate portion the broker firm absorbs). MGM engine currently
            // records everything as INSURER; the insurer/broker split is a
            // future refinement per the source Excel columns
            // paid_by_insurer_rate / paid_by_broker_rate.
            $table->string('payer_source', 16)->default('INSURER');

            // Idempotency + workflow state.
            $table->string('idempotency_key')->unique();
            $table->string('status', 16)->default('unsettled');  // unsettled | settled | reversed
            // For clawback / reversal rows: FK to the original ledger row
            // this reversal cancels. amount is negated.
            $table->foreignId('reverses_ledger_id')->nullable()->constrained('commission_ledgers')->nullOnDelete();

            $table->timestamps();

            // Reporting indexes — the two hot queries are "everything for a
            // policy" and "everything for a beneficiary within a period".
            $table->index(['tenant_id', 'beneficiary_agent_id', 'created_at']);
            $table->index(['policy_id', 'payout_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledgers');
    }
};
