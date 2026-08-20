<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared MOD-11 checksum for Thai national IDs and juristic-registration
 * numbers. Both use the same 13-digit format and the same weighting
 * (positions 1..12 × weights 13..2, sum, then (11 - sum % 11) % 10 must
 * equal digit 13). Kept as a lightweight static helper so both the
 * AgentRegisterRequest and CustomerRequest FormRequest classes call the
 * same code path — a bad ID card can't slip past one and not the other.
 */
final class ThaiIdentifier
{
    public static function isValid(string $digits): bool
    {
        if (preg_match('/^\d{13}$/', $digits) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $digits[$i]) * (13 - $i);
        }
        $check = (11 - ($sum % 11)) % 10;
        return $check === (int) $digits[12];
    }
}
