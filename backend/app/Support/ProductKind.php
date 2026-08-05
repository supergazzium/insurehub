<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Collapses the legacy Access taxonomy (Life / Motor / Non-Motor / Rider / Tax +
 * category text in Thai) into a single UI-facing bucket used by the create-policy
 * wizard to decide which conditional block to render on step 3.
 *
 * Kept as a single source of truth so ProductResource and ProductListResource
 * emit the same value.
 *
 * Buckets:
 *   motor    — motor products (any Motor sub_category_2 or type)
 *   life     — life + group-life (beneficiaries required)
 *   travel   — travel insurance (ประกันการเดินทาง sub-category)
 *   property — fire / property (property block)
 *   health   — non-motor misc (health, PA, general)
 *   other    — rider, tax, unclassified
 *
 * All comparisons are case-insensitive: production data stores `type` as
 * lowercase (`life`, `motor`, `group`, `other`) while the create-modal's
 * newer taxonomy writes `Life`, `Motor`, `Group-Life`, etc. Both must
 * bucket the same way.
 *
 * We also probe `subCategory` (the fine-grained bucket like
 * `ประกันการเดินทาง`) not just `subCategory2` (the coarse Motor/Non-Motor
 * bucket) so travel/property/etc. can be distinguished cleanly.
 */
final class ProductKind
{
    public static function derive(
        string $type,
        string $category,
        string $subCategory2,
        string $subCategory = '',
    ): string {
        $t = strtolower($type);
        $sc2 = strtolower($subCategory2);
        // sub_category_2 is the coarse Motor/Non-Motor bucket and is the
        // most reliable signal in production data (the free-text `type`
        // column has been used loosely — many non-motor rows carry
        // `type=motor` because the field is misused historically). When
        // sub_category_2 says Motor, trust it; otherwise it says Non-Motor
        // (or blank) and we fall through to category/sub-category checks.
        if ($sc2 === 'motor') {
            return 'motor';
        }
        // Only trust type=motor when sub_category_2 is blank (no coarse
        // signal available at all) — otherwise a blank sub_category_2 with
        // an unrelated non-motor category might get misclassified.
        if ($sc2 === '' && $t === 'motor' && !str_contains($category, 'เบ็ดเตล็ด') && !str_contains($category, 'อัคคีภัย')) {
            return 'motor';
        }
        if ($t === 'life' || $t === 'group-life' || $t === 'group') {
            return 'life';
        }
        // Travel is a specific sub-category under "การประกันภัยเบ็ดเตล็ด".
        // Check it *before* the generic เบ็ดเตล็ด → health fallback below.
        if (str_contains($subCategory, 'เดินทาง')) {
            return 'travel';
        }
        if (str_contains($category, 'อัคคีภัย')) {
            return 'property';
        }
        if (str_contains($category, 'เบ็ดเตล็ด')) {
            return 'health';
        }
        return 'other';
    }
}
