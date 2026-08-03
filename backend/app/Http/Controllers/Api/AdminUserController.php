<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AuditEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admin CRUD for user role assignment + per-user permission overrides.
 * All routes gated by `admin.users`.
 *
 * Guardrail: an admin cannot demote themselves out of the `admin.roles`
 * permission. Otherwise they could lock the whole system out.
 */
class AdminUserController extends ApiController
{
    public function __construct(private readonly Rbac $rbac) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('admin.users');

        $q = User::query()
            ->where('tenant_id', $this->tenantId($request))
            ->with('roleRel:id,key,name_th,name_en')
            ->orderBy('name');

        if ($search = $request->input('search')) {
            $like = '%'.$search.'%';
            $q->where(fn ($qq) => $qq->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }
        if ($roleId = $request->input('roleId')) {
            $q->where('role_id', $roleId);
        }

        $users = $q->limit($this->perPage($request, 50, 200))->get();
        return response()->json([
            'data' => $users->map(fn (User $u) => $this->shape($u))->all(),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        Gate::authorize('admin.users');
        $this->authorizeTenant($request, $user);
        $overrides = UserPermissionOverride::query()
            ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
            ->where('user_permission_overrides.user_id', $user->id)
            ->get(['user_permission_overrides.id', 'permissions.key', 'permissions.id as permission_id', 'user_permission_overrides.effect']);

        return response()->json([
            'data' => array_merge($this->shape($user), [
                'overrides' => $overrides->map(fn ($o) => [
                    'id' => (string) $o->id,
                    'permissionId' => (string) $o->permission_id,
                    'permissionKey' => $o->key,
                    'effect' => $o->effect,
                ])->all(),
            ]),
        ]);
    }

    /** PATCH /admin/users/{user}/role */
    public function setRole(Request $request, User $user): JsonResponse
    {
        Gate::authorize('admin.users');
        $this->authorizeTenant($request, $user);

        $data = $request->validate([
            'roleId' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $newRole = Role::query()->findOrFail($data['roleId']);

        // Self-lockout guard: user can't move themselves off admin.roles.
        if ($user->id === $request->user()->id) {
            $newRolePerms = $this->rbac->userPermissions(tap($user, function (User $u) use ($newRole) {
                $u->setRelation('roleRel', $newRole);
                $u->role_id = $newRole->id;
            }));
            if (! in_array('admin.roles', $newRolePerms, true)) {
                abort(422, 'You cannot remove your own admin.roles permission.');
            }
        }

        $oldRoleKey = $user->roleRel?->key;
        $user->role_id = $newRole->id;
        $user->role = $newRole->key; // keep legacy string column in sync
        $user->save();

        $this->audit($request, 'user.role_changed', 'user:'.$user->id, [
            'from' => $oldRoleKey,
            'to' => $newRole->key,
        ]);

        return response()->json(['data' => $this->shape($user->fresh(['roleRel']))]);
    }

    /** POST /admin/users/{user}/overrides */
    public function addOverride(Request $request, User $user): JsonResponse
    {
        Gate::authorize('admin.users');
        $this->authorizeTenant($request, $user);

        $data = $request->validate([
            'permissionId' => ['required', 'integer', 'exists:permissions,id'],
            'effect' => ['required', 'in:grant,deny'],
        ]);

        // Self-lockout guard.
        if ($user->id === $request->user()->id && $data['effect'] === 'deny') {
            $perm = Permission::query()->findOrFail($data['permissionId']);
            if ($perm->key === 'admin.roles') {
                abort(422, 'You cannot deny your own admin.roles permission.');
            }
        }

        $override = UserPermissionOverride::updateOrCreate(
            ['user_id' => $user->id, 'permission_id' => $data['permissionId']],
            ['effect' => $data['effect'], 'granted_by_user_id' => $request->user()->id],
        );

        $this->audit($request, 'user.override_'.$data['effect'], 'user:'.$user->id, [
            'permission_id' => $data['permissionId'],
        ]);

        return response()->json(['data' => [
            'id' => (string) $override->id,
            'permissionId' => (string) $override->permission_id,
            'effect' => $override->effect,
        ]], 201);
    }

    public function removeOverride(Request $request, User $user, int $overrideId): JsonResponse
    {
        Gate::authorize('admin.users');
        $this->authorizeTenant($request, $user);
        $override = UserPermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('id', $overrideId)
            ->firstOrFail();
        $override->delete();

        $this->audit($request, 'user.override_removed', 'user:'.$user->id, [
            'override_id' => $overrideId,
        ]);
        return response()->json(['message' => 'Override removed.']);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function authorizeTenant(Request $request, User $user): void
    {
        if ((int) $user->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function shape(User $u): array
    {
        return [
            'id' => (string) $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'active' => (bool) $u->active,
            'roleId' => $u->role_id !== null ? (string) $u->role_id : null,
            'roleKey' => $u->roleRel?->key,
            'roleLabel' => $u->roleRel !== null
                ? ['th' => $u->roleRel->name_th, 'en' => $u->roleRel->name_en]
                : null,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Request $request, string $action, string $target, array $metadata = []): void
    {
        AuditEntry::create([
            'tenant_id' => $this->tenantId($request),
            'user_id' => $request->user()->id,
            'occurred_at' => now(),
            'actor' => $request->user()->name,
            'action' => $action,
            'target' => $target,
            'ip' => $request->ip(),
            'result' => 'success',
            'metadata' => $metadata,
        ]);
    }
}
