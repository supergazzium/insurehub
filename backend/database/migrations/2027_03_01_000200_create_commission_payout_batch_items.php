<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen snapshot of the policies that entered a payout batch, one row per
 * eligible policy. Amounts are copied in at batch creation and never
 * recomputed — the batch total, the PDF, and the reconciliation all read
 * from here (spec §12 "Source of Truth = Batch Snapshot").
 *
 * Amount resolution order at snapshot time:
 *   1. policy_rebates.actual_agent_amount   (if a rebate row exists)
 *   2. policy_rebates.calculated_agent_amount
 *   3. policies.comm_hub_to_agent_amount (+ rider com_amt_ag)
 *   4. 0  (flagged in preview as "no commission amount")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payout_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('commission_payout_batches')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            // Denormalised so the snapshot survives later master edits.
            $table->string('agent_code', 32)->nullable();
            $table->string('vat_type', 16)->nullable();      // 1/2/3 (from agents.vat_type)
            // Frozen amounts.
            $table->decimal('snapshot_base_premium', 15, 2)->default(0);
            $table->decimal('snapshot_agent_commission', 15, 2)->default(0); // main policy leg
            $table->decimal('snapshot_rider_commission', 15, 2)->default(0); // Σ rider com_amt_ag
            $table->string('amount_source', 24)->nullable(); // which fallback produced the amount
            // pending → paid (set on mark-paid) | excluded (manually dropped from batch).
            $table->string('item_status', 16)->default('pending');
            // The policy_rebates.agent_rebate_status BEFORE this batch touched it (for reversal).
            $table->string('original_agent_rebate_status', 32)->nullable();
            $table->timestamps();

            // A policy may only sit in one non-cancelled batch at a time — enforced
            // in the service; index supports that lookup + the detail grid.
            $table->index(['batch_id', 'agent_id'], 'cpbi_batch_agent_idx');
            $table->index(['tenant_id', 'policy_id'], 'cpbi_tenant_policy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payout_batch_items');
    }
};
