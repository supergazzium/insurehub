<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PolicyYears;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-derive ปีของกรมธรรม์ (policy_year) and ปีของลูกค้า (customer_year) for
 * existing policies by walking the renewal chain (ref_app_to_id) — task 1.4.
 *
 *   policy_year   root = 1; a renewal = parent + 1 on the same product, else 1
 *   customer_year renewal = parent + 1; otherwise the ordinal year since the
 *                 customer's earliest policy (as of this policy's effective date)
 *
 * What it writes (never touches a value that looks deliberate):
 *   - customer_year when it is null
 *   - policy_year when it still holds the column default 1 and the chain says
 *     it is a later renewal year
 * Everything else that disagrees with the chain is listed as a conflict and
 * left alone unless --overwrite is passed. Writes go straight to the table,
 * so no observer / audit / schedule side effects fire.
 *
 *   php artisan policies:backfill-years [--tenant=ID] [--dry-run] [--overwrite]
 */
class BackfillPolicyYears extends Command
{
    protected $signature = 'policies:backfill-years
        {--tenant= : restrict to one tenant id}
        {--dry-run : report only, write nothing}
        {--overwrite : also replace conflicting stored values with the derived ones}';

    protected $description = 'Derive policy_year / customer_year from renewal chains for existing policies';

    /** @var array<int, object> */
    private array $rows = [];

    /** @var array<int, int> */
    private array $policyYear = [];

    /** @var array<int, int> */
    private array $customerYear = [];

    /** @var array<int, string|null> first effective date per customer */
    private array $firstByCustomer = [];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');

        $q = DB::table('policies')->whereNull('deleted_at');
        if ($this->option('tenant') !== null) {
            $q->where('tenant_id', (int) $this->option('tenant'));
        }
        foreach ($q->get(['id', 'tenant_id', 'customer_id', 'product_id', 'ref_app_to_id', 'effective_date', 'policy_year', 'customer_year', 'policy_no', 'application_no']) as $r) {
            $this->rows[(int) $r->id] = $r;
            if ($r->customer_id !== null && $r->effective_date !== null) {
                $key = $r->tenant_id.':'.$r->customer_id;
                $cur = $this->firstByCustomer[$key] ?? null;
                if ($cur === null || $r->effective_date < $cur) {
                    $this->firstByCustomer[$key] = $r->effective_date;
                }
            }
        }

        $updates = [];
        $conflicts = [];
        $chained = 0;
        foreach ($this->rows as $id => $r) {
            $py = $this->derivePolicyYear($id);
            $cy = $this->deriveCustomerYear($id);
            if ($r->ref_app_to_id !== null) {
                $chained++;
            }

            $set = [];
            if ($r->customer_year === null) {
                $set['customer_year'] = $cy;
            } elseif ((int) $r->customer_year !== $cy) {
                $conflicts[] = [$id, $this->label($r), 'customer_year', $r->customer_year, $cy];
                if ($overwrite) {
                    $set['customer_year'] = $cy;
                }
            }
            if ((int) $r->policy_year !== $py) {
                if ((int) $r->policy_year <= 1 && $py > 1) {
                    $set['policy_year'] = $py;
                } else {
                    $conflicts[] = [$id, $this->label($r), 'policy_year', $r->policy_year, $py];
                    if ($overwrite) {
                        $set['policy_year'] = $py;
                    }
                }
            }
            if ($set !== []) {
                $updates[$id] = $set;
            }
        }

        $this->info(sprintf('%sScanned %d policies (%d in a renewal chain).', $dry ? '[dry-run] ' : '', count($this->rows), $chained));
        $fill = count(array_filter($updates, fn ($s) => isset($s['customer_year'])));
        $bump = count(array_filter($updates, fn ($s) => isset($s['policy_year'])));
        $this->line("  customer_year to set: {$fill}");
        $this->line("  policy_year to set:   {$bump}");

        if ($conflicts !== []) {
            $this->warn(count($conflicts).' conflict(s) — stored value disagrees with the renewal chain'.($overwrite ? ' (overwriting)' : ' (left as is; --overwrite to replace)').':');
            $this->table(['id', 'policy', 'field', 'stored', 'derived'], array_slice($conflicts, 0, 50));
            if (count($conflicts) > 50) {
                $this->line('  … '.(count($conflicts) - 50).' more');
            }
        }

        if (! $dry) {
            DB::transaction(function () use ($updates): void {
                foreach ($updates as $id => $set) {
                    DB::table('policies')->where('id', $id)->update($set);
                }
            });
            $this->info('Updated '.count($updates).' policies.');
        }

        return self::SUCCESS;
    }

    private function parentOf(int $id): ?object
    {
        $ref = $this->rows[$id]->ref_app_to_id;
        $parent = $ref !== null ? ($this->rows[(int) $ref] ?? null) : null;

        return $parent !== null && $parent->tenant_id === $this->rows[$id]->tenant_id ? $parent : null;
    }

    private function derivePolicyYear(int $id, array $seen = []): int
    {
        if (isset($this->policyYear[$id])) {
            return $this->policyYear[$id];
        }
        $parent = $this->parentOf($id);
        if ($parent === null || isset($seen[$id])) {   // root, or a broken cycle
            return $this->policyYear[$id] = 1;
        }
        $seen[$id] = true;
        $sameProduct = (string) $parent->product_id === (string) $this->rows[$id]->product_id;

        return $this->policyYear[$id] = $sameProduct ? $this->derivePolicyYear((int) $parent->id, $seen) + 1 : 1;
    }

    private function deriveCustomerYear(int $id, array $seen = []): int
    {
        if (isset($this->customerYear[$id])) {
            return $this->customerYear[$id];
        }
        $r = $this->rows[$id];
        $parent = $this->parentOf($id);
        if ($parent !== null && ! isset($seen[$id])) {
            $seen[$id] = true;

            return $this->customerYear[$id] = $this->deriveCustomerYear((int) $parent->id, $seen) + 1;
        }
        $first = $r->customer_id !== null ? ($this->firstByCustomer[$r->tenant_id.':'.$r->customer_id] ?? null) : null;

        return $this->customerYear[$id] = PolicyYears::ordinalYear($first, $r->effective_date);
    }

    private function label(object $r): string
    {
        return (string) ($r->policy_no ?? $r->application_no ?? '—');
    }
}
