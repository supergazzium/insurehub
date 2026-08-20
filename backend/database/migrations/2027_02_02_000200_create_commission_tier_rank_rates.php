<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-(tier × rank) mgmt fee + referral fee. This IS the table the engine
     * (PR-D onward) reads to compute:
     *   - The seller's own mgmt fee → added to their DIRECT_COMMISSION
     *   - The referral fee → paid to seller's direct upline
     *   - Each upline's mgmt_fee for the differential walk
     *
     * 30 rows per tenant (3 tiers × 10 ranks). Seeded with the exact numbers
     * from Sheet2 of the source Excel. Admin can edit any cell.
     *
     * Effective-dating columns (valid_start / valid_end) are here now for
     * future revision support. The current UI is save-in-place; when the
     * revision workflow ships (Phase later), it'll insert new rows with a
     * new valid_start and auto-close the old row.
     *
     * Rate snapshotting on the ledger (PR-D's commission_ledgers.rate_applied)
     * means tier edits don't rewrite historical payouts.
     */
    public function up(): void
    {
        Schema::create('commission_tier_rank_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tier_id')->constrained('commission_tiers')->cascadeOnDelete();
            $table->foreignId('rank_id')->constrained('ranks')->cascadeOnDelete();
            // Same units as the old engine's rates: decimal 0..1 (0.0675 = 6.75%).
            // Zero is a valid stored value ("this tier×rank pays no mgmt fee").
            $table->decimal('mgmt_fee_rate', 8, 5);
            $table->decimal('referral_fee_rate', 8, 5);
            $table->date('valid_start')->nullable();
            $table->date('valid_end')->nullable();
            $table->timestamps();

            // One active row per (tier, rank, valid_start). Nulls in
            // valid_start behave as distinct on MySQL, so two "no-effective-date"
            // rows for the same (tier, rank) would slip past — enforced by
            // the seeder using updateOrCreate keyed on (tier, rank) instead.
            $table->unique(['tier_id', 'rank_id', 'valid_start'], 'ctrr_tier_rank_start_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_tier_rank_rates');
    }
};
