<?php

declare(strict_types=1);

namespace App\Services\Format;

use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Buddhist Era date formatter. Ports the token logic from
 * AccessExportDateF.bas (`DFormat`), simplified — most callers just need a
 * standard Thai-format date with a BE year.
 *
 *   BuddhistDate::format($carbon)            → "14 ก.ค. 2569"
 *   BuddhistDate::format($carbon, 'long')    → "14 กรกฎาคม 2569"
 *   BuddhistDate::format($carbon, 'compact') → "14/07/69"
 */
final class BuddhistDate
{
    /** @var array<int, string> */
    private const MONTH_SHORT = [
        1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
        'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
    ];

    /** @var array<int, string> */
    private const MONTH_LONG = [
        1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
    ];

    public static function format(?DateTimeInterface $dt, string $style = 'short'): string
    {
        if ($dt === null) return '';
        $day = (int) $dt->format('j');
        $mo = (int) $dt->format('n');
        $beYear = (int) $dt->format('Y') + 543;

        return match ($style) {
            'long' => sprintf('%d %s %d', $day, self::MONTH_LONG[$mo], $beYear),
            'compact' => sprintf('%02d/%02d/%02d', $day, $mo, $beYear % 100),
            default => sprintf('%d %s %d', $day, self::MONTH_SHORT[$mo], $beYear),   // short
        };
    }
}
