<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merges the manually-confirmed duplicate customer records surfaced by the
 * legacy-migration audit. Each pair is a reviewed decision (keep ← merge):
 * the merged record's policies and child rows move to the kept record, then
 * the merged record is soft-deleted. ONLY the 15 pairs confirmed safe
 * (EN/TH same person, identical-name empty dup, company spelling variant) are
 * here — the "different person / shared mistyped ID" groups are deliberately
 * NOT merged. Idempotent (skips a pair whose merge code is already gone).
 */
class MergeDuplicateCustomers extends Command
{
    protected $signature = 'customers:merge-duplicates {--dry-run} {--tenant=1}';

    protected $description = 'Merge the 15 confirmed-duplicate customer pairs (keep ← merge).';

    /** [keep_code, merge_code, why] — reviewed 2026-11-06. */
    private const PAIRS = [
        // A — same person, EN vs TH name; keep the Thai (C99) record
        ['C9901243', 'C8002297', 'A EN/TH สุบิน'],
        ['C9901244', 'C8002300', 'A EN/TH ลดาวัลย์'],
        ['C9901242', 'C8002303', 'A EN/TH ชญานิศ'],
        ['C9900802', 'C8003284', 'A EN/TH กานต์นภัส'],
        ['C9901155', 'C8003346', 'A EN/TH ทิพย์กัลยา'],
        ['C9900965', 'C8003377', 'A EN/TH ทัศนีย์'],
        ['C9900563', 'C8003703', 'A EN/TH อธิวัฒน์'],
        // B — identical name, merged record has 0 policies
        ['C9900920', 'C9901236', 'B identical รัชนี'],
        ['C9900944', 'C9901593', 'B identical มาดาพร'],
        ['C9900952', 'C9901594', 'B identical สุชญา'],
        ['C9900956', 'C9901597', 'B identical วนรัตน์'],
        // E — company, spelling/spacing variant of the same tax id
        ['C0004364', 'C9900975', 'E spelling ฟอยล์มาสเตอร์'],
        ['C8003502', 'C9901534', 'E spelling มาลีบู เฮาส์'],
        ['C9900576', 'C9900651', 'E spelling แซงชัวรี่ เนคซัส'],
        ['C9901501', 'C9901718', 'E dup ฤทธิ์รัตน (0 pol)'],
    ];

    /** customer_id FK tables to reassign when merging. */
    private const CHILD_TABLES = [
        'policies', 'customer_assignment_history', 'customer_kyc_docs', 'mail_threads',
    ];

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');
        $done = 0;
        $skipped = 0;
        $rows = [];

        foreach (self::PAIRS as [$keepCode, $mergeCode, $why]) {
            $keep = DB::table('customers')->where('tenant_id', $tenantId)->where('customer_code', $keepCode)->whereNull('deleted_at')->first();
            $merge = DB::table('customers')->where('tenant_id', $tenantId)->where('customer_code', $mergeCode)->whereNull('deleted_at')->first();

            if (! $keep || ! $merge) {
                $skipped++;
                $rows[] = [$keepCode, $mergeCode, 'skip (already merged / missing)', $why];

                continue;
            }

            $moved = [];
            foreach (self::CHILD_TABLES as $t) {
                $n = DB::table($t)->where('customer_id', $merge->id)->count();
                if ($n > 0) {
                    $moved[] = "$t:$n";
                    if (! $dry) {
                        DB::table($t)->where('customer_id', $merge->id)->update(['customer_id' => $keep->id]);
                    }
                }
            }
            if (! $dry) {
                // soft-delete the merged record, leaving a breadcrumb in notes
                DB::table('customers')->where('id', $merge->id)->update([
                    'deleted_at' => $this->timestamp(),
                    'notes' => trim((string) ($merge->notes ?? '')."\n[merged into {$keepCode} — {$why}]"),
                ]);
                // keep the surviving denormalized policy counts honest
                $this->refreshCounts($keep->id);
            }
            $done++;
            $rows[] = [$keepCode, $mergeCode, implode(' ', $moved) ?: '(no children)', $why];
        }

        $this->table(['keep', 'merged', 'moved', 'why'], $rows);
        $this->info(($dry ? '[DRY-RUN] would merge ' : 'merged ')."{$done} pairs, skipped {$skipped}");

        return self::SUCCESS;
    }

    private function refreshCounts(int $customerId): void
    {
        $active = DB::table('policies')->where('customer_id', $customerId)->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'expired'])->count();
        $total = DB::table('policies')->where('customer_id', $customerId)->whereNull('deleted_at')->count();
        DB::table('customers')->where('id', $customerId)->update([
            'active_policy_count' => $active,
            'total_policy_count' => $total,
        ]);
    }

    private function timestamp(): string
    {
        return DB::table('customers')->selectRaw('NOW() as n')->value('n');
    }
}
