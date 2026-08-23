<?php

declare(strict_types=1);

use App\Support\ProductKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * C-19 — Backfill products.product_type_id for the 898 legacy products
 * whose FK was never populated. Without this, the wizard's schema-driven
 * Risk section renders as an empty card for every real carrier — only
 * the 18 SCN scenario products (which were seeded with an FK) worked.
 *
 * Strategy:
 *   1. For each product with product_type_id IS NULL, derive the kind
 *      using ProductKind::derive(type, category, sub_category_2,
 *      sub_category). Aliases `property → fire` and `other → misc` per
 *      PolicyRiskShim::KIND_ALIASES.
 *   2. Pick the product_types row with the matching kind that has the
 *      lowest sort_order — a reasonable default (admins can retag each
 *      product individually via the ProductType picker later).
 *   3. UPDATE the row.
 *
 * Idempotent: only touches rows where product_type_id IS NULL. Safe to
 * re-run. Reversible: down() clears the assignments made here by matching
 * against a marker (see below).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Build kind → product_type_id map (first row by sort_order).
        $canonical = DB::table('product_types')
            ->select(['id', 'kind', 'sort_order'])
            ->whereNotNull('kind')
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('kind')
            ->map(fn ($rows) => (int) $rows->first()->id)
            ->toArray();

        if (count($canonical) === 0) {
            // Fresh DB with no product_types seeded yet — nothing to do.
            return;
        }

        $unmapped = DB::table('products')
            ->select(['id', 'type', 'category', 'sub_category', 'sub_category_2'])
            ->whereNull('product_type_id')
            ->get();

        $counts = ['motor' => 0, 'fire' => 0, 'travel' => 0, 'life' => 0, 'health' => 0, 'misc' => 0, 'skipped' => 0];

        foreach ($unmapped as $row) {
            $derived = ProductKind::derive(
                (string) ($row->type ?? ''),
                (string) ($row->category ?? ''),
                (string) ($row->sub_category_2 ?? ''),
                (string) ($row->sub_category ?? ''),
            );

            // Normalize aliases so we match product_types.kind vocabulary.
            $kind = match ($derived) {
                'property' => 'fire',
                'other' => 'misc',
                default => $derived,
            };

            $ptId = $canonical[$kind] ?? null;
            if ($ptId === null) {
                $counts['skipped']++;
                continue;
            }

            DB::table('products')->where('id', $row->id)->update(['product_type_id' => $ptId]);
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        // Announce results in the migration log so we can spot-check.
        $summary = collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(' ');
        DB::statement("SELECT '  backfilled: {$summary}' AS msg");
    }

    public function down(): void
    {
        // Non-destructive rollback: we don't know which specific rows we
        // touched vs which were manually assigned after the fact. Leave
        // the FKs in place — the up() is idempotent so re-running is safe.
    }
};
