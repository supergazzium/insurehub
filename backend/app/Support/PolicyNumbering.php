<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Serial-number allocators for policy identifiers.
 *
 * `Q<YY><4-digit>` for quote_no, `A<YY><6-digit>` for application_no —
 * the Access `application_code` pattern the legacy importer writes.
 *
 * Extracted from QuoteController in C-11 so PolicyController's draft
 * promotion endpoints can call the same allocator without duplicating
 * the MAX+1 SQL. Keeps allocator behavior consistent regardless of
 * which endpoint mints the number.
 */
class PolicyNumbering
{
    /** Q<YY><4-digit-serial>. Called by /quotes POST + draft promote. */
    public static function nextQuoteNo(int $tenantId, ?Carbon $now = null): string
    {
        $now = $now ?? Carbon::now();
        $prefix = 'Q'.$now->format('y');
        $maxNum = (int) DB::table('policies')
            ->where('tenant_id', $tenantId)
            ->where('quote_no', 'like', $prefix.'%')
            ->whereRaw('SUBSTRING(quote_no, ?) REGEXP \'^[0-9]+$\'', [strlen($prefix) + 1])
            ->max(DB::raw('CAST(SUBSTRING(quote_no, '.(strlen($prefix) + 1).') AS UNSIGNED)'));
        $next = $maxNum + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** A<YY><6-digit-serial>. Called by /quotes/convert + draft promote. */
    public static function nextApplicationNo(int $tenantId, ?Carbon $now = null): string
    {
        $now = $now ?? Carbon::now();
        $prefix = 'A'.$now->format('y');
        $maxNum = (int) DB::table('policies')
            ->where('tenant_id', $tenantId)
            ->where('application_no', 'like', $prefix.'%')
            ->whereRaw('SUBSTRING(application_no, ?) REGEXP \'^[0-9]+$\'', [strlen($prefix) + 1])
            ->max(DB::raw('CAST(SUBSTRING(application_no, '.(strlen($prefix) + 1).') AS UNSIGNED)'));
        $next = $maxNum + 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
