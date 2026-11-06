<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies the 22 manually-reviewed duplicate-ID decisions from the migration
 * audit (round 3). Each entry is a human verdict, encoded explicitly rather
 * than parsed from free text. Idempotent: a merge whose merged record is
 * already soft-deleted is skipped; a field already set is left.
 *
 * Verdict types:
 *   merge     — move merged record's policies + children to keeper, soft-delete it;
 *               optional copy_phone preserves the merged record's phone.
 *   fix_tax    — set a corporate record's tax_id to the correct value.
 *   blank_id   — clear id_card/tax_id on a record whose number was a mistyped
 *                collision with a DIFFERENT real customer.
 *   reclassify — corporate "shop" that is really a person → individual.
 */
class ApplyDupDecisions extends Command
{
    protected $signature = 'customers:apply-dup-decisions {--dry-run} {--tenant=1}';

    protected $description = 'Apply the 22 reviewed duplicate-ID merge/fix/reclassify decisions.';

    private const CHILD_TABLES = ['policies', 'customer_assignment_history', 'customer_kyc_docs', 'mail_threads'];

    /** [keep_code, merge_code, copy_phone?] */
    private const MERGES = [
        ['C8003518', 'C0000188', false], ['C9901434', 'C0001507', false],
        ['C8003884', 'C0002743', false], ['C9001633', 'C0005531', false],
        ['C8002544', 'C0006038', false], ['C9001808', 'C0006234', false],
        ['C8000653', 'C9901159', false], ['C8002787', 'C8000991', false],
        ['C8003519', 'C8002102', false], ['C9900862', 'C8002330', false],
        ['C9900860', 'C8002331', false], ['C8003410', 'C8002594', false],
        ['C8003532', 'C8002639', false], ['C9001748', 'C9000501', false],
        ['C9901371', 'C9901744', false],
        ['C9901701', 'C9901126', true],   // #17 copy phone from merged
        ['C9900898', 'C8003771', false],  // #22 company renamed
        ['C8003765', 'C9900598', false],  // #21 two CHAROENVIT
        ['C8003762', 'C9900614', false],  // #23 SS PLATINUM EN+TH (surfaced by tax fix)
    ];

    /** code → correct tax_id (#19, #21). */
    private const FIX_TAX = [
        'C9900895' => '0105564049500', 'C9901251' => '0994000920938',       // #19
        'C8003762' => '0105547073708', 'C8003766' => '0105555020600',       // #21
        'C8003768' => '0105558116250', 'C9900595' => '0125562009195',
        'C9900596' => '0105557118691', 'C8003765' => '0105531009771',
    ];

    /** codes whose shared (mistyped) id_card should be blanked — #14,#15 duplicates. */
    private const BLANK_ID = ['C9901099', 'C8003843'];

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $merged = $this->applyMerges($tenantId, $dry);
        $taxed = $this->applyTaxFixes($tenantId, $dry);
        $blanked = $this->applyBlankIds($tenantId, $dry);
        $recl = $this->applyReclassify($tenantId, $dry);  // #20

        $this->table(['action', 'count'], [
            ['merges applied', $merged],
            ['tax_id fixes', $taxed],
            ['shared-id blanked', $blanked],
            ['reclassified (ร้านแมงป่อง→person)', $recl],
        ]);
        $this->info($dry ? '[DRY-RUN] no writes' : 'decisions applied');

        return self::SUCCESS;
    }

    private function cust(int $tenant, string $code): ?object
    {
        return DB::table('customers')->where('tenant_id', $tenant)->where('customer_code', $code)->whereNull('deleted_at')->first();
    }

    private function applyMerges(int $tenant, bool $dry): int
    {
        $n = 0;
        foreach (self::MERGES as [$keepCode, $mergeCode, $copyPhone]) {
            $keep = $this->cust($tenant, $keepCode);
            $merge = $this->cust($tenant, $mergeCode);
            if (! $keep || ! $merge) {
                continue;
            }
            if (! $dry) {
                foreach (self::CHILD_TABLES as $t) {
                    DB::table($t)->where('customer_id', $merge->id)->update(['customer_id' => $keep->id]);
                }
                if ($copyPhone && trim((string) $keep->phone) === '' && trim((string) $merge->phone) !== '') {
                    DB::table('customers')->where('id', $keep->id)->update(['phone' => $merge->phone]);
                }
                DB::table('customers')->where('id', $merge->id)->update([
                    'deleted_at' => $this->now(),
                    'notes' => trim((string) ($merge->notes ?? '')."\n[merged into {$keepCode} — dup-ID review]"),
                ]);
                $this->refreshCounts($keep->id);
            }
            $n++;
        }

        return $n;
    }

    private function applyTaxFixes(int $tenant, bool $dry): int
    {
        $n = 0;
        foreach (self::FIX_TAX as $code => $tax) {
            $c = $this->cust($tenant, $code);
            if (! $c) {
                continue;
            }
            if ((string) $c->tax_id !== $tax) {
                $n++;
                if (! $dry) {
                    DB::table('customers')->where('id', $c->id)->update(['tax_id' => $tax]);
                }
            }
        }

        return $n;
    }

    private function applyBlankIds(int $tenant, bool $dry): int
    {
        $n = 0;
        foreach (self::BLANK_ID as $code) {
            $c = $this->cust($tenant, $code);
            if (! $c || (trim((string) $c->id_card) === '' && trim((string) $c->tax_id) === '')) {
                continue;
            }
            $n++;
            if (! $dry) {
                DB::table('customers')->where('id', $c->id)->update([
                    'id_card' => null,
                    'notes' => trim((string) ($c->notes ?? '')."\n[id_card cleared — was a mistyped collision with another customer; needs correct ID]"),
                ]);
            }
        }

        return $n;
    }

    /** #20: C9901379 "ร้าน แมงป่อง" is actually นายวิศรุต จอกแก้ว. */
    private function applyReclassify(int $tenant, bool $dry): int
    {
        $c = $this->cust($tenant, 'C9901379');
        if (! $c || $c->customer_type !== 'corporate') {
            return 0;
        }
        if (! $dry) {
            DB::table('customers')->where('id', $c->id)->update([
                'customer_type' => 'individual',
                'title_th' => 'นาย',
                'first_name' => 'วิศรุต',
                'last_name' => 'จอกแก้ว',
                'juristic_name' => null,
                'tax_id' => null,
                'id_card' => '1470900054721',
                'gender' => 'M',
                'birth_date' => '1988-09-17',  // 17 ก.ย. 2531 BE → CE
                'address' => '3/7',
                'moo' => '8',
                'sub_district' => 'บางปูใหม่',
                'amphoe' => 'สมุทรปราการ',
                'province' => 'สมุทรปราการ',
            ]);
        }

        return 1;
    }

    private function refreshCounts(int $id): void
    {
        $active = DB::table('policies')->where('customer_id', $id)->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'expired'])->count();
        $total = DB::table('policies')->where('customer_id', $id)->whereNull('deleted_at')->count();
        DB::table('customers')->where('id', $id)->update(['active_policy_count' => $active, 'total_policy_count' => $total]);
    }

    private function now(): string
    {
        return DB::table('customers')->selectRaw('NOW() n')->value('n');
    }
}
