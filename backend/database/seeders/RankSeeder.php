<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Rank;
use Illuminate\Database\Seeder;

/**
 * MGM ranks — 10 levels, seeded from Sheet2 rows 15-25 of the source Excel
 * (Blue/TIER_FULL example, licensed non-life broker track).
 *
 * Lv1 and Lv2 are marked "ไม่บังคับยอด" (no threshold) in the Excel; we
 * store them as 0 so the promotion engine's comparison is uniform.
 *
 * Lv7+ set license_required=true (per Sheet2 — those ranks are only for
 * licensed non-life brokers with a broker's certificate). Enforcement is
 * in PR-C's promotion engine against agents.has_license.
 *
 * updateOrCreate keyed by `level` so re-running the seeder after admins
 * rename ranks (name_th / name_en) preserves the renames. Adjusting
 * thresholds does overwrite — treat this seeder as the source of truth
 * for the numeric targets; admins should not tweak those via UI without
 * a corresponding code change.
 */
class RankSeeder extends Seeder
{
    public function run(): void
    {
        $ranks = [
            // level, code, name_th, name_en, monthly, three_month, license
            [1, 'Lv1', 'ระดับ 1', 'Level 1', 0, 0, false],
            [2, 'Lv2', 'ระดับ 2', 'Level 2', 0, 0, false],
            [3, 'Lv3', 'ระดับ 3', 'Level 3', 100_000, 300_000, false],
            [4, 'Lv4', 'ระดับ 4', 'Level 4', 200_000, 600_000, false],
            [5, 'Lv5', 'ระดับ 5', 'Level 5', 500_000, 1_500_000, false],
            [6, 'Lv6', 'ระดับ 6', 'Level 6', 1_000_000, 3_000_000, false],
            [7, 'Lv7', 'ระดับ 7', 'Level 7', 2_000_000, 6_000_000, true],
            [8, 'Lv8', 'ระดับ 8', 'Level 8', 4_000_000, 12_000_000, true],
            [9, 'Lv9', 'ระดับ 9', 'Level 9', 8_000_000, 24_000_000, true],
            [10, 'Lv10', 'ระดับ 10', 'Level 10', 16_000_000, 48_000_000, true],
        ];

        foreach ($ranks as [$level, $code, $nameTh, $nameEn, $monthly, $threeMonth, $license]) {
            Rank::updateOrCreate(
                ['level' => $level],
                [
                    'code' => $code,
                    'name_th' => $nameTh,
                    'name_en' => $nameEn,
                    'monthly_avg_target' => $monthly,
                    'three_month_accum_target' => $threeMonth,
                    'license_required' => $license,
                ],
            );
        }

        $this->command?->info('  ranks: '.Rank::count());
    }
}
