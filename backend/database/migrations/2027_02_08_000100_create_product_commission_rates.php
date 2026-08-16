<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-product standard commission rates. Replaces the (carrier × type)
     * matrix as the source of truth for the MGM engine. Two rows per product,
     * one per direction:
     *
     *   direction='carrier_to_hub' — what the carrier pays InsureHub
     *   direction='hub_to_agent'   — what InsureHub pays the selling agent;
     *                                consumed by the MGM engine as the
     *                                DIRECT_COMMISSION base rate
     *
     * Scheme depends on product group:
     *   scheme='life_years' → per-year vector (yr_1..yr_5, yr_6_10, yr_11_up)
     *   scheme='flat'       → single flat_rate applied to every payment
     *
     * Effective-dating is deferred (schema supports it, revision workflow will
     * come later). Resolver picks the max(effective_from) row per direction.
     *
     * Note on table name: an older `product_commission_rates` (per-year wide
     * shape, Access import) was dropped by
     * 2027_02_01_000100_drop_legacy_commission_tables.php. Reusing the name
     * here — this is a fresh table with a different column set and different
     * ownership semantics (created/edited on the Product form, not imported).
     *
     * Backfill (in this migration's up()):
     *   - carrier_to_hub.flat_rate ← carrier_product_type_rates.standard_rate
     *     matched by (carrier_id, product_type_id) when a matrix row exists.
     *   - hub_to_agent.flat_rate  ← null (was never persisted before; the
     *     ProductRequest silently dropped the frontend's commissionPercent).
     *     Admin fills in per product via the new UI.
     *
     * S1–S12 scenario ledgers depend on `hub_to_agent` returning the same
     * rates the matrix used to. Post-migration, the resolver falls back to
     * the matrix when no row exists, keeping accruals identical until the
     * hub_to_agent side is populated.
     */
    public function up(): void
    {
        Schema::create('product_commission_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('direction', 32);
            $table->string('scheme', 32);
            $table->decimal('flat_rate', 8, 5)->nullable();
            $table->decimal('yr_1', 8, 5)->nullable();
            $table->decimal('yr_2', 8, 5)->nullable();
            $table->decimal('yr_3', 8, 5)->nullable();
            $table->decimal('yr_4', 8, 5)->nullable();
            $table->decimal('yr_5', 8, 5)->nullable();
            $table->decimal('yr_6_10', 8, 5)->nullable();
            $table->decimal('yr_11_up', 8, 5)->nullable();
            $table->date('effective_from')->nullable();
            $table->timestamps();

            // MySQL treats NULL as distinct in unique indexes, so the
            // "no effective date" case (effective_from = NULL) allows multiple
            // rows per (tenant, product, direction). Controllers upsert on
            // (tenant, product, direction) explicitly to keep it single-row
            // until revision workflow lands.
            $table->unique(
                ['tenant_id', 'product_id', 'direction', 'effective_from'],
                'pcr_tenant_product_direction_start_unique'
            );

            // Resolver hot path.
            $table->index(['tenant_id', 'product_id', 'direction'], 'pcr_lookup_idx');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_commission_rates');
    }

    /**
     * One row per (product, direction) for every existing product. Scheme is
     * derived from the product group (Life/Rider → life_years, else flat).
     * carrier_to_hub inherits the matrix cell if one exists; hub_to_agent
     * starts null (no previous storage).
     */
    private function backfill(): void
    {
        $now = now();
        $rows = [];

        $products = DB::table('products')->select(
            'id',
            'tenant_id',
            'carrier_id',
            'product_type_id',
            'type'
        )->get();

        foreach ($products as $p) {
            $scheme = in_array($p->type, ['Life', 'Rider'], true) ? 'life_years' : 'flat';

            $carrierRow = $p->product_type_id !== null
                ? DB::table('carrier_product_type_rates')
                    ->where('tenant_id', $p->tenant_id)
                    ->where('carrier_id', $p->carrier_id)
                    ->where('product_type_id', $p->product_type_id)
                    ->orderByDesc('id')
                    ->first()
                : null;

            $carrierToHubRate = $carrierRow !== null && $carrierRow->standard_rate !== null
                ? (float) $carrierRow->standard_rate
                : null;

            $rows[] = [
                'tenant_id' => $p->tenant_id,
                'product_id' => $p->id,
                'direction' => 'hub_to_agent',
                'scheme' => $scheme,
                'flat_rate' => null,
                'yr_1' => null,
                'yr_2' => null,
                'yr_3' => null,
                'yr_4' => null,
                'yr_5' => null,
                'yr_6_10' => null,
                'yr_11_up' => null,
                'effective_from' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $rows[] = [
                'tenant_id' => $p->tenant_id,
                'product_id' => $p->id,
                'direction' => 'carrier_to_hub',
                'scheme' => $scheme,
                'flat_rate' => $scheme === 'flat' ? $carrierToHubRate : null,
                'yr_1' => $scheme === 'life_years' ? $carrierToHubRate : null,
                'yr_2' => null,
                'yr_3' => null,
                'yr_4' => null,
                'yr_5' => null,
                'yr_6_10' => null,
                'yr_11_up' => null,
                'effective_from' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Chunk to keep single insert statement below MySQL packet limit.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_commission_rates')->insert($chunk);
        }
    }
};
