<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rate cells for a Life product's (age × sum) dimension. See
        // 2027_01_10_000100_create_product_life_rate_dimensions.php for the
        // parent table's rationale.
        //
        // policy_year is an unsigned smallint rather than a fixed set of
        // columns — some Life products have 10+ year curves that differ every
        // year. No fanout hack like the wide table's yr_6..yr_11up needed.
        //
        // party matches the existing convention: com | ag | in (the seeder's
        // partyMap() converts UI camelCase to these codes).
        Schema::create('product_life_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dimension_id')
                ->constrained('product_life_rate_dimensions')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('policy_year');   // 1, 2, 3, ..., N
            $table->string('party', 8);                    // com | ag | in
            $table->decimal('rate', 15, 4);                // percent 0..100
            $table->timestamps();

            // Exactly one rate per (dimension, year, party) — no ambiguity.
            $table->unique(['dimension_id', 'policy_year', 'party'], 'plr_dim_year_party_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_life_rates');
    }
};
