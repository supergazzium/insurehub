<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CommissionTier;
use App\Models\ProductType;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the initial product-type taxonomy per tenant.
 *
 * Non-life types match Sheet2 row 54 of the source Excel (the header row
 * of the carrier × product-type matrix). Each type is pre-assigned to a
 * tier based on the row-54 color coding:
 *   - SKYBLUE cells → TIER_FULL
 *   - YELLOW cells  → TIER_PARTIAL
 *   - RED cells     → TIER_DIRECT_ONLY
 *
 * Life types are seeded for MGM commission compatibility (Life products
 * use PR #5's per-product age×sum×year table for their BASE rate, but
 * still need a tier assignment for mgmt fee + referral). Admin can
 * reassign any type to any tier at any time.
 *
 * updateOrCreate keyed by (tenant, code) so re-running preserves admin
 * renames (name_th / name_en / notes) but overwrites the seeded
 * tier assignment. Admins editing tier via API/UI won't be affected
 * by re-seeding because tier_id is not in the update payload.
 */
class ProductTypeSeeder extends Seeder
{
    /**
     * Non-life product types from Sheet2 row 54 of the source Excel.
     * Format: [code, name_th, name_en, sub_of, tier_code, sort_order]
     */
    private const NON_LIFE_TYPES = [
        ['MOTOR_CLASS1_GARAGE',    'มอเตอร์ ชั้น 1 (อู่)',         'Motor Class 1 (Garage)',        'Motor', 'TIER_FULL', 10],
        ['MOTOR_CLASS1_DEALER',    'มอเตอร์ ชั้น 1 (ห้าง)',        'Motor Class 1 (Dealer)',         'Motor', 'TIER_FULL', 20],
        ['MOTOR_CLASS23',          'มอเตอร์ ชั้น 2+/3+/2/3',        'Motor Class 2+/3+/2/3',          'Motor', 'TIER_FULL', 30],
        ['MOTOR_HEAVY_GARAGE',     'มอเตอร์รถหนัก (อู่)',          'Motor Heavy Vehicle (Garage)',   'Motor', 'TIER_FULL', 40],
        ['MOTOR_HEAVY_DEALER',     'มอเตอร์รถหนัก (ห้าง)',         'Motor Heavy Vehicle (Dealer)',   'Motor', 'TIER_FULL', 50],
        ['MOTOR_HEAVY_CLASS23',    'มอเตอร์รถหนัก ชั้น 2+/3+/2/3', 'Motor Heavy Class 2+/3+/2/3',    'Motor', 'TIER_FULL', 60],
        ['PORROR_CAR',             'พรบ (เก๋ง/กระบะ/ตู้)',         'Compulsory (Car)',               'Compulsory', 'TIER_PARTIAL', 70],
        ['PORROR_OTHER',           'พรบ (อื่นๆ)',                    'Compulsory (Other)',             'Compulsory', 'TIER_PARTIAL', 80],
        ['PA_INDIVIDUAL',          'PA รายเดี่ยว',                   'Personal Accident (Individual)', 'PA',    'TIER_FULL', 90],
        ['TA_INDIVIDUAL',          'TA รายเดี่ยว (ท่องเที่ยว)',      'Travel Accident (Individual)',   'Travel', 'TIER_FULL', 100],
        ['FIRE_HOUSE_BASIC',       'Fire บ้าน (พื้นฐาน)',            'Fire House (Basic)',             'Fire',  'TIER_FULL', 110],
        ['FIRE_SME_BASIC',         'Fire SME (พื้นฐาน)',             'Fire SME (Basic)',               'Fire',  'TIER_FULL', 120],
        ['FIRE_HOUSE_PACKAGE',     'Fire บ้าน (Package)',            'Fire House (Package)',           'Fire',  'TIER_FULL', 130],
        ['FIRE_SME_PACKAGE',       'Fire SME (Package)',             'Fire SME (Package)',             'Fire',  'TIER_FULL', 140],
        ['IAR_CAR_EAR',            'IAR / CAR / EAR',                'IAR / CAR / EAR',                'Property', 'TIER_DIRECT_ONLY', 150],
        ['MARINE',                 'ประกันขนส่ง + Marine',           'Cargo + Marine',                 'Marine', 'TIER_PARTIAL', 160],
        ['MISC',                   'MISC',                            'Miscellaneous',                  'Other', 'TIER_DIRECT_ONLY', 170],
        ['HEALTH_ADULT',           'ประกันสุขภาพผู้ใหญ่',             'Health (Adult)',                 'Health', 'TIER_PARTIAL', 180],
        ['HEALTH_CHILD',           'ประกันสุขภาพเด็ก',                'Health (Child)',                 'Health', 'TIER_PARTIAL', 190],
        ['GROUP_ACCIDENT',         'ประกันกลุ่ม อุบัติเหตุ',           'Group Accident',                 'Group', 'TIER_DIRECT_ONLY', 200],
        ['GROUP_HEALTH',           'ประกันกลุ่ม สุขภาพ',              'Group Health',                   'Group', 'TIER_DIRECT_ONLY', 210],
    ];

    /**
     * Life types. All default to TIER_FULL — the same mgmt fee curve as
     * high-margin non-life products. Admin can reassign per business decision.
     */
    private const LIFE_TYPES = [
        ['WHOLE_LIFE_STANDARD',    'ประกันชีวิตตลอดชีพ (ประเภทสามัญ)', 'Whole Life (Standard)',       'Life', 'TIER_FULL', 300],
        ['ENDOWMENT_STANDARD',     'ประกันสะสมทรัพย์ (ประเภทสามัญ)',   'Endowment (Standard)',        'Life', 'TIER_FULL', 310],
        ['ANNUITY',                'ประกันบำนาญ',                     'Annuity',                     'Life', 'TIER_FULL', 320],
        ['TERM',                   'ประกันชีวิตชั่วระยะเวลา',          'Term Life',                   'Life', 'TIER_FULL', 330],
        ['LIFE_RIDER',             'สัญญาเพิ่มเติม (Rider)',           'Life Rider',                  'Life', 'TIER_PARTIAL', 340],
    ];

    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $this->command?->warn('No tenants found — skip product-type seeding.');

            return;
        }

        foreach ($tenants as $tenant) {
            // Cache per-tenant tier lookup so seeding N types × 1 tier query isn't N queries.
            $tiersByCode = CommissionTier::query()
                ->where('tenant_id', $tenant->id)
                ->pluck('id', 'code');

            if ($tiersByCode->isEmpty()) {
                $this->command?->error("Tenant {$tenant->id} has no commission_tiers — run CommissionTierSeeder first.");

                continue;
            }

            $all = array_merge(self::NON_LIFE_TYPES, self::LIFE_TYPES);
            foreach ($all as [$code, $nameTh, $nameEn, $subOf, $tierCode, $sort]) {
                $tierId = $tiersByCode->get($tierCode);
                if ($tierId === null) {
                    $this->command?->warn("Tenant {$tenant->id}: tier {$tierCode} missing — skipping {$code}");

                    continue;
                }
                ProductType::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $code],
                    [
                        'name_th' => $nameTh,
                        'name_en' => $nameEn,
                        'sub_of' => $subOf,
                        'tier_id' => $tierId,
                        'sort_order' => $sort,
                        'active' => true,
                    ],
                );
            }
        }

        $this->command?->info('  product_types: '.ProductType::count());
    }
}
