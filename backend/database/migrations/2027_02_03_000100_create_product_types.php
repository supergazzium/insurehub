<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MGM product-type taxonomy — the "column headers" of Sheet2's rows 54-79
     * carrier × product-type matrix (PR-A4). Every product references exactly
     * one product_type via products.product_type_id (added in the next
     * migration).
     *
     * Each product_type maps to exactly ONE commission tier (tier_id FK).
     * Admin can reassign the tier at any time. Historical policies keep
     * their historical rates via ledger snapshotting (PR-D).
     *
     * Seeded rows are the 15 non-life types from Sheet2's row 54 header +
     * a small set of Life types (WHOLE_LIFE, ENDOWMENT, ANNUITY, TERM, RIDER)
     * so Life products (which use PR #5's per-product rate table for the
     * base rate) can still be tier-assigned for MGM commissions.
     *
     * Flat taxonomy (no parent_id). If the list grows past ~30 entries, we
     * can promote `parent_code` to a proper FK — for now `sub_of` is a
     * string used only for display grouping.
     */
    public function up(): void
    {
        Schema::create('product_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 64);           // 'MOTOR_CLASS1_GARAGE', 'FIRE_HOUSE_BASIC', ...
            $table->string('name_th', 128);
            $table->string('name_en', 128);
            // Non-normative grouping for the admin UI ("Motor" / "Fire" / "Health").
            // Not a FK — flat taxonomy.
            $table->string('sub_of', 32)->nullable();
            $table->foreignId('tier_id')
                ->constrained('commission_tiers')
                ->restrictOnDelete();  // don't let tier delete orphan product-types
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'tier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_types');
    }
};
