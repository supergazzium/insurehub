<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Rbac\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent: safe to run on every deploy. Adds new permissions to the
 * catalog, updates human-readable names/descriptions, and grants every
 * catalog permission to `admin` so the god-tier role stays complete when
 * new permissions land.
 */
class RbacPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $row) {
            Permission::updateOrCreate(
                ['key' => $row['key']],
                [
                    'module' => $row['module'],
                    'name_th' => $row['name_th'],
                    'name_en' => $row['name_en'],
                    'description' => $row['description'],
                ],
            );
        }

        // Grant EVERY permission to the `admin` role. super_admin bypasses
        // via the Gate::before hook and doesn't need a grant table entry,
        // but writing them here anyway makes the UI display "all boxes ticked"
        // when someone opens super_admin (locked / read-only for those two).
        $adminRole = Role::query()->where('key', 'admin')->first();
        $superAdminRole = Role::query()->where('key', 'super_admin')->first();
        $allPermissionIds = Permission::query()->pluck('id')->all();
        if ($adminRole !== null) {
            $adminRole->permissions()->sync($allPermissionIds);
        }
        if ($superAdminRole !== null) {
            $superAdminRole->permissions()->sync($allPermissionIds);
        }

        // Agent baseline. Idempotent — attach if missing.
        $agentRole = Role::query()->where('key', 'agent')->first();
        if ($agentRole !== null) {
            $portalPermIds = Permission::query()
                ->whereIn('key', ['portal.view', 'portal.update_profile'])
                ->pluck('id')
                ->all();
            $agentRole->permissions()->syncWithoutDetaching($portalPermIds);
        }
    }
}
