<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Report what CommissionEngine::fetchProductRates() will actually match once
 * COMMISSION_READ_PRODUCT_RATES is flipped on.
 *
 * Prints three tables:
 *   1. Distinct `party` values in product_commission_rate_installments — flags
 *      any code the engine won't recognise (i.e. anything other than
 *      com / ag / in).
 *   2. Distinct `installment_term` values — the engine only reads the "main"
 *      bucket; other terms are ignored (per-installment rates are a future
 *      refinement). This surfaces products where the main-term row is missing.
 *   3. Product-level summary: for every product with any rate row, whether it
 *      has (com, ag, in) all present at term "main". Products with a gap will
 *      have that party accrue at zero when the flag is flipped — the operator
 *      may want to backfill first.
 *
 * Read-only. Safe to run in production.
 */
class CommissionRatesAudit extends Command
{
    protected $signature = 'commission:rates-audit
        {--tenant= : Restrict to a single tenant (optional)}';

    protected $description = 'Audit product_commission_rate_installments before flipping COMMISSION_READ_PRODUCT_RATES.';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        $base = DB::table('product_commission_rate_installments as pcri');
        if ($tenantId !== null) {
            $base->join('products as p', 'p.id', '=', 'pcri.product_id')
                ->where('p.tenant_id', (int) $tenantId);
        }

        $this->line('');
        $this->info('1. Distinct party codes (engine expects: com, ag, in)');
        $partyRows = (clone $base)
            ->select('pcri.party', DB::raw('COUNT(*) as row_count'))
            ->groupBy('pcri.party')
            ->orderBy('pcri.party')
            ->get();

        $unrecognised = 0;
        $tableRows = [];
        foreach ($partyRows as $r) {
            $ok = in_array($r->party, ['com', 'ag', 'in'], true);
            if (! $ok) {
                $unrecognised += (int) $r->row_count;
            }
            $tableRows[] = [$r->party, $r->row_count, $ok ? 'ok' : 'UNRECOGNISED'];
        }
        $this->table(['party', 'rows', 'status'], $tableRows);
        if ($unrecognised > 0) {
            $this->warn("  → {$unrecognised} row(s) use party codes the engine will ignore.");
        }

        $this->line('');
        $this->info('2. Distinct installment_term values (engine reads only "main")');
        $termRows = (clone $base)
            ->select('pcri.installment_term', DB::raw('COUNT(*) as row_count'))
            ->groupBy('pcri.installment_term')
            ->orderBy('pcri.installment_term')
            ->get();
        $notMain = 0;
        $tableRows = [];
        foreach ($termRows as $r) {
            $ok = $r->installment_term === 'main';
            if (! $ok) {
                $notMain += (int) $r->row_count;
            }
            $tableRows[] = [$r->installment_term ?? '(null)', $r->row_count, $ok ? 'read' : 'ignored'];
        }
        $this->table(['installment_term', 'rows', 'status'], $tableRows);
        if ($notMain > 0) {
            $this->warn("  → {$notMain} row(s) live at non-main terms — currently ignored by the engine.");
        }

        $this->line('');
        $this->info('3. Per-product coverage at term "main"');
        $coverage = (clone $base)
            ->where('pcri.installment_term', 'main')
            ->whereIn('pcri.party', ['com', 'ag', 'in'])
            ->select(
                'pcri.product_id',
                DB::raw("SUM(CASE WHEN pcri.party = 'com' THEN 1 ELSE 0 END) as has_com"),
                DB::raw("SUM(CASE WHEN pcri.party = 'ag' THEN 1 ELSE 0 END) as has_ag"),
                DB::raw("SUM(CASE WHEN pcri.party = 'in' THEN 1 ELSE 0 END) as has_in"),
            )
            ->groupBy('pcri.product_id')
            ->get();

        $missing = ['com' => 0, 'ag' => 0, 'in' => 0];
        $productsWithGap = 0;
        foreach ($coverage as $row) {
            $gap = false;
            if ((int) $row->has_com === 0) {
                $missing['com']++;
                $gap = true;
            }
            if ((int) $row->has_ag === 0) {
                $missing['ag']++;
                $gap = true;
            }
            if ((int) $row->has_in === 0) {
                $missing['in']++;
                $gap = true;
            }
            if ($gap) {
                $productsWithGap++;
            }
        }
        $this->line("  Products with any main-term rate row: {$coverage->count()}");
        $this->line("  Products missing at least one party: {$productsWithGap}");
        $this->line("    - missing 'com' (InH): {$missing['com']}");
        $this->line("    - missing 'ag'  (AG):  {$missing['ag']}");
        $this->line("    - missing 'in'  (OV):  {$missing['in']}");
        $this->line('');

        $this->line('Next steps:');
        $this->line('  - Backfill unrecognised party codes or missing rows before flipping.');
        $this->line('  - Set COMMISSION_READ_PRODUCT_RATES=true, then run:');
        $this->line('      artisan tinker → CommissionEngine::recomputeForPolicy() for');
        $this->line('      any policies whose past payments should back-fill at the new rates.');

        return self::SUCCESS;
    }
}
