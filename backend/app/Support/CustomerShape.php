<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves the TRUE customer type and the correct name/id field placement
 * from the messy legacy Client export, for the importer's stepCustomers.
 *
 * The legacy Type_Cust code (1 individual / 2 corporate / 3 foreign) is only a
 * hint — the real type is confirmed from content signals (company keywords in
 * the name, a juristic 0-prefixed tax id, a passport-only record). Corporate
 * records store the company name split across title/name/surname
 * (บริษัท | <body> | จำกัด) and the tax id in the national_id column; this
 * helper reassembles them into juristic_name / tax_id and leaves the
 * person-only fields blank.
 */
final class CustomerShape
{
    /** Thai + English tokens that mark a juristic (company) record. */
    private const COMPANY_TOKENS = [
        'บริษัท', 'ห้างหุ้นส่วน', 'หจก', 'หจก.', 'บมจ', 'บจก',
        'company', 'co.,ltd', 'co., ltd', 'ltd', 'limited', 'partnership', 'corporation',
    ];

    /** Personal-title tokens — presence means the row is a person, not a company. */
    private const PERSON_TITLES = [
        'นาย', 'นาง', 'นางสาว', 'น.ส', 'ด.ช', 'ด.ญ', 'คุณ',
        'mr', 'mrs', 'miss', 'ms', 'master', 'dr',
    ];

    /**
     * @param  array{type_code:?string,title_th:?string,name_th:?string,surname_th:?string,national_id:?string,passport:?string}  $row
     * @return array{customer_type:string,juristic_name:?string,first_name:?string,last_name:?string,tax_id:?string,id_card:?string,passport:?string}
     */
    public static function resolve(array $row): array
    {
        $title = trim((string) ($row['title_th'] ?? ''));
        $name = trim((string) ($row['name_th'] ?? ''));
        $surname = trim((string) ($row['surname_th'] ?? ''));
        $nid = self::digits($row['national_id'] ?? null);
        $passport = trim((string) ($row['passport'] ?? '')) ?: null;
        $code = (string) ($row['type_code'] ?? '');

        $looksCompany = self::hasCompanyToken("$title $name $surname");
        $looksPerson = self::hasPersonTitle($title);
        $juristicTax = self::isJuristicTaxId($nid);        // 13 digits starting with 0

        // --- decide the true type ---
        // Company wins when the name reads like a company, when the legacy code
        // says corporate (a personal-looking title like the gender-neutral "คุณ"
        // does NOT override that — the 25 mis-titled corporates), or when a
        // juristic 0-prefixed tax id is present. Only a type-1/3 record with no
        // company signal stays a person.
        if ($looksCompany || $code === '2' || ($juristicTax && ! $looksPerson)) {
            $type = 'corporate';
        } elseif ($code === '3' || ($passport !== null && $nid === null)) {
            // Type 3 = foreign; also any passport-only record with no Thai ID.
            $type = 'foreign_individual';
        } else {
            $type = 'individual';
        }

        // --- place fields per the resolved type ---
        if ($type === 'corporate') {
            // Reassemble the full legal name: บริษัท <body> จำกัด. A personal
            // title (the 25 mis-titled corporates) is dropped from the name.
            $parts = array_filter([
                self::hasPersonTitle($title) ? '' : $title,
                $name,
                $surname,
            ], fn ($p) => trim((string) $p) !== '');
            $juristic = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));

            return [
                'customer_type' => 'corporate',
                'juristic_name' => $juristic !== '' ? $juristic : null,
                'first_name' => null,
                'last_name' => null,
                'tax_id' => $nid,          // the company reg / tax number
                'id_card' => null,
                'passport' => null,
            ];
        }

        if ($type === 'foreign_individual') {
            return [
                'customer_type' => 'foreign_individual',
                'juristic_name' => null,
                'first_name' => $name ?: null,
                'last_name' => self::cleanDash($surname),
                'tax_id' => null,
                'id_card' => $nid,          // usually null for foreigners
                'passport' => $passport,
            ];
        }

        // individual — but a company tax id on a person is kept in tax_id, not id_card
        return [
            'customer_type' => 'individual',
            'juristic_name' => null,
            'first_name' => $name ?: null,
            'last_name' => self::cleanDash($surname),
            'tax_id' => $juristicTax ? $nid : null,
            'id_card' => $juristicTax ? null : $nid,
            'passport' => $passport,
        ];
    }

    private static function hasCompanyToken(string $s): bool
    {
        $l = mb_strtolower($s);
        foreach (self::COMPANY_TOKENS as $t) {
            if (str_contains($l, mb_strtolower($t))) {
                return true;
            }
        }

        return false;
    }

    private static function hasPersonTitle(string $title): bool
    {
        $l = mb_strtolower(trim($title));
        if ($l === '') {
            return false;
        }
        foreach (self::PERSON_TITLES as $t) {
            if (str_starts_with($l, mb_strtolower($t))) {
                return true;
            }
        }

        return false;
    }

    private static function isJuristicTaxId(?string $digits): bool
    {
        return $digits !== null && strlen($digits) === 13 && str_starts_with($digits, '0');
    }

    private static function digits(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $d = preg_replace('/\D+/', '', $v);

        return $d === '' ? null : $d;
    }

    /** Legacy exports use "-" as a blank placeholder for surname. */
    private static function cleanDash(?string $v): ?string
    {
        $v = trim((string) $v);

        return ($v === '' || $v === '-') ? null : $v;
    }
}
