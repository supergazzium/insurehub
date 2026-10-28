<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Policy;
use App\Models\Product;

/**
 * Classifies a policy into one of three business buckets for display:
 *   life      — ประกันชีวิต
 *   cmi       — พรบ (compulsory motor, Compulsory Motor Insurance)
 *   non_life  — ประกันวินาศภัย (everything else: motor voluntary, fire, health, …)
 *
 * Single source of truth so every surface (payout list, statements, reports)
 * labels a policy the same way. Order matters: พรบ is detected before the
 * generic motor/non-life bucket because a CMI product's `kind` is still
 * `motor`.
 */
final class PolicyTypeLabel
{
    public const LIFE = 'life';

    public const CMI = 'cmi';

    public const NON_LIFE = 'non_life';

    public const LABELS = [
        self::LIFE => 'ประกันชีวิต',
        self::CMI => 'พรบ',
        self::NON_LIFE => 'ประกันวินาศภัย',
    ];

    /** Classify a policy. Expects `product` (and ideally `product.productType`) loaded. */
    public static function classify(Policy $policy): string
    {
        $product = $policy->product;

        if (self::isCompulsory($policy, $product)) {
            return self::CMI;
        }
        if (Freelook::isLife($product)) {
            return self::LIFE;
        }

        return self::NON_LIFE;
    }

    public static function label(Policy $policy): string
    {
        return self::LABELS[self::classify($policy)] ?? self::LABELS[self::NON_LIFE];
    }

    /** พรบ signals: product type/code marked CMI/พรบ, or only a compulsory premium. */
    private static function isCompulsory(Policy $policy, ?Product $product): bool
    {
        $type = strtolower((string) ($product->type ?? ''));
        if ($type === 'cmi' || $type === 'compulsory' || $type === 'porror') {
            return true;
        }
        $code = strtoupper((string) ($product?->productType?->code ?? ''));
        if (str_starts_with($code, 'PORROR')) {
            return true;
        }
        // A policy that carries only a compulsory premium (no voluntary main
        // premium) is a standalone พรบ.
        $compulsory = (float) ($policy->compulsory_premium ?? 0);
        $main = (float) ($policy->main_premium ?? 0);

        return $compulsory > 0 && $main <= 0;
    }
}
