<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Serial-number allocators for policy identifiers.
 *
 * - `Q<YY><4-digit>`         quote_no        (per-year serial)
 * - `A<YYMMDD><4-digit>`     application_no  (per-DAY serial, e.g. A2605290003)
 * - `J<YYMMDD><4-digit>`     job_no          (per-DAY serial, e.g. J2608270001)
 *
 * The `A<YYMMDD>NNNN` pattern matches the legacy Access `application_code`
 * the importer wrote (verified against production data: every historical
 * application_no is `A` + 6-digit date + 4-digit daily sequence). The daily
 * sequence resets each calendar day.
 *
 * Extracted from QuoteController in C-11 so PolicyController's draft/promotion
 * endpoints call the same allocator without duplicating the MAX+1 SQL.
 */
class PolicyNumbering
{
    /** Q<YY><4-digit-serial> — per-year. Called by /quotes POST + draft promote. */
    public static function nextQuoteNo(int $tenantId, ?Carbon $now = null): string
    {
        $now = $now ?? Carbon::now();
        $prefix = 'Q'.$now->format('y');

        return $prefix.self::nextSerial($tenantId, 'quote_no', $prefix, 4);
    }

    /** A<YYMMDD><4-digit-serial> — per-day. e.g. A2605290003. */
    public static function nextApplicationNo(int $tenantId, ?Carbon $now = null): string
    {
        $now = $now ?? Carbon::now();
        $prefix = 'A'.$now->format('ymd');

        return $prefix.self::nextSerial($tenantId, 'application_no', $prefix, 4);
    }

    /** J<YYMMDD><4-digit-serial> — per-day work/job number. e.g. J2608270001. */
    public static function nextJobNo(int $tenantId, ?Carbon $now = null): string
    {
        $now = $now ?? Carbon::now();
        $prefix = 'J'.$now->format('ymd');

        return $prefix.self::nextSerial($tenantId, 'job_no', $prefix, 4);
    }

    /**
     * MAX(numeric-suffix)+1 over rows whose <column> starts with <prefix>,
     * zero-padded to <width>. The suffix regex guard skips any legacy value
     * that isn't purely numeric after the prefix.
     */
    private static function nextSerial(int $tenantId, string $column, string $prefix, int $width): string
    {
        $suffixStart = strlen($prefix) + 1;
        $maxNum = (int) DB::table('policies')
            ->where('tenant_id', $tenantId)
            ->where($column, 'like', $prefix.'%')
            ->whereRaw("SUBSTRING({$column}, ?) REGEXP '^[0-9]+$'", [$suffixStart])
            ->max(DB::raw("CAST(SUBSTRING({$column}, {$suffixStart}) AS UNSIGNED)"));

        return str_pad((string) ($maxNum + 1), $width, '0', STR_PAD_LEFT);
    }
}
