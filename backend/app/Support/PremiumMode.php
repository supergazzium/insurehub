<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * งวดการชำระเบี้ย (payment frequency) — single source of truth for which
 * frequencies a product accepts and how many installments each implies.
 *
 * A product may pin its own list in `products.allowed_premium_modes`
 * (e.g. a motor product the carrier lets customers pay monthly). When the
 * column is null the default follows the carrier's insure type:
 *   Life           → every mode
 *   Non-Life / Tax → annual only
 *
 * NOTE: `installment_mode` (A/B/C) is who bears the fee + interest, NOT the
 * frequency — never derive one from the other.
 */
final class PremiumMode
{
    /** @var list<string> */
    public const ALL = ['monthly', 'quarterly', 'semiannual', 'annual', 'single'];

    /** จำนวนงวดต่อปีตามความถี่. */
    public const INSTALLMENTS = [
        'monthly' => 12,
        'quarterly' => 4,
        'semiannual' => 2,
        'annual' => 1,
        'single' => 1,
    ];

    /** @return list<string> */
    public static function defaultsFor(?string $insureType): array
    {
        return strtolower((string) $insureType) === 'life' ? self::ALL : ['annual'];
    }

    /** @return list<string> */
    public static function allowedFor(Product $product): array
    {
        $own = $product->allowed_premium_modes;
        if (is_array($own) && $own !== []) {
            // Keep canonical order regardless of how the operator ticked them.
            return array_values(array_intersect(self::ALL, $own));
        }

        return self::defaultsFor($product->carrier?->insure_type);
    }

    public static function installmentsFor(?string $mode): int
    {
        return self::INSTALLMENTS[$mode ?? ''] ?? 1;
    }

    /**
     * Reject a frequency the product doesn't offer. No-op when either side
     * is unknown (draft autosave may post the mode before the product).
     */
    public static function assertAllowed(?Product $product, ?string $mode, string $field = 'premiumMode'): void
    {
        if ($product === null || $mode === null || $mode === '') {
            return;
        }
        $allowed = self::allowedFor($product);
        if (! in_array($mode, $allowed, true)) {
            throw ValidationException::withMessages([
                $field => ["ผลิตภัณฑ์นี้ไม่รองรับงวดการชำระ '{$mode}' (รองรับ: ".implode(', ', $allowed).')'],
            ]);
        }
    }
}
