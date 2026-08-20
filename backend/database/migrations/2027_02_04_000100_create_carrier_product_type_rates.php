<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MGM base commission rates — the (carrier × product-type) matrix from
     * Sheet2 rows 54-79 of the source Excel.
     *
     * This IS the source of truth for the "standard commission" that non-life
     * products earn. The MGM engine (PR-D):
     *   1. Looks up product → product_type → tier (mgmt fee curve)
     *   2. Looks up (product.carrier, product.product_type) here → standard_rate
     *   3. Combines: DIRECT_COMMISSION = premium × (standard_rate + seller_mgmt_fee)
     *
     * Life products use PR #5's product_life_rates for their standard rate
     * instead of this matrix. The dispatcher in PR-D routes based on
     * carrier.insure_type.
     *
     * Nullable standard_rate = "carrier doesn't sell this product-type" (the
     * "-" cells in the Excel). Query time: null standard_rate means the
     * engine can't accrue commission for that (carrier, type) pair — surface
     * as a warning to admin rather than silently paying zero.
     *
     * Effective-dated. The admin grid UI is save-in-place for now; revision
     * workflow (add a new row with a new valid_start, auto-close the old) is
     * deferred to a later phase.
     */
    public function up(): void
    {
        Schema::create('carrier_product_type_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $table->foreignId('product_type_id')->constrained('product_types')->cascadeOnDelete();
            $table->decimal('standard_rate', 8, 5)->nullable();
            $table->date('valid_start')->nullable();
            $table->date('valid_end')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            // One active row per (tenant, carrier, product-type, valid_start).
            // MySQL treats NULL as distinct in unique indexes, so two
            // "no-effective-date" rows for the same (carrier, type) can
            // technically coexist — enforced by the seeder using
            // updateOrCreate keyed on (tenant, carrier, type) instead.
            $table->unique(
                ['tenant_id', 'carrier_id', 'product_type_id', 'valid_start'],
                'cptr_tenant_carrier_type_start_unique'
            );

            // Engine lookup path — (carrier, type) is the hot query.
            $table->index(['tenant_id', 'carrier_id', 'product_type_id'], 'cptr_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_product_type_rates');
    }
};
