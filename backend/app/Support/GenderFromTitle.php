<?php

declare(strict_types=1);

namespace App\Support;

final class GenderFromTitle
{
    private const FEMALE_PREFIXES = ['นางสาว', 'น.ส.', 'นส.', 'นาง', 'ด.ญ.', 'พญ.', 'ทพญ.', 'mrs', 'ms', 'miss', 'madam'];

    private const MALE_PREFIXES = ['นาย', 'เด็กชาย', 'ด.ช.', 'นพ.', 'ทพ.', 'mr', 'master'];

    private const RANK_PATTERN = '/^(ว่าที่\s*)?(พล|พัน|ร้อย|จ่า|สิบ|พ\.|ร\.|จ\.|ส\.|ด\.ต|น\.อ|น\.ท|น\.ต)/u';

    public static function guess(?string $title): ?string
    {
        $t = mb_strtolower(trim((string) $title));
        if ($t === '') {
            return null;
        }
        if (str_contains($t, 'หญิง')) {
            return 'F';
        }
        foreach (self::FEMALE_PREFIXES as $p) {
            if (self::startsWith($t, $p)) {
                return 'F';
            }
        }
        foreach (self::MALE_PREFIXES as $p) {
            if (self::startsWith($t, $p)) {
                return 'M';
            }
        }

        return preg_match(self::RANK_PATTERN, $t) === 1 ? 'M' : null;
    }

    public static function normalize(?string $raw): ?string
    {
        $v = mb_strtolower(trim((string) $raw));

        return match ($v) {
            'm', 'male', 'ชาย' => 'M',
            'f', 'female', 'หญิง' => 'F',
            default => null,
        };
    }

    private static function startsWith(string $title, string $prefix): bool
    {
        if (! str_starts_with($title, $prefix)) {
            return false;
        }
        if (preg_match('/^[a-z]+$/', $prefix) !== 1) {
            return true;
        }
        $next = substr($title, strlen($prefix), 1);

        return $next === '' || $next === '.' || $next === ' ';
    }
}
