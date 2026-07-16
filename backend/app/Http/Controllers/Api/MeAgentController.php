<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\MyAgentResource;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\RecruitmentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * "Me as an agent" — profile, referral link, downline, and PII upload
 * endpoints scoped to the currently-authenticated agent. Every write
 * runs through the Agent observer for audit logging (see AgentObserver).
 */
class MeAgentController extends ApiController
{
    // ── Read ─────────────────────────────────────────────────────────────

    public function show(Request $request): MyAgentResource
    {
        return new MyAgentResource($this->currentAgent($request));
    }

    // ── Sectioned updates ────────────────────────────────────────────────

    /**
     * Per-section update maps: request key (camelCase) → DB column (snake_case).
     * Anything not in the map for the section is silently dropped, so a client
     * can't sneak `approval_status` into a profile update.
     *
     * @var array<string, array<string, string>>
     */
    private const SECTION_MAPS = [
        'profile' => [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'firstNameEn' => 'first_name_en',
            'lastNameEn' => 'last_name_en',
            'nickname' => 'nickname',
            'gender' => 'gender',
            'phone' => 'phone',
            'lineId' => 'line_id',
            'facebookName' => 'facebook_name',
            'birthDate' => 'birth_date',
        ],
        'idDocument' => [
            'idCard' => 'id_card',
        ],
        'license' => [
            'licenseLifeNo' => 'license_life_no',
            'licenseLifeExpiry' => 'license_life_expiry',
            'licenseNonLifeNo' => 'license_non_life_no',
            'licenseNonLifeExpiry' => 'license_non_life_expiry',
        ],
        'bank' => [
            'bankId' => 'bank_id',
            'bankAccountNo' => 'bank_account_no',
            'bankAccountName' => 'bank_account_name',
            'bankNameText' => 'bank_name_text',
        ],
        'address' => [
            'address' => 'address',
            'subDistrict' => 'sub_district',
            'district' => 'district',
            'province' => 'province',
            'postcode' => 'postcode',
        ],
    ];

