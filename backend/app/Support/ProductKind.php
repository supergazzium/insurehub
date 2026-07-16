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
 *   property — fire / property (property block)
 *   health   — non-motor misc (บริษัทฯประเภทเบ็ดเตล็ด: health, PA, travel)
 *   other    — rider, tax, unclassified
 */
final class ProductKind
{
    public static function derive(string $type, string $category, string $subCategory2): string
    {
        if ($subCategory2 === 'Motor' || $type === 'Motor') {
            return 'motor';
        }
        if ($type === 'Life' || $type === 'Group-Life') {
            return 'life';
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
