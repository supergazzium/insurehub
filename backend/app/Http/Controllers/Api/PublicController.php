<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Unauthenticated public endpoints — namespaced so it's obvious what's
 * reachable without a session. Currently just the recruitment-link lookup
 * used by the public register page.
 */
class PublicController extends Controller
{
    /**
     * GET /public/recruit/{token}
     *
     * Resolve a recruitment-link token → recruiter display info. Also
     * increments the click counter (once per request — the frontend calls
     * this exactly once on page load).
     */
    public function recruitLink(Request $request, string $token): JsonResponse
    {
        $link = RecruitmentLink::query()
            ->with('agent:id,agent_code,first_name,last_name')
            ->where('token', $token)
            ->where('revoked', false)
            ->first();

        if ($link === null || $link->agent === null) {
            return response()->json([
                'valid' => false,
                'message' => 'Referral link is no longer valid.',
            ], 404);
        }

        // Increment atomically — small race window between select above and
        // the update is fine for a click counter.
        $link->increment('clicks');

        return response()->json([
            'valid' => true,
            'recruiterAgentCode' => $link->agent->agent_code,
            'recruiterName' => trim(($link->agent->first_name ?? '').' '.($link->agent->last_name ?? '')),
        ]);
    }

    /**
     * GET /public/lookup/banks — active bank list for the bank dropdown.
     * Cached for 1 day; ~20 rows so payload is tiny.
     */
    public function banks(Request $request): JsonResponse
    {
        $data = Cache::remember('lookup:banks', 86400, function (): array {
            return DB::table('banks')
                ->where('active', true)
                ->orderBy('name_th')
                ->get(['id', 'name_th', 'name_en', 'code'])
                ->map(fn ($r) => [
                    'id' => (string) $r->id,
                    'nameTh' => $r->name_th,
                    'nameEn' => $r->name_en,
                    'code' => $r->code,
                ])->all();
        });
        return response()->json(['data' => $data]);
    }

    /**
     * GET /public/lookup/provinces — distinct Thai provinces.
     * ~77 rows, cached 1 day.
     */
    public function provinces(Request $request): JsonResponse
    {
        $data = Cache::remember('lookup:provinces', 86400, function (): array {
            return DB::table('locations')
                ->select('province')
                ->distinct()
                ->orderBy('province')
                ->pluck('province')
                ->all();
        });
        return response()->json(['data' => $data]);
    }

    /**
     * GET /public/lookup/districts?province=... — distinct amphur (district)
     * names in the given province. ~10–50 rows per province.
     */
    public function districts(Request $request): JsonResponse
    {
        $province = (string) $request->query('province', '');
        if ($province === '') return response()->json(['data' => []]);
        $key = 'lookup:districts:'.md5($province);
        $data = Cache::remember($key, 86400, function () use ($province): array {
            return DB::table('locations')
                ->where('province', $province)
                ->select('amphur')
                ->distinct()
                ->orderBy('amphur')
                ->pluck('amphur')
                ->all();
        });
        return response()->json(['data' => $data]);
    }

    /**
     * GET /public/lookup/sub-districts?province=...&district=... — sub-district
     * names in the given province+district, with their postal code so the UI
     * can auto-fill it.
     */
    public function subDistricts(Request $request): JsonResponse
    {
        $province = (string) $request->query('province', '');
        $district = (string) $request->query('district', '');
        if ($province === '' || $district === '') return response()->json(['data' => []]);
        $key = 'lookup:subdistricts:'.md5($province.'|'.$district);
        $data = Cache::remember($key, 86400, function () use ($province, $district): array {
            return DB::table('locations')
                ->where('province', $province)
                ->where('amphur', $district)
                ->orderBy('district')
                ->get(['district', 'zip'])
                ->map(fn ($r) => ['name' => $r->district, 'postcode' => $r->zip])
                ->all();
        });
        return response()->json(['data' => $data]);
    }
}
