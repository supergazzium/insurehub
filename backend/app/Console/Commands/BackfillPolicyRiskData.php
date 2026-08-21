<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Policy;
use App\Support\PolicyRiskShim;
use App\Support\ProductKind;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase C-5 — populate `policies.risk_data` from the legacy top-level
 * risk-* columns for every existing row.
 *
 * Ground truth: docs/audit-2026-08-21/B2-schema-plan.md §4.
 *
 * Algorithm per row:
 *   1. Skip if risk_data IS NOT NULL and --force not set (idempotency).
 *   2. Resolve kind from product.productType.kind (C-3) with
 *      ProductKind::derive() fallback. If kind is null (misconfigured
 *      product), skip + count as `no-kind`.
 *   3. Walk PolicyRiskShim::FIELDS[kind] and copy every set column into
 *      risk_data[kind][key]. NULL columns are skipped so we don't emit
 *      empty keys.
 *   4. Update the row inside a per-chunk transaction. WriteS go via a
 *      raw DB::table update to bypass Eloquent's dirty-tracking so the
 *      shim's dual-write doesn't fire on this migration path.
 *
 * Dry-run by default. Printed histogram groups by kind so the operator
 * can spot which taxonomy rows are missing kind before going live.
 *
 * NOT wired into the schedule — one-shot manual invocation. Add a
 * schedule entry later if we need continuous reconciliation.
 */
class BackfillPolicyRiskData extends Command
{
    protected $signature = 'policies:backfill-risk-data
        {--dry-run : Print histogram + sample writes without persisting}
        {--force : Overwrite rows that already have non-NULL risk_data}
        {--tenant= : Restrict to a single tenant id (default: all)}
        {--chunk=100 : Batch size for the transaction wrapper}';

    protected $description = 'Populate policies.risk_data from legacy top-level risk-* columns via PolicyRiskShim.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $chunk = max(1, (int) $this->option('chunk'));

        $header = $dryRun ? 'DRY RUN — no writes' : 'LIVE RUN — writes enabled';
        $this->info("policies:backfill-risk-data [{$header}]");
        if ($tenantId !== null) {
            $this->line("  Scope: tenant_id = {$tenantId}");
        }
        if ($force) {
            $this->warn('  --force: rows with existing risk_data will be OVERWRITTEN.');
        }

        $totals = [
            'seen' => 0,
            'written' => 0,
            'skipped_has_data' => 0,
            'skipped_no_kind' => 0,
            'skipped_empty' => 0,
        ];
        // per-kind write counts
        $byKind = [];
        $sample = [];

        $query = Policy::query()
            ->with('product.productType')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id');

        $query->chunkById($chunk, function ($rows) use ($dryRun, $force, &$totals, &$byKind, &$sample): void {
            DB::transaction(function () use ($rows, $dryRun, $force, &$totals, &$byKind, &$sample): void {
                foreach ($rows as $policy) {
                    $totals['seen']++;

                    if (! $force && $policy->risk_data !== null) {
                        $totals['skipped_has_data']++;

                        continue;
                    }

                    $kind = $this->resolveKind($policy);
                    if ($kind === null) {
                        $totals['skipped_no_kind']++;

                        continue;
                    }

                    $shape = $this->buildRiskData($policy, $kind);
                    if ($shape === []) {
                        $totals['skipped_empty']++;

                        continue;
                    }

                    $totals['written']++;
                    $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;

                    if (count($sample) < 5) {
                        $sample[] = [
                            'id' => $policy->id,
                            'kind' => $kind,
                            'keys' => implode(',', array_keys($shape)),
                        ];
                    }

                    if ($dryRun) {
                        continue;
                    }

                    // Bypass Eloquent so the observer/casts don't retrigger the
                    // dual-write shim on this migration path.
                    DB::table('policies')
                        ->where('id', $policy->id)
                        ->update(['risk_data' => json_encode([$kind => $shape], JSON_UNESCAPED_UNICODE)]);
                }
            });
        });

        $this->newLine();
        $this->info('Kind histogram (rows written)');
        $rows = collect($byKind)
            ->map(fn ($n, $kind) => [$kind, $n])
            ->sortByDesc(fn ($r) => $r[1])
            ->values()
            ->toArray();
        $this->table(['kind', 'count'], $rows);

        if (! empty($sample)) {
            $this->newLine();
            $this->info('Sample writes (first 5)');
            $this->table(['policy_id', 'kind', 'keys populated'], array_map(fn ($s) => [
                $s['id'], $s['kind'], $s['keys'],
            ], $sample));
        }

        $this->newLine();
        $this->info(sprintf(
            'Totals: seen=%d, written=%d, skipped_has_data=%d, skipped_no_kind=%d, skipped_empty=%d',
            $totals['seen'],
            $totals['written'],
            $totals['skipped_has_data'],
            $totals['skipped_no_kind'],
            $totals['skipped_empty'],
        ));

        if ($dryRun && $totals['written'] > 0) {
            $this->newLine();
            $this->warn('Dry-run complete. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /** Prefer stored productType.kind (C-3). Fall back to runtime derivation.
     *  Normalized via PolicyRiskShim::canonicalKind so the derivation's
     *  legacy vocabulary (`property`/`other`) maps onto the shim's
     *  canonical set (`fire`/`misc`). */
    private function resolveKind(Policy $policy): ?string
    {
        $stored = $policy->product?->productType?->kind;
        if ($stored !== null) {
            return PolicyRiskShim::canonicalKind($stored);
        }
        $product = $policy->product;
        if ($product === null) {
            return null;
        }
        $derived = ProductKind::derive(
            $product->type ?? '',
            $product->category ?? '',
            $product->sub_category_2 ?? '',
            $product->sub_category ?? '',
        );

        return $derived !== null ? PolicyRiskShim::canonicalKind($derived) : null;
    }

    /**
     * Walk the shim field map for this kind, copy set columns into an
     * assoc array keyed by risk_data-shape keys. NULL columns are
     * skipped so we don't emit `{ engine_no: null, ... }`.
     *
     * @return array<string, mixed>
     */
    private function buildRiskData(Policy $policy, string $kind): array
    {
        $fieldMap = PolicyRiskShim::FIELDS[$kind] ?? [];
        $out = [];
        foreach ($fieldMap as $riskKey => $legacyCol) {
            $val = $policy->{$legacyCol} ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            // Cast date-typed model attributes to ISO strings so the JSON
            // payload matches what a fresh wizard write would produce.
            if ($val instanceof \Illuminate\Support\Carbon) {
                $val = $val->toDateString();
            }
            $out[$riskKey] = $val;
        }

        return $out;
    }
}