    /**
     * Validation rules per section. Keys match request payload (camelCase).
     * All `sometimes|nullable` — partial updates.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SECTION_RULES = [
        'profile' => [
            'firstName' => ['sometimes', 'string', 'max:120'],
            'lastName' => ['sometimes', 'string', 'max:120'],
            'firstNameEn' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lastNameEn' => ['sometimes', 'nullable', 'string', 'max:120'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:64'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female,other,'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'lineId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'facebookName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'birthDate' => ['sometimes', 'nullable', 'date', 'before:today'],
        ],
        'idDocument' => [
            'idCard' => ['sometimes', 'string', 'min:5', 'max:32'],
        ],
        'license' => [
            'licenseLifeNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'licenseLifeExpiry' => ['sometimes', 'nullable', 'date'],
            'licenseNonLifeNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'licenseNonLifeExpiry' => ['sometimes', 'nullable', 'date'],
        ],
        'bank' => [
            'bankId' => ['sometimes', 'nullable', 'integer', 'exists:banks,id'],
            'bankAccountNo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bankAccountName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bankNameText' => ['sometimes', 'nullable', 'string', 'max:255'],
        ],
        'address' => [
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subDistrict' => ['sometimes', 'nullable', 'string', 'max:120'],
            'district' => ['sometimes', 'nullable', 'string', 'max:120'],
            'province' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postcode' => ['sometimes', 'nullable', 'string', 'max:16'],
        ],
    ];

    public function updateProfile(Request $request): MyAgentResource
    {
        return $this->applySection($request, 'profile');
    }

    public function updateIdDocument(Request $request): MyAgentResource
    {
        return $this->applySection($request, 'idDocument');
    }

    public function updateLicense(Request $request): MyAgentResource
    {
        return $this->applySection($request, 'license');
    }

    public function updateBank(Request $request): MyAgentResource
    {
        return $this->applySection($request, 'bank');
    }

    public function updateAddress(Request $request): MyAgentResource
    {
        return $this->applySection($request, 'address');
    }

    // ── Photos ───────────────────────────────────────────────────────────

    public function uploadProfilePhoto(Request $request): MyAgentResource
    {
        return $this->handlePhotoUpload($request, 'profile', 'profile_photo_path', 'photo');
    }

    public function uploadIdPhoto(Request $request): MyAgentResource
    {
        return $this->handlePhotoUpload($request, 'id', 'id_card_photo_path', 'photo');
    }

    public function uploadBankBookPhoto(Request $request): MyAgentResource
    {
        return $this->handlePhotoUpload($request, 'bank', 'bank_book_photo_path', 'photo');
    }

    // ── ID unmask (writes an audit row) ──────────────────────────────────

    public function unmaskIdCard(Request $request): JsonResponse
    {
        $agent = $this->currentAgent($request);
        AuditEntry::create([
            'tenant_id' => $agent->tenant_id,
            'user_id' => $request->user()->id,
            'occurred_at' => now(),
            'actor' => $request->user()->name,
            'action' => 'agent.id_card_unmasked',
            'target' => 'agent:'.$agent->id,
            'ip' => $request->ip(),
            'result' => 'success',
            'metadata' => ['self' => true],
        ]);
        return response()->json(['idCard' => $agent->id_card]);
    }

    // ── Referral link + downline ─────────────────────────────────────────

    public function referralLink(Request $request): JsonResponse
    {
        $agent = $this->currentAgent($request);
        $link = RecruitmentLink::query()
            ->where('tenant_id', $agent->tenant_id)
            ->where('agent_id', $agent->id)
            ->where('revoked', false)
            ->orderByDesc('id')
            ->first();

        if ($link === null) {
            $link = RecruitmentLink::create([
                'tenant_id' => $agent->tenant_id,
                'agent_id' => $agent->id,
                'token' => Str::random(32),
                'generated_at' => now(),
                'clicks' => 0,
                'signups' => 0,
                'pending_signups' => 0,
                'revoked' => false,
            ]);
        }

        $base = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173/insurehub')), '/');
        return response()->json([
            'token' => $link->token,
            'url' => $base.'/r/'.$link->token,
            'clicks' => (int) $link->clicks,
            'signups' => (int) $link->signups,
            'pendingSignups' => (int) $link->pending_signups,
            'generatedAt' => $link->generated_at?->toIso8601String(),
        ]);
    }

    public function downline(Request $request): AnonymousResourceCollection
    {
        $agent = $this->currentAgent($request);
        $rows = Agent::query()
            ->where('tenant_id', $agent->tenant_id)
            ->where('parent_agent_id', $agent->id)
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->get();
        return MyAgentResource::collection($rows);
    }

    // ── Earnings (Phase 7a — real-time commission ledger) ────────────────

    /**
     * Real-time earnings ledger for the current agent.
     *
     *   accrued  = sum of unsettled txns  (has been earned, not yet paid)
     *   paid     = sum of settled txns    (transferred to bank)
     *   pending  = subset of accrued whose parent policy has approvalStatus
     *              not yet in-force — a "you'll get this once activated" bucket
     *   byMonth  = grouped rows keyed by YYYY-MM for the chart / history view
     *
     * Includes reversals (negative amounts) so displayed numbers are net.
     */
    public function earnings(Request $request): JsonResponse
    {
        $agent = $this->currentAgent($request);

        $rows = \Illuminate\Support\Facades\DB::table('commission_transactions')
            ->where('agent_id', $agent->id)
            ->where('tenant_id', $agent->tenant_id)
            ->orderByDesc('created_at')
            ->get([
                'id', 'type', 'status', 'policy_id', 'base_premium',
                'diff_pct', 'amount', 'reverses_txn_id', 'created_at',
            ]);

        $unsettled = 0.0; $settled = 0.0;
        $byMonth = [];
        $recent = [];
        foreach ($rows as $r) {
            $amt = (float) $r->amount;
            if ($r->status === 'settled') $settled += $amt; else $unsettled += $amt;

            $mon = substr((string) $r->created_at, 0, 7);
            if (! isset($byMonth[$mon])) $byMonth[$mon] = ['month' => $mon, 'unsettled' => 0.0, 'settled' => 0.0, 'count' => 0];
            if ($r->status === 'settled') $byMonth[$mon]['settled'] += $amt; else $byMonth[$mon]['unsettled'] += $amt;
            $byMonth[$mon]['count']++;

            if (count($recent) < 20) {
                $recent[] = [
                    'id' => (string) $r->id,
                    'type' => $r->type,
                    'status' => $r->status,
                    'policyId' => (string) $r->policy_id,
                    'basePremium' => (float) $r->base_premium,
                    'diffPct' => (float) $r->diff_pct,
                    'amount' => $amt,
                    'isReversal' => $r->reverses_txn_id !== null,
                    'createdAt' => $r->created_at,
                ];
            }
        }

        return response()->json([
            'summary' => [
                'accrued' => round($unsettled, 2),   // to-be-paid
                'paid' => round($settled, 2),        // already transferred
                'total' => round($unsettled + $settled, 2),
                'txnCount' => count($rows),
            ],
            'byMonth' => array_values($byMonth),
            'recent' => $recent,
        ]);
    }

