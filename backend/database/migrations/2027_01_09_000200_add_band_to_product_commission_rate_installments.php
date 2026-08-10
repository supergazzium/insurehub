<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add sum-assured band columns to product_commission_rate_installments
        // so a single product can carry rate tiers like:
        //   [200,000 – 999,999]     -> 8% agent share
        //   [1,000,000 – 4,999,999] -> 12% agent share
        //   [5,000,000 – null]      -> 18% agent share
        //
        // Both nullable; NULL = "no lower bound" / "no upper bound". A row
        // with BOTH null is the "any sum-assured" fallback and matches the
        // pre-existing behavior of every installments row today.
        //
        // Extending the unique index (product_id, party, installment_term) to
        // include min_sum_assure so two bands for the same (party, term) can
        // coexist. MySQL/Postgres treat NULLs as distinct in unique indexes,
        // so two "unbounded" rows for the same (product, party, term) would
        // slip past — matched by the seeder's replace semantics rather than
        // a DB constraint.
        Schema::table('product_commission_rate_installments', function (Blueprint $table): void {
            $table->decimal('min_sum_assure', 15, 2)->nullable()->after('installment_term');
            $table->decimal('max_sum_assure', 15, 2)->nullable()->after('min_sum_assure');
        });

        // Rebuild the unique index to include the band's lower bound. Without
        // this a second band row for the same (product, party, term) would
        // collide with the first on the existing unique key. Named to fit the
        // existing convention (pcri_*).
        Schema::table('product_commission_rate_installments', function (Blueprint $table): void {
            $table->dropUnique('pcri_prod_party_term_unique');
            $table->unique(
                ['product_id', 'party', 'installment_term', 'min_sum_assure'],
                'pcri_prod_party_term_band_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('product_commission_rate_installments', function (Blueprint $table): void {
            $table->dropUnique('pcri_prod_party_term_band_unique');
            $table->unique(
                ['product_id', 'party', 'installment_term'],
                'pcri_prod_party_term_unique',
            );
            $table->dropColumn(['min_sum_assure', 'max_sum_assure']);
        });
    }
};
