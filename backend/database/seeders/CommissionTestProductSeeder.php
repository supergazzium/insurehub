<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\Product;
use App\Models\ProductCommissionRate;
use App\Models\ProductType;
use Illuminate\Database\Seeder;

/**
 * C-20 test fixtures: four products across the requested types, each with a
 * DISTINCT hub->agent (and carrier->hub) commission so the commission-snapshot
 * behaviour is easy to eyeball in the wizard and exercise end-to-end.
 *
 * The resolver dispatches on carrier.insure_type (MgmCommissionEngine), NOT
 * product.type — so:
 *   - Life products (whole-life + endowment) attach to a Life carrier and use
 *     scheme=life_years (per-year vector yr_1..yr_11_up), resolved by
 *     LifeRateResolver.
 *   - Non-life products (motor + fire) attach to a Non-Life carrier and use
 *     scheme=flat, resolved by NonLifeRateResolver.
 *
 * Idempotent: products keyed on (tenant, code); rate rows on
 * (tenant, product, direction, effective_from=null) — the same single-row
 * upsert semantics the ProductController uses. Re-running only refreshes the
 * rates, so it's safe on an existing DB.
 *
 * Codes are prefixed CT-<KIND> so they're easy to find and delete.
 */
class CommissionTestProductSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = (int) (Product::query()->value('tenant_id') ?? 1);

        // Pick ACTIVE carriers only — the wizard filters its carrier dropdown
        // by active=1, so an inactive carrier would make the product
        // unreachable in the UI.
        $lifeCarrier = Carrier::query()
            ->where('tenant_id', $tenantId)->where('insure_type', 'Life')
            ->where('active', true)->orderBy('id')->firstOrFail();
        $nonLifeCarrier = Carrier::query()
            ->where('tenant_id', $tenantId)->where('insure_type', 'Non-Life')
            ->where('active', true)->orderBy('id')->firstOrFail();

        // [code, name_th, product_type code, product.type, carrier, scheme, rates]
        // rates: flat => ['flat'=>x]; life_years => ['yr_1'=>..,'yr_2'=>..,...]
        $specs = [
            [
                'code' => 'CT-LIFE-WL',
                'name' => '[TEST] ประกันชีวิตตลอดชีพ',
                'typeCode' => 'WHOLE_LIFE_STANDARD',
                'type' => 'life',
                'carrier' => $lifeCarrier,
                'scheme' => ProductCommissionRate::SCHEME_LIFE_YEARS,
                'hubToAgent' => ['yr_1' => 0.40, 'yr_2' => 0.10, 'yr_3' => 0.05, 'yr_4' => 0.05, 'yr_5' => 0.05, 'yr_6_10' => 0.02, 'yr_11_up' => 0.01],
                'carrierToHub' => ['yr_1' => 0.55, 'yr_2' => 0.12, 'yr_3' => 0.06, 'yr_4' => 0.06, 'yr_5' => 0.06, 'yr_6_10' => 0.03, 'yr_11_up' => 0.02],
                'duration' => 99, 'pay' => 99,
            ],
            [
                'code' => 'CT-LIFE-ENDOW',
                'name' => '[TEST] ประกันสะสมทรัพย์',
                'typeCode' => 'ENDOWMENT_STANDARD',
                'type' => 'life',
                'carrier' => $lifeCarrier,
                'scheme' => ProductCommissionRate::SCHEME_LIFE_YEARS,
                'hubToAgent' => ['yr_1' => 0.18, 'yr_2' => 0.08, 'yr_3' => 0.04, 'yr_4' => 0.04, 'yr_5' => 0.04, 'yr_6_10' => 0.01, 'yr_11_up' => 0.01],
                'carrierToHub' => ['yr_1' => 0.25, 'yr_2' => 0.10, 'yr_3' => 0.05, 'yr_4' => 0.05, 'yr_5' => 0.05, 'yr_6_10' => 0.02, 'yr_11_up' => 0.01],
                'duration' => 15, 'pay' => 15,
            ],
            [
                'code' => 'CT-MOTOR-C1',
                'name' => '[TEST] ประกันรถยนต์ชั้น 1',
                'typeCode' => 'MOTOR_CLASS1_GARAGE',
                'type' => 'motor',
                'carrier' => $nonLifeCarrier,
                'scheme' => ProductCommissionRate::SCHEME_FLAT,
                'hubToAgent' => ['flat' => 0.12],
                'carrierToHub' => ['flat' => 0.18],
                'duration' => 1, 'pay' => 1,
            ],
            [
                'code' => 'CT-FIRE-HOUSE',
                'name' => '[TEST] ประกันอัคคีภัยบ้านอยู่อาศัย',
                'typeCode' => 'FIRE_HOUSE_BASIC',
                'type' => 'non_life',
                'carrier' => $nonLifeCarrier,
                'scheme' => ProductCommissionRate::SCHEME_FLAT,
                'hubToAgent' => ['flat' => 0.23],
                'carrierToHub' => ['flat' => 0.30],
                'duration' => 1, 'pay' => 1,
            ],
        ];

        foreach ($specs as $s) {
            $type = ProductType::query()->where('code', $s['typeCode'])->firstOrFail();

            $product = Product::updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $s['code']],
                [
                    'carrier_id' => $s['carrier']->id,
                    'name' => $s['name'],
                    'type' => $s['type'],
                    'product_type_id' => $type->id,
                    'commission_tier_id' => $type->tier_id,
                    'coverage' => 100000,
                    'duration_years' => $s['duration'],
                    'pay_years' => $s['pay'],
                    'premium_mode' => 'annual',
                    'min_premium' => 0,
                    'max_premium' => 1000000,
                    'min_age' => 0,
                    'max_age' => 99,
                    'gender' => 'all',
                    'require_medical' => false,
                    'smoker_accepted' => true,
                    'preexisting_excluded' => false,
                    'active' => true,
                ],
            );

            $this->upsertRate($tenantId, $product->id, ProductCommissionRate::DIRECTION_HUB_TO_AGENT, $s['scheme'], $s['hubToAgent']);
            $this->upsertRate($tenantId, $product->id, ProductCommissionRate::DIRECTION_CARRIER_TO_HUB, $s['scheme'], $s['carrierToHub']);

            $rate = $s['scheme'] === ProductCommissionRate::SCHEME_FLAT
                ? (($s['hubToAgent']['flat'] ?? 0) * 100).'% flat'
                : (($s['hubToAgent']['yr_1'] ?? 0) * 100).'% yr1';
            $this->command?->info("  {$s['code']} ({$s['type']}) → hub→agent {$rate}  [carrier {$s['carrier']->code}, product #{$product->id}]");
        }

        $this->command?->info('Seeded 4 commission-test products (CT-LIFE-WL, CT-LIFE-ENDOW, CT-MOTOR-C1, CT-FIRE-HOUSE).');
    }

    /**
     * Single-row upsert per (tenant, product, direction, effective_from=null),
     * matching the resolver's newest-effective single-row assumption. Clears
     * every rate column first, then sets the ones this scheme uses.
     *
     * @param array<string,float> $rates
     */
    private function upsertRate(int $tenantId, int $productId, string $direction, string $scheme, array $rates): void
    {
        $columns = array_fill_keys(
            ['flat_rate', 'yr_1', 'yr_2', 'yr_3', 'yr_4', 'yr_5', 'yr_6_10', 'yr_11_up'],
            null,
        );

        if ($scheme === ProductCommissionRate::SCHEME_FLAT) {
            $columns['flat_rate'] = $rates['flat'] ?? null;
        } else {
            foreach (ProductCommissionRate::LIFE_YEAR_COLUMNS as $col) {
                $columns[$col] = $rates[$col] ?? null;
            }
        }

        ProductCommissionRate::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'direction' => $direction,
                'effective_from' => null,
            ],
            ['scheme' => $scheme] + $columns,
        );
    }
}
