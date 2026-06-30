<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\Product;
use App\Models\ProductCommissionRate;
use App\Models\Tenant;
use Database\Seeders\Concerns\SeedsFromCsv;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    use SeedsFromCsv;

    public function run(): void
    {
        $tenantId = Tenant::where('slug', 'insurehub')->value('id');
        $carriers = Carrier::where('tenant_id', $tenantId)->pluck('id', 'code')->all();

        $seen = [];
        foreach ($this->readCsv('main_product.csv') as $row) {
            $code = $this->nonEmpty($row['Product_Code'] ?? null);
            if ($code === null) {
                continue;
            }
            $carrierId = $carriers[$row['INC_Code'] ?? ''] ?? null;
            if ($carrierId === null) {
                continue; // skip products tied to unknown carrier
            }

            $product = $seen[$code] ?? Product::updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                [
                    'carrier_id' => $carrierId,
                    'name' => $row['Product_Name'] ?? $code,
                    'type' => $this->mapProductType($row),
                    'category' => $this->nonEmpty($row['Categories'] ?? null),
                    'sub_category' => $this->nonEmpty($row['Sub_Categories'] ?? null),
                    'sub_category_2' => $this->nonEmpty($row['Sub_Categories2'] ?? null),
                    'main_rider' => $this->nonEmpty($row['Main_Rider'] ?? null),
                    'min_age' => (int) ($this->num($row['minAge'] ?? null) ?: 0),
                    'max_age' => (int) ($this->num($row['maxAge'] ?? null) ?: 99),
                    'min_sum_assure' => $this->num($row['minSumAssure'] ?? null),
                    'max_sum_assure' => $this->num($row['maxSumAssure'] ?? null),
                    'min_rar' => $this->num($row['minRAR'] ?? null),
                    'max_rar' => $this->num($row['maxRAR'] ?? null),
                    'active' => true,
                ],
            );
            $seen[$code] = $product;

            // One commission-rate row per Access row (versioned by Valid_start/Valid_End).
            ProductCommissionRate::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'commission_code' => $this->nonEmpty($row['Commission_Code'] ?? null),
                ],
                array_merge(
                    [
                        'valid_start' => $this->parseDate($row['Valid_start'] ?? null),
                        'valid_end' => $this->parseDate($row['Valid_End'] ?? null),
                    ],
                    $this->extractRates($row),
                ),
            );
        }
        $this->command?->info('  products: '.Product::where('tenant_id', $tenantId)->count());
        $this->command?->info('  product_commission_rates: '.ProductCommissionRate::count());
    }

    /** @return array<string,float|null> */
    private function extractRates(array $row): array
    {
        $out = [];
        for ($y = 1; $y <= 11; $y++) {
            $suffix = $y === 1 ? '' : ($y === 11 ? '_11Up' : "_{$y}");
            $key = $y === 11 ? '11up' : (string) $y;
            $out["com_rate_yr_{$key}"] = $this->num($row["ComCommission{$suffix}"] ?? null) ?: null;
            $out["ag_rate_yr_{$key}"] = $this->num($row["AgCommission{$suffix}"] ?? null) ?: null;
            $out["in_rate_yr_{$key}"] = $this->num($row["InCommission{$suffix}"] ?? null) ?: null;
        }
        return $out;
    }

    private function mapProductType(array $row): ?string
    {
        $type = trim((string) ($row['Insure_Type'] ?? ''));
        $sub = trim((string) ($row['Sub_Categories2'] ?? ''));
        if ($type === 'Life') {
            return 'life';
        }
        if (stripos($sub, 'motor') !== false) {
            return 'motor';
        }
        return 'non_life';
    }
}
