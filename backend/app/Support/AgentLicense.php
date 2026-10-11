<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Agent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class AgentLicense
{
    public static function isValid(Agent $agent, ?CarbonInterface $on = null): bool
    {
        $day = ($on ?? Carbon::now())->copy()->startOfDay();

        return self::holds($agent->license_life_no, $agent->license_life_expiry, $day)
            || self::holds($agent->license_non_life_no, $agent->license_non_life_expiry, $day);
    }

    public static function validUntil(Agent $agent): ?string
    {
        $dates = [];
        $day = Carbon::now()->startOfDay();
        if (self::holds($agent->license_life_no, $agent->license_life_expiry, $day)) {
            $dates[] = $agent->license_life_expiry->toDateString();
        }
        if (self::holds($agent->license_non_life_no, $agent->license_non_life_expiry, $day)) {
            $dates[] = $agent->license_non_life_expiry->toDateString();
        }

        return $dates === [] ? null : max($dates);
    }

    private static function holds(?string $number, mixed $expiry, CarbonInterface $day): bool
    {
        if ($number === null || trim($number) === '' || $expiry === null) {
            return false;
        }

        return Carbon::parse($expiry)->startOfDay()->greaterThanOrEqualTo($day);
    }
}
