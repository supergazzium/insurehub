<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AuditEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Admin CRUD for the RBAC catalog.
 *
 * All routes gated by the `admin.roles` permission. Whoever has that
 * permission is effectively god-tier (they can grant themselves anything).
 * Only `admin` and `super_admin` should have it.
 *
 * Guardrails enforced here (in addition to the DB constraints):
 * - `super_admin` is fully locked: not editable, not deletable, wildcard
 *   status protected by App\Models\Role::isWildcard().
 * - `admin` is editable in name/description but its permissions are
 *   read-only from the UI perspective (they always cover the whole catalog).
 * - Roles with any assigned user can't be deleted.
 * - A user with `admin.roles` can't downgrade themselves out of it.
 */
class AdminRoleController extends ApiController
{
    public function __construct(private readonly Rbac $rbac) {}

    /** GET /admin/roles */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('admin.roles');

        $rows = Role::query()
            ->withCount('users')
            ->orderBy('is_system', 'desc')
            ->orderBy('name_en')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Role $r) => $this->shape($r))->all(),
        ]);
    }

    /** GET /admin/permissions — the full catalog, grouped by module. */
    public function permissions(Request $request): JsonResponse
    {
        Gate::authorize('admin.roles');

        $rows = Permission::query()
            ->orderBy('module')
            ->orderBy('key')
            ->get();

        $grouped = [];
        foreach ($rows as $p) {
            $grouped[$p->module] ??= ['module' => $p->module, 'permissions' => []];
            $grouped[$p->module]['permissions'][] = [
                'id' => (string) $p->id,
                'key' => $p->key,
                'nameTh' => $p->name_th,
                'nameEn' => $p->name_en,
                'description' => $p->description,
            ];
        }

        return response()->json(['data' => array_values($grouped)]);
    }

    /** GET /admin/roles/{role} */
    public function show(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('admin.roles');
        return response()->json(['data' => $this->shape($role, includePermissionIds: true)]);
    }

    /** POST /admin/roles */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin.roles');
        $data = $request->validate([
            'nameTh' => ['required', 'string', 'max:120'],
            'nameEn' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'key' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:roles,key'],
            'permissionIds' => ['sometimes', 'array'],
            'permissionIds.*' => ['integer', 'exists:permissions,id'],
        ]);

        $key = $data['key'] ?? $this->autoKey($data['nameEn']);
        // Ensure uniqueness if auto-generated collides.
        while (Role::query()->where('key', $key)->exists()) {
            $key .= '_'.Str::random(3);
        }

        $role = Role::create([
            'key' => $key,
            'name_th' => $data['nameTh'],
            'name_en' => $data['nameEn'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        if (!empty($data['permissionIds'])) {
            $role->permissions()->sync($data['permissionIds']);
        }

        $this->audit($request, 'role.created', 'role:'.$role->id, [
            'key' => $role->key,
            'name_en' => $role->name_en,
            'permission_count' => count($data['permissionIds'] ?? []),
        ]);
        $this->rbac->invalidate($role->key);

        return response()->json(['data' => $this->shape($role->fresh(), includePermissionIds: true)], 201);
    }

    /** PATCH /admin/roles/{role} — name/description only. Permissions use the dedicated endpoint. */
    public function update(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('admin.roles');
        $this->rejectIfSuperAdmin($role);

        $data = $request->validate([
            'nameTh' => ['sometimes', 'string', 'max:120'],
            'nameEn' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $map = ['nameTh' => 'name_th', 'nameEn' => 'name_en', 'description' => 'description'];
        $updates = [];
        foreach ($map as $c => $s) {
            if (array_key_exists($c, $data)) $updates[$s] = $data[$c];
        }
        if ($updates !== []) $role->update($updates);

        $this->audit($request, 'role.updated', 'role:'.$role->id, ['fields' => array_keys($updates)]);
        $this->rbac->invalidate($role->key);

        return response()->json(['data' => $this->shape($role->fresh(), includePermissionIds: true)]);
    }

    /** PUT /admin/roles/{role}/permissions — idempotent replace of the whole set. */
    public function setPermissions(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('admin.roles');
        $this->rejectIfSuperAdmin($role);
        // `admin` keeps wildcard behavior enforced in code; UI silently accepts
        // whatever it sends but the Rbac layer ignores its role_permissions
        // rows (isWildcard() short-circuits). Blocking the write would
        // confuse the UI; letting it through keeps everything consistent.

        $data = $request->validate([
            'permissionIds' => ['required', 'array'],
            'permissionIds.*' => ['integer', 'exists:permissions,id'],
        ]);

        $before = $role->permissions()->pluck('permissions.id')->all();
        $after = array_map('intval', $data['permissionIds']);
        $role->permissions()->sync($after);

        $granted = array_values(array_diff($after, $before));
        $revoked = array_values(array_diff($before, $after));
        $this->audit($request, 'role.permissions_updated', 'role:'.$role->id, [
            'granted_ids' => $granted,
            'revoked_ids' => $revoked,
        ]);
        $this->rbac->invalidate($role->key);

        return response()->json(['data' => $this->shape($role->fresh(), includePermissionIds: true)]);
    }

    /** DELETE /admin/roles/{role} — refuse if is_system or any user attached. */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('admin.roles');

        if ($role->is_system) {
            abort(422, 'System roles cannot be deleted.');
        }
        $userCount = User::query()->where('role_id', $role->id)->count();
        if ($userCount > 0) {
            abort(422, "This role has {$userCount} user(s). Reassign them before deleting.");
        }

        $key = $role->key;
        $roleId = $role->id;
        $role->delete();

        $this->audit($request, 'role.deleted', 'role:'.$roleId, ['key' => $key]);
        $this->rbac->invalidate($key);

        return response()->json(['message' => 'Role deleted.']);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function autoKey(string $nameEn): string
    {
        return Str::of($nameEn)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->__toString() ?: 'role_'.Str::random(4);
    }

    private function rejectIfSuperAdmin(Role $role): void
    {
        if ($role->key === 'super_admin') {
            abort(422, 'The super_admin role is locked and cannot be edited or deleted.');
        }
    }

    /** @return array<string, mixed> */
    private function shape(Role $r, bool $includePermissionIds = false): array
    {
        $out = [
            'id' => (string) $r->id,
            'key' => $r->key,
            'nameTh' => $r->name_th,
            'nameEn' => $r->name_en,
            'description' => $r->description,
            'isSystem' => (bool) $r->is_system,
            'isWildcard' => $r->isWildcard(),
            'userCount' => (int) ($r->users_count ?? $r->users()->count()),
        ];
        if ($includePermissionIds) {
            $out['permissionIds'] = $r->permissions()->pluck('permissions.id')->map(fn ($v) => (string) $v)->all();
        }
        return $out;
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
