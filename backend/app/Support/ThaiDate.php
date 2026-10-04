<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

final class ThaiDate
{
    private const MONTHS = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

    private const SHORT = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    public static function long(?string $date): string
    {
        if (blank($date)) {
            return '—';
        }
        $d = Carbon::parse($date);

        return $d->day.' '.self::MONTHS[$d->month].' '.($d->year + 543);
    }

    public static function short(?string $date): string
    {
        if (blank($date)) {
            return '—';
        }
        $d = Carbon::parse($date);

        return $d->day.' '.self::SHORT[$d->month].' '.(($d->year + 543) % 100);
    }

    public static function monthYear(?string $date): string
    {
        if (blank($date)) {
            return '—';
        }
        $d = Carbon::parse($date);

        return self::MONTHS[$d->month].' '.($d->year + 543);
    }
}
