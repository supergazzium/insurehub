<?php

declare(strict_types=1);

namespace App\Services\Format;

/**
 * Currency-to-Thai-words converter. Ports Access's `BahtText()` from
 * AccessExportFUnCtion_K.bas — same algorithm, same edge cases.
 *
 * Examples:
 *   1234.56  → "หนึ่งพันสองร้อยสามสิบสี่บาทห้าสิบหกสตางค์"
 *   1234.00  → "หนึ่งพันสองร้อยสามสิบสี่บาทถ้วน"
 *   -100.00  → "ลบหนึ่งร้อยบาทถ้วน"
 *   0        → "ศูนย์บาทถ้วน"
 *   21       → "ยี่สิบเอ็ดบาทถ้วน"        (21 uses "ยี่สิบเอ็ด", not "สองสิบหนึ่ง")
 */
final class BahtText
{
    private const DIGITS = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
    private const UNITS = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน'];

    public static function convert(float $amount): string
    {
        if ($amount === 0.0) return 'ศูนย์บาทถ้วน';

        $negative = $amount < 0;
        $amount = abs($amount);

        // Split into baht (integer part) + satang (fractional × 100), rounded.
        $baht = (int) floor($amount);
        $satang = (int) round(($amount - $baht) * 100);
        // Watch for the .995 case that rounds up into the next baht.
        if ($satang === 100) { $baht++; $satang = 0; }

        $text = self::readNumber($baht).'บาท';
        $text .= $satang > 0 ? self::readNumber($satang).'สตางค์' : 'ถ้วน';

        return $negative ? 'ลบ'.$text : $text;
    }

    /**
     * Read an integer as Thai words. Splits at millions ("ล้าน") recursively
     * so amounts >= 10 million are still pronounced correctly.
     */
    private static function readNumber(int $n): string
    {
        if ($n === 0) return '';
        if ($n >= 1_000_000) {
            return self::readNumber(intdiv($n, 1_000_000)).'ล้าน'.self::readNumber($n % 1_000_000);
        }

        $s = str_pad((string) $n, 6, '0', STR_PAD_LEFT);  // pad to 6 digits: แสน→หน่วย
        $out = '';
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $d = (int) $s[$i];
            $place = $len - 1 - $i;   // 5=แสน, 0=หน่วย
            if ($d === 0) continue;

            // Special-case irregularities that Access replicated:
            //   - "หนึ่ง" in the ones place after a tens place becomes "เอ็ด"
            //   - "สอง" in the tens place becomes "ยี่"
            //   - "หนึ่ง" in the tens place is just "สิบ" (silent one)
            if ($place === 1 && $d === 1) {
                $out .= 'สิบ';
            } elseif ($place === 1 && $d === 2) {
                $out .= 'ยี่สิบ';
            } elseif ($place === 0 && $d === 1 && $len > 1 && (int) $s[$len - 2] > 0) {
                $out .= 'เอ็ด';
            } else {
                $out .= self::DIGITS[$d].self::UNITS[$place];
            }
        }
        return $out;
    }
}
