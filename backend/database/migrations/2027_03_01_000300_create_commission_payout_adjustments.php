<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual per-agent adjustments on a batch — the "Special Deduct" from the
 * Access workflow (spec §5.4). Persisted as records (not a screen-only value)
 * so every deduction carries amount + reason + who + when for audit.
 * A positive amount reduces the agent's payout; type left open for future
 * bonus/correction kinds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payout_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('commission_payout_batches')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('agent_code', 32)->nullable();
            $table->string('type', 24)->default('special_deduct'); // special_deduct | bonus | correction
            $table->decimal('amount', 15, 2)->default(0);          // >= 0; deducts from payout
            $table->text('reason');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['batch_id', 'agent_id'], 'cpa_batch_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payout_adjustments');
    }
};
