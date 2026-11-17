<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicyStatusLookup extends Model
{
    protected $table = 'policy_statuses';

    protected $guarded = ['id'];

    /** @var array<string, array{name_th: ?string, group_name_th: ?string}>|null per-request cache of code → labels */
    private static ?array $byCode = null;

    /**
     * Canonical Thai label for a computed status CODE (active, expired, …).
     * Used for computed-first display (D9) so an expired policy reads หมดอายุ,
     * not the stale legacy label captured at migration time.
     */
    public static function labelFor(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::codeMap()[$code]['name_th'] ?? null;
    }

    /** Canonical group label for a computed status code. */
    public static function groupFor(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::codeMap()[$code]['group_name_th'] ?? null;
    }

    /** @return array<string, array{name_th: ?string, group_name_th: ?string}> */
    private static function codeMap(): array
    {
        if (self::$byCode === null) {
            self::$byCode = self::query()
                ->whereNotNull('code')
                ->get(['code', 'name_th', 'group_name_th'])
                ->keyBy('code')
                ->map(fn ($r) => ['name_th' => $r->name_th, 'group_name_th' => $r->group_name_th])
                ->all();
        }

        return self::$byCode;
    }
}
