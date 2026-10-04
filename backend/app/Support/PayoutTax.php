<?php

declare(strict_types=1);

namespace App\Support;

final class PayoutTax
{
    public const VAT_NONE = '1';

    public const VAT_EXCLUDE = '2';

    public const VAT_INCLUDE = '3';

    public static function satang(float|int|string|null $baht): int
    {
        if ($baht === null || $baht === '') {
            return 0;
        }
        if (is_string($baht) && preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', trim($baht), $m) === 1) {
            $value = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

            return $m[1] === '-' ? -$value : $value;
        }

        return (int) round(((float) $baht) * 100);
    }

    public static function baht(int $satang): float
    {
        return $satang / 100;
    }

    public static function basisPoints(float|int|string|null $percent): int
    {
        return (int) round(((float) $percent) * 100);
    }

    public static function prorate(int $satang, int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $numerator <= 0 || $satang <= 0) {
            return 0;
        }
        if ($numerator >= $denominator) {
            return $satang;
        }

        return intdiv(2 * $satang * $numerator + $denominator, 2 * $denominator);
    }

    public static function compute(int $gross, int $deduction, string $vatType, int $whtBasisPoints): array
    {
        $after = max(0, $gross - $deduction);
        if ($vatType === self::VAT_EXCLUDE) {
            $taxable = $after;
            $vat = intdiv(14 * $after + 100, 200);
        } elseif ($vatType === self::VAT_INCLUDE) {
            $taxable = intdiv(200 * $after + 107, 214);
            $vat = $after - $taxable;
        } else {
            $taxable = $after;
            $vat = 0;
        }
        $wht = intdiv(2 * max(0, $whtBasisPoints) * $taxable + 10000, 20000);
        $total = $taxable + $vat;

        return [
            'gross' => $gross,
            'deduction' => min($deduction, $gross),
            'afterDeduction' => $after,
            'taxable' => $taxable,
            'vat' => $vat,
            'total' => $total,
            'wht' => $wht,
            'net' => $total - $wht,
        ];
    }

    public static function computeBaht(float $gross, float $deduction, string $vatType, float $whtPercent): array
    {
        return array_map(
            fn (int $v): float => self::baht($v),
            self::compute(self::satang($gross), self::satang($deduction), $vatType, self::basisPoints($whtPercent)),
        );
    }

    public static function bahtText(float $amount): string
    {
        $satang = self::satang(round($amount, 2));
        if ($satang === 0) {
            return 'ศูนย์บาทถ้วน';
        }
        $baht = intdiv($satang, 100);
        $st = $satang % 100;
        $text = $baht > 0 ? self::thaiNumber($baht).'บาท' : '';

        return $text.($st > 0 ? self::thaiNumber($st).'สตางค์' : 'ถ้วน');
    }

    private static function thaiNumber(int $n): string
    {
        if ($n >= 1000000) {
            $rest = $n % 1000000;

            return self::thaiNumber(intdiv($n, 1000000)).'ล้าน'.($rest > 0 ? self::thaiNumber($rest) : '');
        }
        $digits = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
        $units = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];
        $chars = array_reverse(str_split((string) $n));
        $out = '';
        foreach ($chars as $pos => $ch) {
            $d = (int) $ch;
            if ($d === 0) {
                continue;
            }
            if ($pos === 0 && $d === 1 && $n > 10) {
                $word = 'เอ็ด';
            } elseif ($pos === 1 && $d === 1) {
                $word = '';
            } elseif ($pos === 1 && $d === 2) {
                $word = 'ยี่';
            } else {
                $word = $digits[$d];
            }
            $out = $word.$units[$pos].$out;
        }

        return $out;
    }
}
