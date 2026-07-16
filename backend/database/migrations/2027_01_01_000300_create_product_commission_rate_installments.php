<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Source: insurehub.product_commission_rates (tall shape — 1,990 rows).
        // Preserves the (party, installment_term) dimensions that the existing
        // wide `product_commission_rates` per-year columns cannot represent.
        Schema::create('product_commission_rate_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('party', 8);                          // com | ag | in
            $table->string('installment_term', 32)->nullable();  // main | 3 | 6 | 12 | ...
            $table->decimal('rate', 15, 4);                       // 0..1 or 0..100 depending on legacy
            $table->timestamps();

            $table->unique(['product_id', 'party', 'installment_term'], 'pcri_prod_party_term_unique');
            $table->index(['product_id', 'party']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_commission_rate_installments');
    }
};
