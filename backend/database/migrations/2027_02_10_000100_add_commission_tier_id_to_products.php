<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move commission-tier ownership from product_types onto the product.
     *
     * The MGM engine needs a tier per product to resolve referral_fee_rate
     * and mgmt_fee_rate. Until now, tier was reached indirectly via
     * product → product_type_id → product_types.tier_id. That meant new
     * products created without a product_type_id resolved to tier = null,
     * which zeroed REFERRAL_FEE and MANAGEMENT_DIFFERENTIAL for the
     * entire upline chain.
     *
     * Fix: commission tier is set directly on the product. product_type_id
     * remains for descriptive / reporting purposes but no longer drives
     * accrual (engine reads product's own commission_tier_id first, with
     * product-type as a safety fallback during rollout).
     *
     * Backfill: copy product_types.tier_id into products.commission_tier_id
     * for every product with a set product_type_id. Preserves existing
     * accrual behavior for the ~17 seeded scenario products and any real
     * data on the branch.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('commission_tier_id')
                ->nullable()
                ->after('product_type_id')
                ->constrained('commission_tiers')
                ->nullOnDelete();
            $table->index(['tenant_id', 'commission_tier_id'], 'products_tenant_tier_idx');
        });

        // Backfill from product_types.tier_id where product_type_id is set.
        DB::statement(<<<'SQL'
            UPDATE products p
            JOIN product_types pt ON pt.id = p.product_type_id
            SET p.commission_tier_id = pt.tier_id
            WHERE p.product_type_id IS NOT NULL
              AND p.commission_tier_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_tenant_tier_idx');
            $table->dropForeign(['commission_tier_id']);
            $table->dropColumn('commission_tier_id');
        });
    }
};
