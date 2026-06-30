<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\Location;
use App\Models\MotorMarketGroup;
use App\Models\MotorVehicle;
use App\Models\NamePrefix;
use App\Models\Nationality;
use App\Models\Occupation;
use App\Models\PaidStatus;
use App\Models\PaymentInscompStatus;
use App\Models\PaymentInscompTo;
use App\Models\PaymentMethod;
use App\Models\PolicyStatusLookup;
use App\Models\Religion;
use Database\Seeders\Concerns\SeedsFromCsv;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LookupTablesSeeder extends Seeder
{
    use SeedsFromCsv;

    public function run(): void
    {
        $this->seedBanks();
        $this->seedNationalities();
        $this->seedReligions();
        $this->seedOccupations();
        $this->seedPrefixes();
        $this->seedLocations();
        $this->seedPolicyStatuses();
        $this->seedPaidStatuses();
        $this->seedPaymentMethods();
        $this->seedPaymentInscompStatuses();
        $this->seedPaymentInscompTos();
        $this->seedMotorMarketGroups();
        $this->seedMotorVehicles();
    }

    private function seedBanks(): void
    {
        foreach ($this->readCsv('bankname_para.csv') as $row) {
            Bank::updateOrCreate(
                ['name_th' => $row['Bank_name']],
                ['active' => true],
            );
        }
        $this->command?->info('  banks: '.Bank::count());
    }

    private function seedNationalities(): void
    {
        foreach ($this->readCsv('nation.csv') as $row) {
            Nationality::updateOrCreate(
                ['nation_name_th' => $row['nation_name_th']],
                [
                    'iso2' => $this->nonEmpty($row['nation_id'] ?? null),
                    'iso3' => $this->nonEmpty($row['country_id'] ?? null),
                    'nation_name_en' => $this->nonEmpty($row['nation_name_en'] ?? null),
                    'country_name_th' => $this->nonEmpty($row['country_name_th'] ?? null),
                    'country_name_en' => $this->nonEmpty($row['country_name_en'] ?? null),
                ],
            );
        }
        $this->command?->info('  nationalities: '.Nationality::count());
    }

    private function seedReligions(): void
    {
        foreach ($this->readCsv('religion.csv') as $row) {
            Religion::updateOrCreate(
                ['name_th' => $row['religion_des_th']],
                ['name_en' => $this->nonEmpty($row['religion_des_en'] ?? null)],
            );
        }
        $this->command?->info('  religions: '.Religion::count());
    }

    private function seedOccupations(): void
    {
        foreach ($this->readCsv('occupation.csv') as $row) {
            Occupation::updateOrCreate(
                ['access_code' => $row['occ_Code']],
                [
                    'type' => $this->nonEmpty($row['occ_Type'] ?? null),
                    'name_th' => $row['occ_des_th'] ?? '',
                    'name_en' => $this->nonEmpty($row['occ_des_en'] ?? null),
                ],
            );
        }
        $this->command?->info('  occupations: '.Occupation::count());
    }

    private function seedPrefixes(): void
    {
        foreach ($this->readCsv('prefix_para.csv') as $row) {
            NamePrefix::updateOrCreate(
                [
                    'insured_type_id' => (int) $row['Insured_Type_ID'],
                    'title_code' => (int) $row['Title_Code'],
                ],
                [
                    'insured_type' => $row['Insured_Type'],
                    'description_th' => $row['Description_TH'] ?? '',
                    'description_en' => $this->nonEmpty($row['Description_EN'] ?? null),
                ],
            );
        }
        $this->command?->info('  name_prefixes: '.NamePrefix::count());
    }

    private function seedLocations(): void
    {
        // 7,460 rows — bulk insert in chunks.
        $rows = $this->readCsv('location.csv');
        DB::table('locations')->truncate();
        $now = now();
        $chunks = array_chunk($rows, 500);
        foreach ($chunks as $chunk) {
            $payload = array_map(static fn ($r) => [
                'location_code' => $r['Location_Code'] ?? null,
                'province' => $r['province'] ?? '',
                'amphur' => $r['amphur'] ?? '',
                'district' => $r['district'] ?? '',
                'zip' => $r['zip'] ?? '',
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);
            DB::table('locations')->insert($payload);
        }
        $this->command?->info('  locations: '.Location::count());
    }

    private function seedPolicyStatuses(): void
    {
        // Map Access Thai statuses to frontend code (PolicyStatus union).
        // The frontend union: quote|application|submitted|issued|active|lapsed|cancelled|reinstated|expired
        // We keep the Access labels as-is; mapping to codes is left to import script.
        foreach ($this->readCsv('policies_status_para.csv') as $row) {
            PolicyStatusLookup::updateOrCreate(
                ['name_th' => $row['Policies_status']],
                ['group_name_th' => $this->nonEmpty($row['G_Policies_status'] ?? null)],
            );
        }
        $this->command?->info('  policy_statuses: '.PolicyStatusLookup::count());
    }

    private function seedPaidStatuses(): void
    {
        DB::table('paid_statuses')->truncate();
        foreach ($this->readCsv('paidstatus_para.csv') as $row) {
            PaidStatus::create([
                'group_name_th' => $row['G_paid_status'] ?? '',
                'name_th' => $row['paid_status'] ?? '',
                'finance_comp' => $this->nonEmpty($row['FinanceComp'] ?? null),
            ]);
        }
        $this->command?->info('  paid_statuses: '.PaidStatus::count());
    }

    private function seedPaymentMethods(): void
    {
        // Map Thai → frontend PaymentMethod union codes.
        $codeMap = [
            'เงินสด' => 'cash',
            'ตัดบัตร' => 'creditCard',
            'เงินโอน' => 'bankTransfer',
        ];
        foreach ($this->readCsv('payment_method_para.csv') as $row) {
            $name = $row['payment_method_des'] ?? '';
            PaymentMethod::updateOrCreate(
                ['name_th' => $name],
                ['code' => $codeMap[$name] ?? null],
            );
        }
        $this->command?->info('  payment_methods: '.PaymentMethod::count());
    }

    private function seedPaymentInscompStatuses(): void
    {
        foreach ($this->readCsv('payment_inscomp_status_para.csv') as $row) {
            PaymentInscompStatus::updateOrCreate(
                ['name_th' => $row['Payment_InsComp_Status_des'] ?? ''],
                [],
            );
        }
        $this->command?->info('  payment_inscomp_statuses: '.PaymentInscompStatus::count());
    }

    private function seedPaymentInscompTos(): void
    {
        foreach ($this->readCsv('payment_inscomp_to_para.csv') as $row) {
            PaymentInscompTo::updateOrCreate(
                ['name_th' => $row['Payment_InsComp_to_des'] ?? ''],
                [],
            );
        }
        $this->command?->info('  payment_inscomp_tos: '.PaymentInscompTo::count());
    }

    private function seedMotorMarketGroups(): void
    {
        foreach ($this->readCsv('motormarketgrouppara.csv') as $row) {
            MotorMarketGroup::updateOrCreate(
                ['group_code' => $row['VEHICLE_MARKET_GROUP'] ?? ''],
                [
                    'desc_en' => $row['Desc_Eng'] ?? '',
                    'desc_th' => $row['Desc_Thai'] ?? '',
                    'redbook_type' => $this->nonEmpty($row['Redbook_Type'] ?? null),
                ],
            );
        }
        $this->command?->info('  motor_market_groups: '.MotorMarketGroup::count());
    }

    private function seedMotorVehicles(): void
    {
        // 32k rows — stream and bulk insert.
        DB::table('motor_vehicles')->truncate();
        $groups = MotorMarketGroup::pluck('id', 'group_code')->all();
        $now = now();
        $buffer = [];
        $flush = function () use (&$buffer): void {
            if ($buffer !== []) {
                DB::table('motor_vehicles')->insert($buffer);
                $buffer = [];
            }
        };
        foreach ($this->streamCsv('motor_para.csv') as $r) {
            $buffer[] = $this->buildMotorRow($r, $groups, $now);
            if (count($buffer) >= 1000) {
                DB::table('motor_vehicles')->insert($buffer);
                $buffer = [];
            }
        }
        $flush();
        $this->command?->info('  motor_vehicles: '.MotorVehicle::count());
    }

    /**
     * @param  array<string,string>  $r
     * @param  array<string,int>  $groups
     * @return array<string,mixed>
     */
    private function buildMotorRow(array $r, array $groups, \Illuminate\Support\Carbon $now): array
    {
        return [
                    'serial' => $this->nonEmpty($r['SERIAL'] ?? null),
                    'brand_code' => $this->nonEmpty($r['Brand_CODE'] ?? null),
                    'model_code' => $this->nonEmpty($r['MODEL_CODE'] ?? null),
                    'submodel_code' => $this->nonEmpty($r['SUBMODEL_CODE'] ?? null),
                    'vehicle_brand' => $this->nonEmpty($r['Vehicle_Brand'] ?? null),
                    'vehicle_model' => $this->nonEmpty($r['Vehicle_model'] ?? null),
                    'vehicle_submodel' => $this->nonEmpty($r['Vehicle_submodel'] ?? null),
                    'vh_year_beg' => $this->nonEmpty($r['VH_YEAR_BEG'] ?? null),
                    'vh_year_end' => $this->nonEmpty($r['VH_YEAR_END'] ?? null),
                    'cover_01_flag' => $this->nonEmpty($r['COVER_01_FLAG'] ?? null),
                    'cover_plus_flag' => $this->nonEmpty($r['COVER_PLUS_FLAG'] ?? null),
                    'motor_market_group_id' => $groups[$r['VEHICLE_MARKET_GROUP'] ?? ''] ?? null,
                    'sedan_type' => $this->nonEmpty($r['SEDAN_TYPE'] ?? null),
                    'sell_stat' => $this->nonEmpty($r['SELL_STAT'] ?? null),
                    'submodel_year' => $this->nonEmpty($r['SUBMODEL_YEAR'] ?? null),
                    'effect_date' => $this->parseDate($r['EFFECT_DATE'] ?? null),
                    'scale' => $this->nonEmpty($r['SCALE'] ?? null),
                    'weight' => $this->nonEmpty($r['WEIGHT'] ?? null),
                    'cc' => $this->nonEmpty($r['CC'] ?? null),
                    'seat_no' => $this->nonEmpty($r['SEAT_NO'] ?? null),
                    'chassis_no_format' => $this->nonEmpty($r['CHASSIS_NO_FORMAT'] ?? null),
                    'engine_no_format' => $this->nonEmpty($r['ENGINE_NO_FORMAT'] ?? null),
                    'standard_sumins' => $this->num($r['STANDARD_SUMINS'] ?? null),
                    'standard_theft' => $this->num($r['STANDARD_THEFT'] ?? null),
                    'eighty_sumins' => $this->num($r['80_SUMINS'] ?? null),
                    'car_group' => $this->nonEmpty($r['CAR_GROUP'] ?? null),
                    'old_std_sumins' => $this->num($r['OLD_STD_SUMINS'] ?? null),
                    'old_std_theft' => $this->num($r['OLD_STD_THEFT'] ?? null),
                    'model_status' => $this->nonEmpty($r['MODEL_STATUS'] ?? null),
                    'rbm_serial' => $this->nonEmpty($r['rbm_serial'] ?? null),
                    'redbook_code' => $this->nonEmpty($r['Redbook_Code'] ?? null),
                    'created_at' => $now,
                    'updated_at' => $now,
        ];
    }
}
