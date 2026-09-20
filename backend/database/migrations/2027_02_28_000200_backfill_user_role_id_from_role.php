<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The RBAC gate resolves permissions through users.role_id (the roles relation),
 * but some users — notably seeded super_admin/admin accounts — carry only the
 * legacy `role` string with a null role_id. That made even super_admin fail
 * every Gate::authorize() (e.g. agent approval → "This action is unauthorized").
 *
 * Backfill role_id from the role string wherever it maps to an existing role
 * and role_id is still null. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = DB::table('roles')->pluck('id', 'key'); // [key => id]
        foreach ($roles as $key => $id) {
            DB::table('users')
                ->whereNull('role_id')
                ->where('role', $key)
                ->update(['role_id' => $id]);
        }
    }

    public function down(): void
    {
        // Non-reversible backfill; leave role_id in place.
    }
};
