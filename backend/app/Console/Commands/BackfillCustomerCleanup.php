<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\GenderFromTitle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Post-import cleanup for customer records the legacy source mis-typed or left
 * messy, surfaced by the migration audit. Three independent, idempotent passes:
 *
 *  1. Reclassify foreign PEOPLE mis-typed as corporate. The source typed some
 *     individuals (mostly non-Thai) as นิติบุคคล; their "company name" is a
 *     Latin personal name with no organisation keyword. Move the name into
 *     first/last and set foreign_individual. Real institutions (school, bank,
 *     condo juristic, Co./Ltd) are left as corporate.
 *  2. Normalise titles to a canonical set (Mr./Miss/Mrs. case, combined
 *     "น.ส.,นาย" values, spaced "ว่าที่ ร.ต. หญิง").
 *  3. Fix titles that contradict gender — the title is authoritative
 *     (นาย→M, นาง/นางสาว→F, Mr→M, Mrs/Miss→F).
 */
class BackfillCustomerCleanup extends Command
{
    protected $signature = 'customers:cleanup {--dry-run} {--tenant=1}';

    protected $description = 'Reclassify foreign people mis-typed as companies, normalise titles, fix title/gender clashes.';

    /** Organisation keywords — a name containing any of these stays corporate. */
    private const ORG_WORDS = [
        'บริษัท', 'ห้างหุ้นส่วน', 'หจก', 'บจก', 'บมจ', 'จำกัด', 'ร้าน',
        'โรงเรียน', 'สถาบัน', 'มหาวิทยาลัย', 'วิทยาลัย', 'ธนาคาร', 'เทศบาล',
        'องค์การ', 'องค์กร', 'นิติบุคคล', 'กองทุน', 'สำนักงาน', 'สมาคม', 'มูลนิธิ',
        'company', 'co.', 'co ', 'ltd', 'limited', 'corporation', 'school', 'shop', 'group',
    ];

    /** raw title → canonical title. */
    private const TITLE_MAP = [
        'mr' => 'Mr.', 'mr.' => 'Mr.', 'MR' => 'Mr.', 'MR.' => 'Mr.',
        'mrs' => 'Mrs.', 'mrs.' => 'Mrs.', 'MRS' => 'Mrs.', 'MRS.' => 'Mrs.',
        'miss' => 'Miss', 'MISS' => 'Miss', 'ms' => 'Ms.', 'ms.' => 'Ms.',
        'master' => 'Master', 'MASTER' => 'Master',
        'น.ส.,นาย' => 'นางสาว', 'นางสาว / นาย' => 'นางสาว', 'นางสาว/นาย' => 'นางสาว',
        'ว่าที่ ร.ต. หญิง' => 'ว่าที่ร้อยตรีหญิง',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $tenantId = (int) $this->option('tenant');

        $reclassified = $this->reclassifyForeignPeople($tenantId, $dry);
        $titles = $this->normaliseTitles($tenantId, $dry);
        $genders = $this->fixGenderClashes($tenantId, $dry);

        $this->table(['pass', 'affected'], [
            ['reclassified corporate→foreign_individual', $reclassified],
            ['titles normalised', $titles],
            ['title/gender clashes fixed', $genders],
        ]);
        $this->info($dry ? '[DRY-RUN] no writes' : 'cleanup applied');

        return self::SUCCESS;
    }

    private function reclassifyForeignPeople(int $tenantId, bool $dry): int
    {
        $n = 0;
        $rows = DB::table('customers')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where('customer_type', 'corporate')->whereNotNull('juristic_name')->get();
        foreach ($rows as $c) {
            $name = trim((string) $c->juristic_name);
            // Latin personal name only (letters, spaces, dots) and no org keyword.
            if ($name === '' || ! preg_match('/^[A-Za-z. ]+$/', $name)) {
                continue;
            }
            $lower = mb_strtolower($name);
            foreach (self::ORG_WORDS as $w) {
                if (str_contains($lower, mb_strtolower($w))) {
                    continue 2;
                }
            }
            // split "First Middle Last" → first = all but last word, last = last word
            $parts = preg_split('/\s+/', $name);
            $last = count($parts) > 1 ? array_pop($parts) : null;
            $first = implode(' ', $parts);
            $n++;
            if (! $dry) {
                DB::table('customers')->where('id', $c->id)->update([
                    'customer_type' => 'foreign_individual',
                    'first_name' => $first,
                    'last_name' => $last,
                    'juristic_name' => null,
                    'tax_id' => null,
                ]);
            }
        }

        return $n;
    }

    private function normaliseTitles(int $tenantId, bool $dry): int
    {
        $n = 0;
        foreach (self::TITLE_MAP as $from => $to) {
            $q = DB::table('customers')->where('tenant_id', $tenantId)->whereNull('deleted_at')
                ->whereRaw('TRIM(title_th) = ?', [$from]);
            $count = $q->count();
            if ($count > 0) {
                $n += $count;
                if (! $dry) {
                    $q->update(['title_th' => $to]);
                }
            }
        }

        return $n;
    }

    private function fixGenderClashes(int $tenantId, bool $dry): int
    {
        $n = 0;
        $rows = DB::table('customers')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->whereIn('customer_type', ['individual', 'foreign_individual'])
            ->whereNotNull('title_th')->whereNotNull('gender')->where('gender', '<>', '')->get();
        foreach ($rows as $c) {
            $fromTitle = GenderFromTitle::guess($c->title_th);
            if ($fromTitle !== null && $fromTitle !== $c->gender) {
                $n++;
                if (! $dry) {
                    DB::table('customers')->where('id', $c->id)->update(['gender' => $fromTitle]);
                }
            }
        }

        return $n;
    }
}
