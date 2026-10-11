<?php

use App\Rbac\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #3 / role work — add the IT role and its permissions.
 *
 * The 6 business roles (executive, finance, sales_support, corporate_sales,
 * operations, agent) already exist from 2027_01_03_000100. This adds the 7th
 * role (it) plus the new `it.*` permissions from PermissionCatalog, then grants
 * every it.* permission to the IT role. admin / super_admin pick the new
 * permissions up via RbacPermissionSeeder (grants ALL to admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1. Create the IT role if absent.
        if (DB::table('roles')->where('key', 'it')->doesntExist()) {
            DB::table('roles')->insert([
                'key' => 'it', 'name_th' => 'ฝ่ายไอที', 'name_en' => 'IT',
                'is_system' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // 2. Upsert the new it.* permissions from the catalog.
        $itPerms = array_values(array_filter(
            PermissionCatalog::all(),
            fn ($p) => $p['module'] === 'it',
        ));
        foreach ($itPerms as $p) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $p['key']],
                [
                    'module' => $p['module'],
                    'name_th' => $p['name_th'],
                    'name_en' => $p['name_en'],
                    'description' => $p['description'] ?? null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        // 3. Grant all it.* permissions to the IT role.
        $itRoleId = DB::table('roles')->where('key', 'it')->value('id');
        $itPermIds = DB::table('permissions')->where('module', 'it')->pluck('id');
        foreach ($itPermIds as $pid) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $itRoleId, 'permission_id' => $pid],
                [],
            );
        }

        // 4. Keep admin / super_admin complete — grant the new perms to them too.
        foreach (['admin', 'super_admin'] as $key) {
            $roleId = DB::table('roles')->where('key', $key)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($itPermIds as $pid) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $pid],
                    [],
                );
            }
        }
    }

    public function down(): void
    {
        $itPermIds = DB::table('permissions')->where('module', 'it')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $itPermIds)->delete();
        DB::table('permissions')->where('module', 'it')->delete();
        DB::table('roles')->where('key', 'it')->delete();
    }
};
