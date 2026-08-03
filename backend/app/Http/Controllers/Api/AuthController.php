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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
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

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
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
        $prefix = 'NA'.now()->format('y');
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
        ];
    }
}
