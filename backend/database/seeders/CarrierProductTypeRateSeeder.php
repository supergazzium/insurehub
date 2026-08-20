<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\CarrierProductTypeRate;
use App\Models\ProductType;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the (carrier × product-type) standard commission matrix from
 * Sheet2 rows 56-79 of the source Excel.
 *
 * Only seeds cells for CARRIERS that already exist in the DB (via
 * CarrierSeeder). Carriers in the Excel that don't exist yet are
 * skipped — admin creates them, then re-runs the matrix seeder.
 *
 * Idempotent via updateOrCreate keyed by (tenant, carrier, product-type,
 * valid_start=null). Rate values from the Excel are patterned:
 *   - Most (carrier × non-motor / non-Fire) cells: 9%
 *   - Motor Class 1 (garage): 10% for all carriers
 *   - Motor Class 1 (dealer) / Class 2+/3+/2/3: 9% for all
 *   - Fire packages vary (9% for most, 12-15% for a few carriers)
 *   - "-" in the Excel means "carrier doesn't sell this" → null standard_rate
 *
 * The complete matrix isn't reproduced here row-by-row — CarrierSeeder
 * doesn't guarantee every Excel carrier exists. Ops will complete the
 * matrix via the admin UI (PR-A5). This seeder is enough to:
 *   1. Prove the schema works end-to-end
 *   2. Give the engine (PR-D) something to look up for common carriers
 */
class CarrierProductTypeRateSeeder extends Seeder
{
    /**
     * Common pattern from Sheet2 — applies to MSIG, TOKIO, TIP, IND, BKI,
     * ERGO, SOMPO, KPI, AIO, AIG, VIB, BUI, NVK, TNI, Deves. Individual
     * carriers override cells via CARRIER_OVERRIDES.
     */
    private const COMMON_PATTERN = [
        'MOTOR_CLASS1_GARAGE' => 0.10,
        'MOTOR_CLASS1_DEALER' => 0.09,
        'MOTOR_CLASS23' => 0.10,
        'MOTOR_HEAVY_GARAGE' => 0.09,
        'MOTOR_HEAVY_DEALER' => 0.09,
        'MOTOR_HEAVY_CLASS23' => 0.09,
        'PORROR_CAR' => 0.07,
        'PORROR_OTHER' => null,
        'PA_INDIVIDUAL' => 0.09,
        'TA_INDIVIDUAL' => 0.09,
        'FIRE_HOUSE_BASIC' => 0.09,
        'FIRE_SME_BASIC' => 0.09,
        'FIRE_HOUSE_PACKAGE' => 0.09,
        'FIRE_SME_PACKAGE' => 0.09,
        'IAR_CAR_EAR' => 0.09,
        'MARINE' => 0.09,
        'MISC' => 0.09,
        'HEALTH_ADULT' => null,
        'HEALTH_CHILD' => null,
        'GROUP_ACCIDENT' => null,
        'GROUP_HEALTH' => null,
    ];

    /**
     * Per-carrier overrides for cells that DIFFER from COMMON_PATTERN.
     *
     * @var array<string, array<string, float|null>|string>
     */
    private const CARRIER_OVERRIDES = [
        'Allianz' => [
            'FIRE_HOUSE_BASIC' => 0.15,
            'FIRE_SME_BASIC' => 0.15,
            'FIRE_HOUSE_PACKAGE' => 0.12,
            'FIRE_SME_PACKAGE' => 0.12,
            'HEALTH_ADULT' => 0.05,
            'HEALTH_CHILD' => 0.05,
        ],
        'MTI' => [
            'HEALTH_ADULT' => 0.05,
            'HEALTH_CHILD' => 0.05,
        ],
        'TIP' => [
            'HEALTH_ADULT' => 0.09,
            'HEALTH_CHILD' => 0.05,
        ],
        'IND' => [
            'FIRE_HOUSE_BASIC' => 0.12,
            'FIRE_SME_BASIC' => 0.12,
            'FIRE_HOUSE_PACKAGE' => 0.12,
            'FIRE_SME_PACKAGE' => 0.12,
        ],
        'BKI' => [
            'HEALTH_ADULT' => 0.09,
            'HEALTH_CHILD' => 0.05,
        ],
        'MITTARE' => [
            'PORROR_CAR' => 0.06,
            'PA_INDIVIDUAL' => 0.05,
            'TA_INDIVIDUAL' => 0.05,
        ],
        'AXA' => [
            'PA_INDIVIDUAL' => 0.05,
            'TA_INDIVIDUAL' => 0.05,
            'HEALTH_ADULT' => 0.09,
            'HEALTH_CHILD' => 0.05,
        ],
        'TPB' => [
            'PORROR_CAR' => 0.19,
            'PORROR_OTHER' => 0.06,
        ],
        'CHUBB' => [
            'FIRE_HOUSE_BASIC' => 0.12,
            'FIRE_SME_BASIC' => 0.12,
            'FIRE_HOUSE_PACKAGE' => 0.12,
            'FIRE_SME_PACKAGE' => 0.12,
            'HEALTH_ADULT' => 0.09,
            'HEALTH_CHILD' => 0.05,
        ],
        'VIB' => [
            'HEALTH_ADULT' => 0.09,
            'HEALTH_CHILD' => 0.05,
        ],
        // Health-only carriers — everything else is null.
        'PACIFIC' => 'health_only',
        'LUMA' => 'health_only',
        'APRIL' => 'health_only',
    ];

    private const HEALTH_ONLY_PATTERN = [
        'HEALTH_ADULT' => 0.09,
        'HEALTH_CHILD' => 0.09,
    ];

    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $this->command?->warn('No tenants found — skip carrier×type rate seeding.');

            return;
        }

        foreach ($tenants as $tenant) {
            $carriersByCode = Carrier::query()
                ->where('tenant_id', $tenant->id)
                ->pluck('id', 'code');
            $typesByCode = ProductType::query()
                ->where('tenant_id', $tenant->id)
                ->pluck('id', 'code');

            if ($carriersByCode->isEmpty() || $typesByCode->isEmpty()) {
                $this->command?->warn("Tenant {$tenant->id}: carriers or product_types empty — skipping matrix.");

                continue;
            }

            foreach ($carriersByCode as $carrierCode => $carrierId) {
                $rates = $this->ratesForCarrier($carrierCode);
                foreach ($rates as $typeCode => $rate) {
                    $typeId = $typesByCode->get($typeCode);
                    if ($typeId === null) {
                        continue; // product_type not seeded — safe to skip
                    }
                    CarrierProductTypeRate::updateOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'carrier_id' => $carrierId,
                            'product_type_id' => $typeId,
                            'valid_start' => null,
                        ],
                        [
                            'standard_rate' => $rate,
                        ],
                    );
                }
            }
        }

        $this->command?->info('  carrier_product_type_rates: '.CarrierProductTypeRate::count());
    }

    /**
     * Resolve the effective rate map for a carrier by folding overrides
     * over the common pattern.
     *
     * @return array<string, float|null>
     */
    private function ratesForCarrier(string $carrierCode): array
    {
        $override = self::CARRIER_OVERRIDES[$carrierCode] ?? null;

        if ($override === 'health_only') {
            $out = array_fill_keys(array_keys(self::COMMON_PATTERN), null);
            foreach (self::HEALTH_ONLY_PATTERN as $type => $rate) {
                $out[$type] = $rate;
            }

            return $out;
        }

        return array_merge(self::COMMON_PATTERN, is_array($override) ? $override : []);
    }
}
