<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rank;
use Illuminate\Http\JsonResponse;

/**
 * Read-only list of rank tiers (Lv1–Lv10) for the level selector and the
 * level-progress UI. Ranks are global (not tenant-scoped).
 */
class RankController extends Controller
{
    public function index(): JsonResponse
    {
        $ranks = Rank::query()
            ->orderBy('level')
            ->get()
            ->map(fn (Rank $r): array => [
                'id' => (string) $r->id,
                'level' => (int) $r->level,
                'levelKey' => 'l'.$r->level,
                'code' => $r->code,
                'nameTh' => $r->name_th,
                'nameEn' => $r->name_en,
                'monthlyAvgTarget' => (float) $r->monthly_avg_target,
                'threeMonthAccumTarget' => (float) $r->three_month_accum_target,
                'licenseRequired' => (bool) $r->license_required,
            ]);

        return response()->json(['data' => $ranks]);
    }
}
