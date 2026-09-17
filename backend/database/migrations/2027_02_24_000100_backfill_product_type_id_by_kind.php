<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill products.product_type_id where it's NULL.
 *
 * Every product needs a product_type so the wizard's dynamic risk schema (and
 * its rider/beneficiary/vehicle fields) can render. Products carry a coarse
 * `type` (life / motor / non_life); we map that to a per-tenant DEFAULT
 * product_type of the matching `kind`:
 *   life     → kind 'life'
 *   motor    → kind 'motor'
 *   non_life → kind 'misc'   (general non-life bucket)
 * The default within a kind is that tenant's lowest-id product_type of that
 * kind. This is a coarse default — the specific type (e.g. exact motor class)
 * can still be refined per product by an operator later.
 *
 * Idempotent: only touches rows where product_type_id IS NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kindByType = [
            'life' => 'life',
            'motor' => 'motor',
            'non_life' => 'misc',
        ];

        // Per-tenant default product_type id for each kind (lowest id).
        $defaults = DB::table('product_types')
            ->select('tenant_id', 'kind', DB::raw('MIN(id) as default_id'))
            ->groupBy('tenant_id', 'kind')
            ->get()
            ->groupBy('tenant_id');

        DB::table('products')
            ->whereNull('product_type_id')
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($kindByType, $defaults): void {
                foreach ($products as $p) {
                    $kind = $kindByType[$p->type] ?? 'misc';
                    $row = ($defaults[$p->tenant_id] ?? collect())
                        ->firstWhere('kind', $kind)
                        ?? ($defaults[$p->tenant_id] ?? collect())->first();
                    if ($row === null) {
                        continue; // tenant has no product_types at all — skip
                    }
                    DB::table('products')->where('id', $p->id)
                        ->update(['product_type_id' => $row->default_id]);
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data backfill; no-op down so rollback doesn't wipe
        // legitimately-set product types.
    }
};
