<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add entry-age brackets to product_commission_rates so one Life
        // product (ประเภทสามัญ — Whole Life / Endowment / Annuity / Term)
        // can carry per-age-bracket × per-year rate tables:
        //
        //   Age 1-15   → Y1 25% / Y2 5% / Y3 5% / Y4 3% / Y5 1% / Y6+ 1%
        //   Age 16-50  → Y1 35% / Y2 5% / Y3 5% / ...
        //   Age 51-65  → Y1 30% / Y2 5% / Y3 5% / ...
        //
        // Both bounds nullable — NULL = unbounded on that side. A row with
        // both NULL is the "any age" fallback and matches the pre-existing
        // behavior of every row today.
        //
        // Rate resolution (CommissionEngine::fetchWideRates()): use the
        // policy's insured age at issue against min_age/max_age; specific
        // brackets win over the (both-null) fallback.
        Schema::table('product_commission_rates', function (Blueprint $table): void {
            $table->unsignedTinyInteger('min_age')->nullable()->after('valid_end');
            $table->unsignedTinyInteger('max_age')->nullable()->after('min_age');
        });

        // Support index — the engine will look up by (product_id, age).
        Schema::table('product_commission_rates', function (Blueprint $table): void {
            $table->index(['product_id', 'min_age', 'max_age'], 'pcr_product_age_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_commission_rates', function (Blueprint $table): void {
            $table->dropIndex('pcr_product_age_idx');
            $table->dropColumn(['min_age', 'max_age']);
        });
    }
};
