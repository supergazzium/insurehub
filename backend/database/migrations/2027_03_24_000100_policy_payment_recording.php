<?php

declare(strict_types=1);

use App\Rbac\Rbac;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['key' => 'payments.record', 'module' => 'payments', 'name_th' => 'บันทึกการชำระเบี้ยจากลูกค้า', 'name_en' => 'Record premium payments'],
        ['key' => 'payments.void', 'module' => 'payments', 'name_th' => 'ยกเลิกรายการชำระเบี้ยที่บันทึกผิด', 'name_en' => 'Void premium payments'],
    ];

    public function up(): void
    {
        Schema::table('policy_payments', function (Blueprint $t): void {
            $t->string('payee', 16)->default('insurehub')->after('method');
            $t->string('pay_mode', 24)->default('full')->after('payee');
            $t->string('note', 255)->nullable()->after('reference');
            $t->decimal('adjustment_amount', 15, 2)->default(0)->after('vat_amount');
            $t->string('adjustment_kind', 24)->nullable()->after('adjustment_amount');
            $t->foreignId('proof_document_id')->nullable()->after('recorded_by_user_id')->constrained('policy_documents')->nullOnDelete();
            $t->timestamp('voided_at')->nullable()->after('proof_document_id');
            $t->foreignId('voided_by_user_id')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $t->string('void_reason', 255)->nullable()->after('voided_by_user_id');
            $t->index(['policy_id', 'voided_at'], 'pp_policy_voided_idx');
        });

        $now = now();
        foreach (self::PERMISSIONS as $row) {
            if (DB::table('permissions')->where('key', $row['key'])->exists()) {
                DB::table('permissions')->where('key', $row['key'])->update($row + ['updated_at' => $now]);
            } else {
                DB::table('permissions')->insert($row + ['description' => null, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $grants = [
            'super_admin' => ['payments.record', 'payments.void'],
            'admin' => ['payments.record', 'payments.void'],
            'finance' => ['payments.record', 'payments.void'],
            'sales_support' => ['payments.record'],
            'operations' => ['payments.record'],
        ];
        foreach ($grants as $roleKey => $keys) {
            $roleId = DB::table('roles')->where('key', $roleKey)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        try {
            app(Rbac::class)->invalidateAll();
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_column(self::PERMISSIONS, 'key'))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('policy_payments', function (Blueprint $t): void {
            $t->dropIndex('pp_policy_voided_idx');
            $t->dropConstrainedForeignId('voided_by_user_id');
            $t->dropConstrainedForeignId('proof_document_id');
            $t->dropColumn(['payee', 'pay_mode', 'note', 'adjustment_amount', 'adjustment_kind', 'voided_at', 'void_reason']);
        });
    }
};