    // ── Change password ──────────────────────────────────────────────────

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        if (! \Illuminate\Support\Facades\Hash::check($data['currentPassword'], $user->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'currentPassword' => ['Current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => \Illuminate\Support\Facades\Hash::make($data['newPassword']),
        ])->save();

        // Revoke all other tokens; keep the current one so this request's caller
        // isn't logged out mid-response.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '<>', $currentTokenId))->delete();

        AuditEntry::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'occurred_at' => now(),
            'actor' => $user->name,
            'action' => 'user.password_changed',
            'target' => 'user:'.$user->id,
            'ip' => $request->ip(),
            'result' => 'success',
            'metadata' => ['self' => true],
        ]);

        return response()->json(['message' => 'Password updated.']);
    }

    // ── Internal helpers ─────────────────────────────────────────────────

    /**
     * Resolve the current user's Agent record, 404 if the user isn't tied
     * to one. Approved-status enforcement lives on the login path
     * (via users.active); once logged in the portal is available.
     */
    private function currentAgent(Request $request): Agent
    {
        $agentId = $request->user()->agent_id;
        if ($agentId === null) {
            abort(404, 'This account is not linked to an agent record.');
        }
        $agent = Agent::query()
            ->where('id', $agentId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->first();
        if ($agent === null) {
            abort(404, 'Agent record not found.');
        }
        return $agent;
    }

    private function applySection(Request $request, string $section): MyAgentResource
    {
        $rules = self::SECTION_RULES[$section] ?? abort(500, "Unknown section: {$section}");
        $map = self::SECTION_MAPS[$section];

        $validated = Validator::make($request->all(), $rules)->validate();

        $updates = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $validated)) {
                $updates[$snake] = $validated[$camel];
            }
        }

        $agent = $this->currentAgent($request);
        if ($updates !== []) {
            $agent->update($updates);
        }

        return new MyAgentResource($agent->fresh());
    }

    private function handlePhotoUpload(Request $request, string $section, string $column, string $field): MyAgentResource
    {
        $request->validate([
            $field => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],  // 5 MB cap
        ]);

        /** @var UploadedFile $file */
        $file = $request->file($field);
        $agent = $this->currentAgent($request);

        // Segregate by tenant + agent so a leaked path can't reveal cross-tenant files.
        $dir = "agent-uploads/{$agent->tenant_id}/{$agent->id}/{$section}";
        $storedPath = $file->store($dir, 'local');

        // Remove the previous file if one exists (best-effort — never let a
        // stale-file cleanup crash the upload).
        $old = $agent->{$column};
        if ($old && Storage::disk('local')->exists($old)) {
            try { Storage::disk('local')->delete($old); } catch (\Throwable) { /* ignore */ }
        }

        $agent->update([$column => $storedPath]);
        return new MyAgentResource($agent->fresh());
    }
}
