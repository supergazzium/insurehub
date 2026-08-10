<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Life products often carry rate tables that vary along THREE axes at
        // once: entry-age bracket × sum-assured bracket × policy year × party.
        //
        // Existing tables can only express two of the three dimensions:
        //   - product_commission_rates: age × year × party (via the age-year
        //     shape from PR #4). No sum-assured axis.
        //   - product_commission_rate_installments: sum-assured × installment
        //     × party. No age or year axis.
        //
        // This table holds one row per (age-bracket, sum-assured-bracket) pair
        // for a Life product; the sibling product_life_rates table (created in
        // the next migration) holds the year × party × rate cells that hang
        // off it. See the ProductRateSeeder::seedLifeMatrix() docblock for
        // the full data model.
        //
        // All four bracket bounds are nullable — NULL means unbounded on that
        // side. A row with all four NULL is the "any age, any sum" fallback.
        Schema::create('product_life_rate_dimensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->decimal('min_sum_assure', 15, 2)->nullable();
            $table->decimal('max_sum_assure', 15, 2)->nullable();

            // Effective-dating stays here rather than on product_life_rates —
            // when a product is repriced, both the bracket definitions AND
            // their rates change together, so it's a per-dimension concern.
            $table->date('valid_start')->nullable();
            $table->date('valid_end')->nullable();

            $table->timestamps();

            // Support index for engine lookup (find the dimension whose
            // brackets cover the policy's insured age + sum-assured).
            $table->index(['product_id', 'min_age', 'max_age'], 'plrd_product_age_idx');
            $table->index(['product_id', 'min_sum_assure', 'max_sum_assure'], 'plrd_product_sum_idx');

            // No unique constraint on (product, min_age, min_sum_assure,
            // valid_start): MySQL/Postgres treat NULLs as distinct in unique
            // indexes, which would let two "any bracket" fallbacks coexist.
            // Replace semantics in the seeder prevent duplicates instead.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_life_rate_dimensions');
    }
};
