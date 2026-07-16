<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
