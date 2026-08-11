<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CommissionTier;
use App\Models\CommissionTierRankRate;
use App\Models\Rank;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the 3 MGM commission tiers per tenant and the 30 (tier × rank) rate
 * rows underneath each. Numbers come directly from Sheet2 of the source
 * Excel:
 *
 *   TIER_FULL     (ฟ้า / Blue)   — Lv10 6.75% .. Lv1 0% mgmt fee, 1% referral
 *   TIER_PARTIAL  (เหลือง / Yellow) — Lv10 1.30% .. Lv1 0% mgmt fee, 0% referral
 *   TIER_DIRECT_ONLY (แดง / Red)   — all zero (seller keeps their commission,
 *                                    no upline gets anything)
 *
 * Referral fee is 1% for TIER_FULL across ALL ranks (not just Lv10). This
 * matches Sheet1 rows 4-9 (Lv1-Lv6) which also show 1% referral. The
 * referral is a tier-level attribute; storing it per-(tier, rank) row lets
 * a future admin change it per rank if they need to, but the seeder keeps
 * every rank in the same tier at the same referral rate.
 *
 * Idempotent: uses updateOrCreate keyed by (tenant, code) for tiers and
 * (tier, rank) for rate rows. Re-running preserves admin renames on the
 * tier name_th / name_en / notes / color_hex fields.
 */
class CommissionTierSeeder extends Seeder
{
    /**
     * Mgmt fee curve per tier — indexed by rank level (1..10).
     * Same shape as Sheet2 rows 15-25 (TIER_FULL example) and rows 28-38
     * (TIER_PARTIAL example). TIER_DIRECT_ONLY is all zero.
     */
    private const MGMT_FEE_CURVES = [
        'TIER_FULL' => [
            1 => 0,       2 => 0.03,    3 => 0.04,    4 => 0.045,   5 => 0.05,
            6 => 0.055,   7 => 0.06,    8 => 0.0625,  9 => 0.065,  10 => 0.0675,
        ],
        'TIER_PARTIAL' => [
            1 => 0,       2 => 0.005,   3 => 0.006,   4 => 0.007,   5 => 0.008,
            6 => 0.009,   7 => 0.010,   8 => 0.011,   9 => 0.012,  10 => 0.013,
        ],
        'TIER_DIRECT_ONLY' => [
            1 => 0,       2 => 0,       3 => 0,       4 => 0,       5 => 0,
            6 => 0,       7 => 0,       8 => 0,       9 => 0,      10 => 0,
        ],
    ];

    private const REFERRAL_FEE_PER_TIER = [
        'TIER_FULL' => 0.01,        // 1% flat across all ranks
        'TIER_PARTIAL' => 0,
        'TIER_DIRECT_ONLY' => 0,
    ];

    /**
     * @var array<string, array{name_th: string, name_en: string, color: string, sort: int, notes: string}>
     */
    private const TIER_META = [
        'TIER_FULL' => [
            'name_th' => 'เต็มระบบ',
            'name_en' => 'Full-commission tier',
            'color' => '#3B82F6', // blue
            'sort' => 1,
            'notes' => 'ค่าตำแหน่งเต็ม + ค่าแนะนำ 1% — เหมาะกับสินค้าที่ carrier จ่าย commission สูง (Motor ชั้น 1, PA, Fire)',
        ],
        'TIER_PARTIAL' => [
            'name_th' => 'บางส่วน',
            'name_en' => 'Partial-commission tier',
            'color' => '#EAB308', // yellow
            'sort' => 2,
            'notes' => 'ค่าตำแหน่งน้อย ไม่มีค่าแนะนำ — เหมาะกับ พรบ, Marine, ประกันสุขภาพ',
        ],
        'TIER_DIRECT_ONLY' => [
            'name_th' => 'เฉพาะผู้ขาย',
            'name_en' => 'Direct-only tier',
            'color' => '#EF4444', // red
            'sort' => 3,
            'notes' => 'ผู้ขายได้ commission เต็ม ไม่มีค่าตำแหน่ง / ค่าแนะนำ — เหมาะกับ IAR, MISC, ประกันกลุ่ม',
        ],
    ];

    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $this->command?->warn('No tenants found — skip commission tier seeding.');

            return;
        }

        // Ranks are shared across tenants (see RankSeeder). Index by level for
        // fast lookup when writing the 30 rate rows.
        $ranksByLevel = Rank::all()->keyBy('level');
        if ($ranksByLevel->isEmpty()) {
            $this->command?->error('ranks table is empty — run RankSeeder first.');

            return;
        }

        foreach ($tenants as $tenant) {
            foreach (array_keys(self::MGMT_FEE_CURVES) as $code) {
                $meta = self::TIER_META[$code];
                $tier = CommissionTier::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $code],
                    [
                        'name_th' => $meta['name_th'],
                        'name_en' => $meta['name_en'],
                        'color_hex' => $meta['color'],
                        'sort_order' => $meta['sort'],
                        'notes' => $meta['notes'],
                    ],
                );

                foreach (self::MGMT_FEE_CURVES[$code] as $level => $mgmt) {
                    $rank = $ranksByLevel->get($level);
                    if ($rank === null) {
                        continue;
                    }
                    CommissionTierRankRate::updateOrCreate(
                        ['tier_id' => $tier->id, 'rank_id' => $rank->id, 'valid_start' => null],
                        [
                            'mgmt_fee_rate' => $mgmt,
                            'referral_fee_rate' => self::REFERRAL_FEE_PER_TIER[$code],
                        ],
                    );
                }
            }
        }

        $this->command?->info('  commission_tiers: '.CommissionTier::count());
        $this->command?->info('  commission_tier_rank_rates: '.CommissionTierRankRate::count());
    }
}
