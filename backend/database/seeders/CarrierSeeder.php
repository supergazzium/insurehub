<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\Tenant;
use Database\Seeders\Concerns\SeedsFromCsv;
use Illuminate\Database\Seeder;

class CarrierSeeder extends Seeder
{
    use SeedsFromCsv;

    public function run(): void
    {
        $tenantId = Tenant::where('slug', 'insurehub')->value('id');
        if ($tenantId === null) {
            throw new \RuntimeException('TenantSeeder must run before CarrierSeeder.');
        }

        // Access: Insure_company (45 rows).
        foreach ($this->readCsv('insure_company.csv') as $row) {
            $code = $this->nonEmpty($row['INC_Code'] ?? null);
            if ($code === null) {
                continue;
            }
            Carrier::updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                [
                    'name' => $row['INC_Name_TH'] ?? $code,
                    'name_en' => $this->nonEmpty($row['INC_Name_En'] ?? null),
                    'nickname_th' => $this->nonEmpty($row['INC_nickname'] ?? null),
                    'insure_type' => $this->nonEmpty($row['Company_Insure_Type'] ?? null),
                    'sub_type' => $this->nonEmpty($row['Sub_Insurer_type'] ?? null),
                    'comp_insure_code' => $this->nonEmpty($row['Comp_insure_code'] ?? null),
                    'oic_insure_com_code' => $this->nonEmpty($row['OIC_InsureCom_Code'] ?? null),
                    'tax_id' => $this->nonEmpty($row['Tax_ID'] ?? null),
                    'address' => $this->nonEmpty($row['Address_for_WH'] ?? null),
                    'bank_account_1' => $this->nonEmpty($row['bank_account_1'] ?? null),
                    'active' => (($row['Status'] ?? '') === 'Active'),
                ],
            );
        }
        // Hand-added carriers that aren't in Insure_company.xlsx but are in
        // the broker's recipient list (Email Template/Recipient list.xlsx).
        Carrier::updateOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'MSI'],
            [
                'name' => 'บริษัท เอ็มเอสไอจี ประกันภัย (ประเทศไทย) จำกัด (มหาชน)',
                'name_en' => 'MSIG Insurance (Thailand) Public Company Limited',
                'nickname_th' => 'เอ็มเอสไอจี',
                'insure_type' => 'Non-Life',
                'active' => true,
            ],
        );

        $this->command?->info('  carriers: '.Carrier::where('tenant_id', $tenantId)->count());
    }
}
