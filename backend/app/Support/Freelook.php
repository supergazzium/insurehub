<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Policy;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

final class Freelook
{
    public const REMOTE_CHANNELS = ['telephone', 'online'];

    public const LIFE_TYPES = ['life', 'group-life', 'group'];

    public static function isLife(?Product $product): bool
    {
        if ($product === null) {
            return false;
        }

        return ProductKind::derive(
            (string) $product->type,
            (string) $product->category,
            (string) $product->sub_category_2,
            (string) $product->sub_category,
        ) === 'life';
    }

    public static function days(?Tenant $tenant, ?string $channel): int
    {
        $remote = in_array($channel, self::REMOTE_CHANNELS, true);

        return $remote
            ? (int) ($tenant?->freelook_days_remote ?? 30)
            : (int) ($tenant?->freelook_days_standard ?? 15);
    }

    public static function apply(Policy $policy): void
    {
        if ($policy->delivered_date === null) {
            return;
        }
        $product = $policy->product_id !== null ? Product::query()->find($policy->product_id) : null;
        if (! self::isLife($product)) {
            return;
        }
        $tenant = Tenant::query()->find($policy->tenant_id);
        $policy->freelook_end_date = Carbon::parse($policy->delivered_date)
            ->addDays(self::days($tenant, $policy->sales_channel))
            ->toDateString();
    }

    public static function whereLife(Builder $q, string $alias = 'pr'): Builder
    {
        return $q->whereIn("{$alias}.type", self::LIFE_TYPES)
            ->whereRaw("LOWER(COALESCE({$alias}.sub_category_2, '')) <> 'motor'");
    }
}
