<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Role catalog — admins can create/edit/delete (except is_system rows).
        Schema::create('roles', function (Blueprint $t): void {
            $t->id();
            $t->string('key', 64)->unique();
            $t->string('name_th', 120);
            $t->string('name_en', 120);
            $t->text('description')->nullable();
            // is_system rows can't be deleted from the UI and (for super_admin
            // + admin) can't have their wildcard status revoked. Seeded on
            // deploy so they can't be created ad-hoc.
            $t->boolean('is_system')->default(false);
            $t->timestamps();
        });

        // Permission catalog — seeded from code by RbacPermissionSeeder on
        // every deploy. Admins can NOT create rows here (a permission means
        // nothing without a controller that checks it).
        Schema::create('permissions', function (Blueprint $t): void {
            $t->id();
            $t->string('key', 128)->unique();     // e.g. "agents.approve"
            $t->string('module', 64);             // e.g. "agents" — UI grouping
            $t->string('name_th', 160);
            $t->string('name_en', 160);
            $t->text('description')->nullable();
            $t->timestamps();
            $t->index('module');
        });

        // Grant table — admins tick/untick here via the /admin/roles UI.
        Schema::create('role_permissions', function (Blueprint $t): void {
            $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $t->timestamps();
            $t->primary(['role_id', 'permission_id']);
        });

        // Optional per-user grant/deny beyond their role. Rare in practice
        // but a necessary escape hatch.
        Schema::create('user_permission_overrides', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $t->enum('effect', ['grant', 'deny']);
            $t->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'permission_id']);
        });

        // Add role_id to users; keep the legacy string `role` column for one
        // release as a fallback (matches the migration path from the spec).
        Schema::table('users', function (Blueprint $t): void {
            $t->foreignId('role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
            $t->index('role_id');
        });

        // ── Backfill ────────────────────────────────────────────────────
        // Seed the 8 base roles first so the FK backfill has targets.
        $now = now();
        DB::table('roles')->insert([
            ['key' => 'super_admin',     'name_th' => 'ผู้ดูแลระบบสูงสุด',                   'name_en' => 'Super Admin',      'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'admin',           'name_th' => 'ผู้ดูแล',                              'name_en' => 'Admin',            'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'executive',       'name_th' => 'ผู้บริหาร',                            'name_en' => 'Executive',        'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'sales_support',   'name_th' => 'เจ้าหน้าที่สนับสนุนงานขาย',              'name_en' => 'Sales Support',    'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'operations',      'name_th' => 'เจ้าหน้าที่ฝ่ายปฏิบัติการ',              'name_en' => 'Operations',       'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'corporate_sales', 'name_th' => 'เจ้าหน้าที่ฝ่ายขายลูกค้าองค์กร',          'name_en' => 'Corporate Sales',  'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'finance',         'name_th' => 'การเงิน-บัญชี',                        'name_en' => 'Finance & Accounting', 'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'agent',           'name_th' => 'ตัวแทน',                              'name_en' => 'Agent',            'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Map old string role → new role_id. `staff` is treated as
        // `sales_support` per typical convention; adjust in a follow-up if
        // your existing staff users should land elsewhere.
        $roleIdByKey = DB::table('roles')->pluck('id', 'key')->all();
        $mapping = [
            'super_admin' => $roleIdByKey['super_admin'],
            'admin'       => $roleIdByKey['admin'],
            'staff'       => $roleIdByKey['sales_support'],
            'agent'       => $roleIdByKey['agent'],
        ];
        foreach ($mapping as $old => $newId) {
            DB::table('users')->where('role', $old)->update(['role_id' => $newId]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('role_id');
        });
        Schema::dropIfExists('user_permission_overrides');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
