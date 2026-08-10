<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Layer 2 (firm -> agent) commission overrides. Complements the
        // product_commission_rate_installments table (Layer 1: insurer -> firm)
        // by letting the firm renegotiate an individual agent's share of the
        // three parties (inh / ag / override) for a specific product,
        // product category, or as a per-agent default.
        //
        // Resolution order in CommissionEngine::resolveRates() (most specific
        // wins): plan with product_id -> plan with category -> plan with both
        // null -> product_commission_rate_installments.
        //
        // Nullable ag_rate / inh_rate / override_rate mean "don't override this
        // party; use the product-level rate". Explicit 0 means "zero this
        // party on purpose", matching the semantics of policies.main_com_rate_*.
        Schema::create('agent_commission_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();

            // Scope: pick ONE of product_id / category / both-null. Both-null is
            // the agent's default plan applied to any product without a more
            // specific rule.
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->string('category', 64)->nullable(); // matches products.type: life|health|motor|...

            // Direct-rate overrides. Same units as
            // product_commission_rate_installments.rate. Null = fall back to
            // the product rate for that party. See resolveRates() for details.
            $table->decimal('ag_rate', 15, 4)->nullable();       // writing agent's share of premium
            $table->decimal('inh_rate', 15, 4)->nullable();      // firm/in-house share
            $table->decimal('override_rate', 15, 4)->nullable(); // upline override share

            // Effective-dated versioning. Same pattern as
            // product_commission_rates.valid_start / valid_end. valid_end null =
            // open-ended.
            $table->date('valid_start');
            $table->date('valid_end')->nullable();

            // Audit trail — who set this and why. Free text for now; a
            // dedicated approval workflow would live in a sibling table.
            $table->string('note')->nullable();

            $table->timestamps();

            // Query paths used by the engine + admin UI.
            $table->index(['tenant_id', 'agent_id', 'valid_start'], 'acp_tenant_agent_start_idx');
            $table->index(['tenant_id', 'agent_id', 'product_id'], 'acp_tenant_agent_product_idx');
            $table->index(['tenant_id', 'agent_id', 'category'], 'acp_tenant_agent_category_idx');

            // Prevent two overlapping versions of the same scope. Two rows for
            // the same (agent, product, category, valid_start) are ambiguous
            // and the engine picks arbitrarily; forbid them.
            $table->unique(
                ['tenant_id', 'agent_id', 'product_id', 'category', 'valid_start'],
                'acp_scope_start_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commission_plans');
    }
};
