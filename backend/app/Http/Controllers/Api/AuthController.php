<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'locale' => $user->locale,
            'tenantId' => $user->tenant_id !== null ? (string) $user->tenant_id : null,
            'agentId' => $user->agent_id !== null ? (string) $user->agent_id : null,
        ];
    }
}
