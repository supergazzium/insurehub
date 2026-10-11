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
 * licensed non-life brokers with a broker's certificate).
 */
class RankSeeder extends Seeder
{
    public function run(): void
    {
        $ranks = [
            [1, 'Lv1', 'ระดับ 1', 'Level 1', 0, false],
            [2, 'Lv2', 'ระดับ 2', 'Level 2', 0, false],
            [3, 'Lv3', 'ระดับ 3', 'Level 3', 300_000, false],
            [4, 'Lv4', 'ระดับ 4', 'Level 4', 600_000, false],
            [5, 'Lv5', 'ระดับ 5', 'Level 5', 1_500_000, false],
            [6, 'Lv6', 'ระดับ 6', 'Level 6', 3_000_000, false],
            [7, 'Lv7', 'ระดับ 7', 'Level 7', 6_000_000, true],
            [8, 'Lv8', 'ระดับ 8', 'Level 8', 12_000_000, true],
            [9, 'Lv9', 'ระดับ 9', 'Level 9', 24_000_000, true],
            [10, 'Lv10', 'ระดับ 10', 'Level 10', 48_000_000, true],
        ];

        foreach ($ranks as [$level, $code, $nameTh, $nameEn, $threeMonth, $license]) {
            Rank::firstOrCreate(
                ['level' => $level],
                [
                    'code' => $code,
                    'name_th' => $nameTh,
                    'name_en' => $nameEn,
                    'three_month_accum_target' => $threeMonth,
                    'license_required' => $license,
                ],
            );
        }

        $this->command?->info('  ranks: '.Rank::count());
    }
}
