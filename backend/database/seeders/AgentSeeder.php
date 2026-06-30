<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Bank;
use App\Models\Tenant;
use Database\Seeders\Concerns\SeedsFromCsv;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    use SeedsFromCsv;

    public function run(): void
    {
        $tenantId = Tenant::where('slug', 'insurehub')->value('id');
        $banks = Bank::pluck('id', 'name_th')->all();

        $rows = $this->readCsv('agent_para.csv');

        // First pass: create everyone without parent FK.
        foreach ($rows as $row) {
            $code = $this->nonEmpty($row['Insure_Agent_Code'] ?? null);
            if ($code === null) {
                continue;
            }
            $agentType = strtoupper(substr($code, 0, 2)); // AG / IN
            Agent::updateOrCreate(
                ['tenant_id' => $tenantId, 'agent_code' => $code],
                [
                    'agent_type' => $agentType,
                    'first_name' => $this->nonEmpty($row['Agent_Name_Thai'] ?? null),
                    'last_name' => $this->nonEmpty($row['Agent_Surname_Thai'] ?? null),
                    'first_name_en' => $this->nonEmpty($row['Agent_Name_Eng'] ?? null),
                    'last_name_en' => $this->nonEmpty($row['Agent_Surname_Eng'] ?? null),
                    'gender' => $this->mapGender($row['Gender'] ?? null),
                    'email' => $this->nonEmpty($row['Email'] ?? null),
                    'email2' => $this->nonEmpty($row['Email2'] ?? null),
                    'phone' => $this->nonEmpty($row['Mobile_phone'] ?? null),
                    'tel_phone' => $this->nonEmpty($row['Tel_Phone'] ?? null),
                    'line_id' => $this->nonEmpty($row['Line_ID'] ?? null),
                    'facebook_name' => $this->nonEmpty($row['Facebook_Name'] ?? null),
                    'id_card' => $this->nonEmpty($row['Agent_Tax_ID'] ?? null),
                    'tax_id' => $this->nonEmpty($row['Agent_Tax_ID'] ?? null),
                    'birth_date' => $this->parseDate($row['Birth_date'] ?? null),
                    'address' => $this->nonEmpty($row['Address_no'] ?? null),
                    'building_floor' => $this->nonEmpty($row['Building_Floor'] ?? null),
                    'moo' => $this->nonEmpty($row['Moo'] ?? null),
                    'moo_ban' => $this->nonEmpty($row['Moo_ban'] ?? null),
                    'soi' => $this->nonEmpty($row['Soi'] ?? null),
                    'road' => $this->nonEmpty($row['Road'] ?? null),
                    'sub_district' => $this->nonEmpty($row['Kwang'] ?? null),
                    'district' => $this->nonEmpty($row['Khet'] ?? null),
                    'province' => $this->nonEmpty($row['Province'] ?? null),
                    'postcode' => $this->nonEmpty($row['Zip_Code'] ?? null),
                    'vat_type' => $this->mapVatType($row['VAT_TYPE'] ?? null),
                    'bank_id' => $banks[$row['Bank_name'] ?? ''] ?? null,
                    'bank_name_text' => $this->nonEmpty($row['Bank_name'] ?? null),
                    'bank_account_no' => $this->nonEmpty($row['Bank_Account'] ?? null),
                    'bank_account_name' => $this->nonEmpty($row['Bank_Account_name'] ?? null),
                    'license_life_no' => $this->nonEmpty($row['LicenseLife_No'] ?? null),
                    'license_life_expiry' => $this->parseDate($row['Exp_Life'] ?? null),
                    'license_non_life_no' => $this->nonEmpty($row['LicenseNon_Life_No'] ?? null),
                    'license_non_life_expiry' => $this->parseDate($row['Exp_Non_Life'] ?? null),
                    'team' => $this->nonEmpty($row['Team'] ?? null),
                    'team_lv1' => $this->nonEmpty($row['Team_LV1'] ?? null),
                    'team_lv2' => $this->nonEmpty($row['Team_LV2'] ?? null),
                    'team_no' => $this->nonEmpty($row['Team_No'] ?? null),
                    'mlm_upline_2' => $this->nonEmpty($row['MLM_Upline2'] ?? null),
                    'joined_at' => $this->parseDate($row['Date_Apply'] ?? null),
                    'head_start_date_raw' => $this->nonEmpty($row['Head_Start_Date'] ?? null),
                    'grace_period_end_raw' => $this->nonEmpty($row['Grace_period_End'] ?? null),
                    'source' => $this->nonEmpty($row['Source'] ?? null),
                    'active' => (($row['Head_Status'] ?? '') === 'Active'),
                ],
            );
        }

        // Second pass: wire parent_agent_id from MLM_Upline (FK by code).
        $byCode = Agent::where('tenant_id', $tenantId)->pluck('id', 'agent_code')->all();
        foreach ($rows as $row) {
            $code = $this->nonEmpty($row['Insure_Agent_Code'] ?? null);
            $upline = $this->nonEmpty($row['MLM_Upline'] ?? null);
            if ($code === null || $upline === null || ! isset($byCode[$code], $byCode[$upline])) {
                continue;
            }
            Agent::where('id', $byCode[$code])->update(['parent_agent_id' => $byCode[$upline]]);
        }

        $this->command?->info('  agents: '.Agent::where('tenant_id', $tenantId)->count());
    }

    private function mapGender(?string $raw): string
    {
        $raw = trim((string) $raw);
        return match ($raw) {
            'M', 'm' => 'male',
            'F', 'f' => 'female',
            default => '',
        };
    }

    private function mapVatType(?string $raw): string
    {
        $raw = trim((string) $raw);
        // Access stores 1/2/3 — frontend uses '' | 'none' | 'vat7' | 'wht1' | 'wht3' | 'wht5'.
        // Until the team confirms the mapping, keep the raw value as-is.
        return $raw;
    }
}
