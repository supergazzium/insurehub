<?php

declare(strict_types=1);

namespace App\Rbac;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The one place that answers "does this user have this permission?"
 *
 * Wired into Laravel's Gate::before() so `$user->can('foo')`,
 * `Gate::authorize('foo')`, `@can('foo')`, and `->middleware('can:foo')`
 * all end up here.
 *
 * Cache strategy: role permissions are cached under a per-role key for
 * 5 minutes, invalidated whenever the /admin/roles UI writes to
 * role_permissions (see RoleController). Per-user overrides are read
 * fresh on every request because they're small (usually 0 rows) and
 * skipping the cache avoids stale-permission bugs after granting a
 * specific user a specific right.
 */
final class Rbac
{
    private const CACHE_TTL_SECONDS = 300;

    public function userHasPermission(User $user, string $permissionKey): bool
    {
        $role = $user->roleRel;
        if ($role === null) {
            return false;
        }

        // Wildcard shortcut. super_admin and admin always pass.
        if ($role->isWildcard()) {
            return true;
        }

        // Per-user override — deny wins over grant wins over role.
        $override = DB::table('user_permission_overrides')
            ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
            ->where('user_permission_overrides.user_id', $user->id)
            ->where('permissions.key', $permissionKey)
            ->value('effect');
        if ($override === 'deny') {
            return false;
        }
        if ($override === 'grant') {
            return true;
        }

        return in_array($permissionKey, $this->permissionsFor($role), true);
    }

    /** @return list<string> */
    public function userPermissions(User $user): array
    {
        $role = $user->roleRel;
        if ($role === null) {
            return [];
        }

        if ($role->isWildcard()) {
            // Return the whole catalog so the frontend renders every module.
            // Cached under a stable key that's busted whenever new perms are seeded.
            return Cache::remember('rbac:catalog:keys', self::CACHE_TTL_SECONDS, function (): array {
                return DB::table('permissions')->pluck('key')->all();
            });
        }

        $rolePerms = $this->permissionsFor($role);

        $overrides = DB::table('user_permission_overrides')
            ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
            ->where('user_permission_overrides.user_id', $user->id)
            ->pluck('effect', 'permissions.key')
            ->all();

        $set = array_fill_keys($rolePerms, true);
        foreach ($overrides as $key => $effect) {
            if ($effect === 'grant') $set[$key] = true;
            if ($effect === 'deny')  unset($set[$key]);
        }
        return array_keys($set);
    }

    /** @return list<string> */
    private function permissionsFor(Role $role): array
    {
        return Cache::remember(
            $this->roleCacheKey($role->key),
            self::CACHE_TTL_SECONDS,
            fn (): array => DB::table('permissions')
                ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('role_permissions.role_id', $role->id)
                ->pluck('permissions.key')
                ->all(),
        );
    }

    public function invalidate(string $roleKey): void
    {
        Cache::forget($this->roleCacheKey($roleKey));
    }

    public function invalidateAll(): void
    {
        Cache::forget('rbac:catalog:keys');
        foreach (DB::table('roles')->pluck('key') as $key) {
            $this->invalidate((string) $key);
        }
    }

    private function roleCacheKey(string $roleKey): string
    {
        return "rbac:role:{$roleKey}:permissions";
    }
}
