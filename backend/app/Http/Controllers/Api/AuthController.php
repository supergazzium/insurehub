<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgentRegisterRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\EmailVerificationToken;
use App\Models\RecruitmentLink;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()
            ->where('email', $data['email'])
            ->where('active', true)
            ->whereNull('deleted_at')
            ->first();

        // Per product feedback (task 15), distinguish "email not registered"
        // from "wrong password" so the UI can show a specific message. NOTE:
        // this is a deliberate trade-off — it enables account enumeration
        // (an attacker can learn which emails exist). Chosen by product.
        // The front end maps these codes to Thai copy.
        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => ['email_not_found'],
            ]);
        }
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['invalid_password'],
            ]);
        }

        $agentActive = $user->agent_id !== null ? Agent::query()->whereKey($user->agent_id)->value('active') : null;
        if ($agentActive !== null && ! (bool) $agentActive) {
            throw ValidationException::withMessages([
                'email' => ['บัญชีตัวแทนนี้ถูกปิดใช้งาน กรุณาติดต่อ InsureHub'],
            ]);
        }

        $deviceName = $data['deviceName'] ?? $request->userAgent() ?? 'api';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Public agent registration endpoint. Creates a paired User + Agent in a
     * transaction; user.active=false and agent.approval_status='pending' until
     * an admin approves. Rejects duplicate national IDs to prevent identity
     * collisions with the imported 395 agents (decision #1a of Phase 1 plan).
     *
     * Optional `referralToken` links the new agent's parent_agent_id to the
     * referring agent and increments the recruitment_links.signups counter.
     */
    public function register(AgentRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Duplicate national-ID check — must scan all agents in this tenant
        // and compare the DECRYPTED id_card. Cheap for the current dataset
        // (a few thousand rows) but if this grows to 100K+ we'll need a
        // deterministic HMAC index alongside the encrypted column.
        $tenantId = $this->defaultTenantId();
        $incomingId = trim($data['idCard']);
        $collision = Agent::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('id_card')
            ->get(['id', 'id_card'])
            ->first(fn (Agent $a) => (string) $a->id_card === $incomingId);
        if ($collision !== null) {
            throw ValidationException::withMessages([
                'idCard' => ['This national ID is already registered.'],
            ]);
        }

        // Optional referral token → parent agent + signup counter bump.
        $parentAgentId = null;
        $recruitmentLink = null;
        if (!empty($data['referralToken'])) {
            $recruitmentLink = RecruitmentLink::query()
                ->where('tenant_id', $tenantId)
                ->where('token', $data['referralToken'])
                ->where('revoked', false)
                ->first();
            if ($recruitmentLink !== null) {
                $parentAgentId = $recruitmentLink->agent_id;
            }
        }

        [$user, $agent] = DB::transaction(function () use ($data, $tenantId, $parentAgentId, $recruitmentLink) {
            $agent = Agent::create([
                'tenant_id' => $tenantId,
                'agent_code' => $this->nextAgentCode($tenantId),
                'agent_type' => 'IN',
                'kind' => $data['signupType'] === 'corporate' ? 'corporate' : 'individual',
                'tax_id' => $data['signupType'] === 'corporate' ? $data['idCard'] : null,
                'first_name' => $data['firstName'],
                'last_name' => $data['lastName'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'line_id' => $data['lineId'] ?? null,
                'id_card' => $data['idCard'],   // encrypted via cast
                'birth_date' => $data['birthDate'],
                'signup_type' => $data['signupType'],
                'juristic_name' => $data['juristicName'] ?? null,
                'parent_agent_id' => $parentAgentId,
                'approval_status' => 'pending',
                'signup_source' => $data['referralToken'] ?? null,
                'active' => false,
                'joined_at' => now()->toDateString(),
                'doc_status' => false,
            ]);

            $agentRoleId = \App\Models\Role::query()->where('key', 'agent')->value('id');
            $user = User::create([
                'tenant_id' => $tenantId,
                'agent_id' => $agent->id,
                'name' => trim($data['firstName'].' '.$data['lastName']),
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => 'agent',
                'role_id' => $agentRoleId,
                'active' => false,
                'locale' => 'th',
            ]);

            if ($recruitmentLink !== null) {
                $recruitmentLink->increment('signups');
                $recruitmentLink->increment('pending_signups');
            }

            AuditEntry::create([
                'tenant_id' => $tenantId,
                'user_id' => null,
                'occurred_at' => now(),
                'actor' => 'public',
                'action' => 'agent.self_register',
                'target' => 'agent:'.$agent->id,
                'ip' => request()->ip(),
                'result' => 'success',
                'metadata' => [
                    'signup_type' => $data['signupType'],
                    'via_referral' => $recruitmentLink !== null,
                ],
            ]);

            return [$user, $agent];
        });

        // Consume the email verification token so it can't be reused.
        if (!empty($data['emailOtpToken'])) {
            EmailVerificationToken::query()
                ->where('token', $data['emailOtpToken'])
                ->update(['consumed_at' => now()]);
        }

        return response()->json([
            'message' => 'Registration received. An administrator will review your application.',
            'agentCode' => $agent->agent_code,
        ], 201);
    }

    /**
     * Public availability probe for the agent-registration form. Lets the
     * frontend disable the "send OTP" button (and show inline errors) when
     * the email or national ID is already taken, rather than burning an OTP
     * quota only to reject at final submit.
     *
     * Request: { field: 'email' | 'idCard', value: string }
     * Response: { available: bool, message?: string }
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'in:email,idCard'],
            'value' => ['required', 'string', 'max:255'],
        ]);
        $field = $data['field'];
        $value = trim($data['value']);

        if ($field === 'email') {
            $normalized = strtolower($value);
            $taken = User::query()->where('email', $normalized)->exists();
            return response()->json([
                'available' => ! $taken,
                'code' => $taken ? 'email_taken' : null,
            ]);
        }

        // idCard: must be 13 digits before we bother scanning encrypted rows.
        if (preg_match('/^\d{13}$/', $value) !== 1) {
            return response()->json([
                'available' => false,
                'code' => 'id_card_invalid_format',
            ]);
        }
        $tenantId = $this->defaultTenantId();
        $taken = Agent::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('id_card')
            ->get(['id', 'id_card'])
            ->contains(fn (Agent $a) => (string) $a->id_card === $value);
        return response()->json([
            'available' => ! $taken,
            'code' => $taken ? 'id_card_taken' : null,
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated()['email'];

        // Use Laravel's built-in Password broker — writes to password_reset_tokens
        // and calls the User's sendPasswordResetNotification. We shim the
        // notification below because we don't have a mail-friendly template yet.
        $status = Password::sendResetLink(['email' => $email]);

        // Always return the same message — don't leak whether an account exists.
        return response()->json([
            'message' => 'If an account exists for that address, a reset link has been sent.',
            'status' => $status,
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                // Revoke all existing tokens on password reset — forces re-login.
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Password reset. You may now log in.']);
    }

    /** Default tenant for public self-registration. */
    private function defaultTenantId(): int
    {
        // Convention: tenant #1 unless a single-tenant deployment says otherwise.
        // Any better routing (subdomain, ?tenant=slug, invite token) plugs in here.
        return (int) (Tenant::query()->orderBy('id')->value('id') ?? 1);
    }

    /**
     * Next self-registered agent_code — NA + 2-digit year + 4-digit serial.
     * Deterministically avoids collision with imported IN/IP-prefixed codes.
     */
    private function nextAgentCode(int $tenantId): string
    {
        // All agent codes use the "IN" prefix now (no more "AG"/"NA") — the
        // same scheme as the imported agents (#27). The sequence is taken
        // across EVERY IN<yy> code including imported ones so a new signup
        // can't collide with an existing imported code for the same year.
        $prefix = 'IN'.now()->format('y');
        $lastForYear = Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('agent_code', 'like', $prefix.'%')
            ->orderByDesc('agent_code')
            ->value('agent_code');
        $seq = $lastForYear ? ((int) substr($lastForYear, strlen($prefix))) + 1 : 1;
        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json(['user' => $this->userPayload($user)]);
    }

    // ── #1 LINE Login (OAuth 2.0 / OIDC) ─────────────────────────────────────

    private function lineConfigured(): bool
    {
        return (bool) config('services.line.channel_id') && (bool) config('services.line.channel_secret');
    }

    /**
     * Build the LINE authorize URL. `mode=connect` carries the signed id of the
     * already-signed-in user (link-to-existing-account); `mode=login` (default)
     * signs in a user whose account is already linked. Returns the URL for the
     * SPA to redirect the browser to.
     */
    public function lineRedirect(Request $request): JsonResponse
    {
        if (! $this->lineConfigured()) {
            return response()->json(['message' => 'LINE Login ยังไม่ได้ตั้งค่า'], 503);
        }
        // Mode follows authentication: a signed-in user (connect-url route under
        // auth:sanctum) is LINKING; an anonymous caller (public login-url) is
        // SIGNING IN with an already-linked account.
        $connectUserId = $request->user() !== null ? (int) $request->user()->id : null;
        $mode = $connectUserId !== null ? 'connect' : 'login';
        $state = Crypt::encryptString(json_encode([
            'mode' => $mode,
            'uid' => $connectUserId,
            'nonce' => Str::random(24),
            'exp' => now()->addMinutes(10)->timestamp,
        ]));

        $url = 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.line.channel_id'),
            'redirect_uri' => config('services.line.redirect'),
            'state' => $state,
            'scope' => 'openid profile',
        ]);

        return response()->json(['url' => $url]);
    }

    /**
     * LINE redirects here after authorisation. Exchange the code for tokens,
     * read the LINE user id (`sub`) from the id_token, then connect or sign in.
     * Always redirects the browser back to the SPA with a result in the query.
     */
    public function lineCallback(Request $request): RedirectResponse
    {
        $front = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', '/')), '/');
        $fail = fn (string $code): RedirectResponse => redirect()->away("{$front}/agent/login?line_error={$code}");

        if (! $this->lineConfigured()) {
            return $fail('not_configured');
        }
        if ($request->query('error') !== null || $request->query('code') === null) {
            return $fail('cancelled');
        }

        // Verify + decode the signed state.
        try {
            $state = json_decode(Crypt::decryptString((string) $request->query('state')), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $fail('bad_state');
        }
        if (! is_array($state) || ($state['exp'] ?? 0) < now()->timestamp) {
            return $fail('bad_state');
        }
        $mode = $state['mode'] ?? 'login';

        // Exchange the authorization code for tokens.
        $tokenRes = Http::asForm()->post('https://api.line.me/oauth2/v2.1/token', [
            'grant_type' => 'authorization_code',
            'code' => (string) $request->query('code'),
            'redirect_uri' => config('services.line.redirect'),
            'client_id' => config('services.line.channel_id'),
            'client_secret' => config('services.line.channel_secret'),
        ]);
        if (! $tokenRes->successful()) {
            return $fail('exchange_failed');
        }
        $idToken = (string) $tokenRes->json('id_token', '');
        $lineUserId = $this->lineUserIdFromIdToken($idToken);
        if ($lineUserId === null) {
            return $fail('no_profile');
        }

        if ($mode === 'connect') {
            $user = User::query()->whereKey((int) ($state['uid'] ?? 0))->first();
            if ($user === null) {
                return $fail('bad_state');
            }
            // Refuse if this LINE account is already linked to someone else.
            $taken = User::query()->where('line_user_id', $lineUserId)->where('id', '!=', $user->id)->exists();
            if ($taken) {
                return redirect()->away("{$front}/portal/settings?line_error=already_linked");
            }
            $user->line_user_id = $lineUserId;
            $user->save();
            return redirect()->away("{$front}/portal/settings?line_connected=1");
        }

        // login mode — the LINE account must already be linked.
        $user = User::query()
            ->where('line_user_id', $lineUserId)
            ->where('active', true)
            ->whereNull('deleted_at')
            ->first();
        if ($user === null) {
            return $fail('not_linked');
        }
        $token = $user->createToken('line')->plainTextToken;
        // Hand the token to the SPA via the fragment so it isn't logged in query
        // strings / server logs; the login page reads it and stores it.
        return redirect()->away("{$front}/agent/login#line_token=" . urlencode($token));
    }

    /**
     * Extract the LINE user id (`sub`) from an OIDC id_token without verifying
     * the signature here — the token came straight from LINE's token endpoint
     * over TLS using our channel secret, so it is trusted for the `sub` claim.
     */
    private function lineUserIdFromIdToken(string $idToken): ?string
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $sub = is_array($payload) ? ($payload['sub'] ?? null) : null;
        return is_string($sub) && $sub !== '' ? $sub : null;
    }

    /** Disconnect the signed-in user's LINE link. */
    public function lineDisconnect(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->line_user_id = null;
        $user->save();
        return response()->json(['message' => 'ยกเลิกการเชื่อม LINE แล้ว']);
    }

    /** @return array<string,mixed> */
    private function userPayload(User $user): array
    {
        $roleRel = $user->roleRel;
        $rbac = app(Rbac::class);
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            // Legacy string role (kept for frontend backwards-compat).
            'role' => $roleRel?->key ?? $user->role,
            'roleId' => $user->role_id !== null ? (string) $user->role_id : null,
            'roleLabel' => $roleRel !== null
                ? ['th' => $roleRel->name_th, 'en' => $roleRel->name_en]
                : null,
            // Full set of permission keys the frontend uses to hide/show UI.
            'permissions' => $rbac->userPermissions($user),
            'locale' => $user->locale,
            'tenantId' => $user->tenant_id !== null ? (string) $user->tenant_id : null,
            'agentId' => $user->agent_id !== null ? (string) $user->agent_id : null,
            // #1 — whether this account has a LINE login linked.
            'lineLinked' => $user->line_user_id !== null,
        ];
    }
}
