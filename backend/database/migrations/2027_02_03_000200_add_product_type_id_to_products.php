<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds products.product_type_id → product_types.id.
     *
     * Nullable during the taxonomy backfill period. Existing products need
     * to be re-classified against the new product_types taxonomy; that's a
     * data-migration exercise (either the ProductSeeder does it during
     * re-seed, or admin does it via the product form).
     *
     * The MGM engine (PR-D) will refuse to accrue commission when
     * product_type_id is null (falls through the base-rate resolver with
     * no matrix cell to look up). Not a validation-level requirement here
     * because breaking existing product create/edit flows would defeat
     * the point of a phased rollout.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_type_id')
                ->nullable()
                ->after('type')
                ->constrained('product_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['product_type_id']);
            $table->dropColumn('product_type_id');
        });
    }
};
