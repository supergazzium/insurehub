<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MGM commission tiers — the three named tiers from Sheet2 of the source
     * Excel (Blue / Yellow / Red). Each product-type maps to exactly one tier
     * (via product_types.tier_id, created in PR-A3). The tier defines the
     * mgmt fee curve and referral fee that the MGM engine (PR-D) applies.
     *
     * Fixed at 3 rows per tenant. Admin can rename (name_th / name_en / notes)
     * but not add/delete — the number of tiers is baked into the business
     * model. Seeding is done by CommissionTierSeeder.
     *
     * Sibling table commission_tier_rank_rates (next migration) holds the
     * per-rank rate curve for each tier.
     */
    public function up(): void
    {
        Schema::create('commission_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);          // 'TIER_FULL', 'TIER_PARTIAL', 'TIER_DIRECT_ONLY'
            $table->string('name_th', 64);       // display, admin-editable
            $table->string('name_en', 64);
            // Display metadata for the admin UI (badge colour, sort order).
            // No engine behaviour depends on these.
            $table->string('color_hex', 7)->nullable();  // '#3B82F6' etc.
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            // (tenant, code) uniquely identifies a tier. Prevents duplicate
            // TIER_FULL rows per tenant.
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_tiers');
    }
};
