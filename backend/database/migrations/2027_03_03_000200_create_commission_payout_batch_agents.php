<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent roll-up + payment tracking WITHIN a payout batch.
 *
 * A batch spans 30–50 agents (one monthly run). Each agent is a separate bank
 * transfer, so payment state is tracked here per agent — not once per batch:
 *   - one `payment_reference` (transfer id) per agent,
 *   - a `paid` flag so staff can mark agents paid as the transfers clear,
 *   - `pdf_generated_at` so the UI can show which statements have been produced.
 *
 * The batch reaches PAID only once every non-excluded agent row is `paid`
 * (enforced in CommissionPayoutService). The payout DATE is still batch-level
 * (one payment run); only the reference/paid flag are per agent.
 *
 * Roll-up amounts are frozen from the item snapshot at batch creation so the
 * agent list, the PDF, and reconciliation all read the same numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payout_batch_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('commission_payout_batches')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            // Denormalised identity + bank so the row survives later master edits.
            $table->string('agent_code', 32)->nullable();
            $table->string('agent_name')->nullable();
            $table->string('vat_type', 16)->nullable();
            $table->string('bank_account_no', 64)->nullable();
            $table->string('bank_account_name')->nullable();
            // Frozen roll-ups (Σ over this agent's non-excluded items in the batch).
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('gross_commission', 15, 2)->default(0);  // Σ main + rider
            $table->decimal('deduct_amount', 15, 2)->default(0);     // Σ adjustments + payoff/card
            $table->decimal('wht_amount', 15, 2)->default(0);        // Σ หัก ณ ที่จ่าย 3%
            $table->decimal('net_transfer', 15, 2)->default(0);      // ยอดเงินโอน
            // Per-agent payment state.
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference', 128)->nullable();    // per-agent transfer id
            $table->timestamp('pdf_generated_at')->nullable();
            // Reversal (wrong payment) at the per-agent grain.
            $table->boolean('reversed')->default(false);
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            // One row per agent per batch; supports the list + pay lookups.
            $table->unique(['batch_id', 'agent_id'], 'cpba_batch_agent_unique');
            $table->index(['batch_id', 'paid'], 'cpba_batch_paid_idx');
            $table->index(['tenant_id', 'agent_id'], 'cpba_tenant_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payout_batch_agents');
    }
};
