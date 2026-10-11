<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-level fields for the formal statement + wrong-payment handling.
 *
 *   period_label            — printed month label on the PDF (e.g. "สิงหาคม 2026"),
 *                             frozen so it is stable regardless of the actual
 *                             from/to date span.
 *   reversed_*              — REVERSAL of a PAID batch (void a wrong payment):
 *                             the batch moves PAID → REVERSED, items are unmarked
 *                             paid and their agent_rebate_status is restored, and
 *                             this records who/when/why for audit. Paid financial
 *                             records are never deleted, only reversed.
 *   reissued_from_batch_id  — correction lineage: a fresh DRAFT batch created to
 *                             re-pay an agent/period after a reversal points back
 *                             at the batch it corrects.
 *
 * The status column already exists (string, 16). We add REVERSED as an allowed
 * value in the model — no schema change to the column is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_payout_batches', function (Blueprint $table): void {
            $table->string('period_label', 64)->nullable()->after('to_date');
            // Reversal (wrong-payment void).
            $table->foreignId('reversed_by_user_id')->nullable()->after('paid_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by_user_id');
            $table->text('reversal_reason')->nullable()->after('reversed_at');
            // Correction lineage — self-reference to the batch this one re-issues.
            $table->foreignId('reissued_from_batch_id')->nullable()->after('reversal_reason')
                ->constrained('commission_payout_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_payout_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_by_user_id');
            $table->dropConstrainedForeignId('reissued_from_batch_id');
            $table->dropColumn(['period_label', 'reversed_at', 'reversal_reason']);
        });
    }
};
