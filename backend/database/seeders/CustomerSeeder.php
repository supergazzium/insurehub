<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Nationality;
use App\Models\Occupation;
use App\Models\Religion;
use App\Models\Tenant;
use Database\Seeders\Concerns\SeedsFromCsv;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    use SeedsFromCsv;

    public function run(): void
    {
        $tenantId = Tenant::where('slug', 'insurehub')->value('id');
        $nationalities = Nationality::pluck('id', 'nation_name_th')->all();
        $religions = Religion::pluck('id', 'name_th')->all();
        $occupations = Occupation::pluck('id', 'name_th')->all();

        foreach ($this->readCsv('client.csv') as $row) {
            $code = $this->nonEmpty($row['Client_Code'] ?? null);
            if ($code === null) {
                continue;
            }
            $annual = $this->num($row['Cus_Annually_Income'] ?? null);
            Customer::updateOrCreate(
                ['tenant_id' => $tenantId, 'customer_code' => $code],
                [
                    'customer_type' => (($row['Type_Cust'] ?? '1') === '2') ? 'corporate' : 'individual',
                    'title_th' => $this->nonEmpty($row['Name_Title_Thai'] ?? null),
                    'title_en' => $this->nonEmpty($row['Name_Title_Eng'] ?? null),
                    'first_name' => $this->nonEmpty($row['Name_Thai'] ?? null),
                    'last_name' => $this->nonEmpty($row['Surname_Thai'] ?? null),
                    'first_name_en' => $this->nonEmpty($row['Name_Eng'] ?? null),
                    'last_name_en' => $this->nonEmpty($row['Surname_Eng'] ?? null),
                    'id_card' => $this->nonEmpty($row['National_ID'] ?? null),
                    'national_id_expiry' => $this->parseDate($row['National_ID_ExpD'] ?? null),
                    'passport' => $this->nonEmpty($row['Passport'] ?? null),
                    'nationality' => $this->nonEmpty($row['Nationality'] ?? null),
                    'nationality_id' => $nationalities[$row['Nationality'] ?? ''] ?? null,
                    'religion' => $this->nonEmpty($row['Religion'] ?? null),
                    'religion_id' => $religions[$row['Religion'] ?? ''] ?? null,
                    'race' => $this->nonEmpty($row['Race'] ?? null),
                    'gender' => $this->mapGender($row['Gender'] ?? null),
                    'birth_date' => $this->parseDate($row['Birth_date'] ?? null),
                    'occupation' => $this->nonEmpty($row['Occupation'] ?? null),
                    'occupation_id' => $occupations[$row['Occupation'] ?? ''] ?? null,
                    'position' => $this->nonEmpty($row['Position_held'] ?? null),
                    'employer_name' => $this->nonEmpty($row['Occupation_Company'] ?? null),
                    'monthly_income' => $annual > 0 ? round($annual / 12, 2) : 0,
                    'annual_income_raw' => $annual ?: null,
                    'phone' => $this->nonEmpty($row['Mobile_phone'] ?? null),
                    'tel_phone' => $this->nonEmpty($row['Tel_Phone'] ?? null),
                    'line_id' => $this->nonEmpty($row['Line_ID'] ?? null),
                    'email' => $this->nonEmpty($row['Email1'] ?? null),
                    'email2' => $this->nonEmpty($row['Email2'] ?? null),
                    'facebook_name' => $this->nonEmpty($row['Facebook_Name'] ?? null),

                    // Permanent (Per_*) address.
                    'address' => $this->nonEmpty($row['Per_Address_no'] ?? null),
                    'building_floor' => $this->nonEmpty($row['Per_Building_Floor'] ?? null),
                    'moo' => $this->nonEmpty($row['Per_Moo'] ?? null),
                    'moo_ban' => $this->nonEmpty($row['Per_Moo_ban'] ?? null),
                    'soi' => $this->nonEmpty($row['Per_Soi'] ?? null),
                    'road' => $this->nonEmpty($row['Per_Road'] ?? null),
                    'sub_district' => $this->nonEmpty($row['Per_Kwang'] ?? null),
                    'amphoe' => $this->nonEmpty($row['Per_Khet'] ?? null),
                    'province' => $this->nonEmpty($row['Per_Province'] ?? null),
                    'postcode' => $this->nonEmpty($row['Per_Zip_Code'] ?? null),

                    // Mailing address.
                    'mailing_same_as_registered' => $this->mailingMatchesPermanent($row),
                    'mailing_address' => $this->nonEmpty($row['Mailing_Address_no'] ?? null),
                    'mailing_sub_district' => $this->nonEmpty($row['Mailing_Kwang'] ?? null),
                    'mailing_district' => $this->nonEmpty($row['Mailing_Khet'] ?? null),
                    'mailing_province' => $this->nonEmpty($row['Mailing_Province'] ?? null),
                    'mailing_postcode' => $this->nonEmpty($row['Mailing_Zip_Code'] ?? null),

                    // Corporate contact.
                    'contact_name' => $this->nonEmpty($row['Contact_person'] ?? null),
                    'contact_phone' => $this->nonEmpty($row['Contact_Mobile_phone'] ?? null),
                    'contact_email' => $this->nonEmpty($row['Contact_Email'] ?? null),
                    'contact_person_receive' => $this->nonEmpty($row['Contact_person_receive'] ?? null),
                    'contact_person_receive_address' => $this->nonEmpty($row['Contact_person_receive_address'] ?? null),

                    'active' => true,
                ],
            );
        }
        $this->command?->info('  customers: '.Customer::where('tenant_id', $tenantId)->count());
    }

    private function mapGender(?string $raw): string
    {
        $raw = trim((string) $raw);
        return match ($raw) {
            'ชาย', 'M', 'm' => 'male',
            'หญิง', 'F', 'f' => 'female',
            '' => '',
            default => 'other',
        };
    }

    private function mailingMatchesPermanent(array $row): bool
    {
        // Quick equality check on the major fields.
        return ($row['Mailing_Address_no'] ?? '') === ($row['Per_Address_no'] ?? '')
            && ($row['Mailing_Province'] ?? '') === ($row['Per_Province'] ?? '')
            && ($row['Mailing_Zip_Code'] ?? '') === ($row['Per_Zip_Code'] ?? '');
    }
}
