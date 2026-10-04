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
        ['key' => 'receipts.view', 'module' => 'receipts', 'name_th' => 'ดูการรับค่าคอมจากบริษัทประกัน', 'name_en' => 'View insurer commission receipts'],
        ['key' => 'receipts.record', 'module' => 'receipts', 'name_th' => 'บันทึกรับค่าคอมจากบริษัทประกัน', 'name_en' => 'Record insurer commission receipts'],
        ['key' => 'receipts.reopen', 'module' => 'receipts', 'name_th' => 'เปิดรายการรับค่าคอมที่รับแล้วกลับมาแก้', 'name_en' => 'Reopen received commission'],
    ];

    public function up(): void
    {
        Schema::table('commission_receivables', function (Blueprint $t): void {
            $t->unsignedSmallInteger('line_no')->default(0)->after('commission_type');
            $t->string('adjustment_kind', 16)->nullable()->after('line_no');
            $t->foreignId('policy_event_id')->nullable()->after('adjustment_kind')->constrained('policy_events')->nullOnDelete();
            $t->string('diff_reason', 32)->nullable()->after('note');
            $t->string('diff_note', 500)->nullable()->after('diff_reason');
            $t->string('source', 16)->default('system')->after('diff_note');
            $t->foreignId('received_by_user_id')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $t->unique(['policy_id', 'commission_type', 'line_no'], 'cr_policy_type_line_unq');
        });
        Schema::table('commission_receivables', function (Blueprint $t): void {
            $t->dropUnique('cr_policy_type_unq');
        });

        Schema::table('commission_receipt_batches', function (Blueprint $t): void {
            $t->char('statement_month', 7)->nullable()->after('policy_year');
            $t->decimal('statement_total', 15, 2)->nullable()->after('statement_month');
        });

        Schema::table('policies', function (Blueprint $t): void {
            $t->boolean('imported_from_legacy')->default(false)->index();
        });

        DB::statement(<<<'SQL'
            UPDATE policies p
            SET p.imported_from_legacy = 1
            WHERE NOT EXISTS (
                SELECT 1 FROM policy_events d WHERE d.policy_id = p.id AND d.type = 'draftCreated'
            )
            AND (
                EXISTS (
                    SELECT 1 FROM policy_events c
                    WHERE c.policy_id = p.id AND c.type = 'created'
                    AND (c.payload LIKE '%"imported": true%' OR c.payload LIKE '%"imported":true%')
                )
                OR p.com_rec_check IS NOT NULL
                OR p.recorded_by_username IS NOT NULL
                OR p.legacy_policy_status_id IS NOT NULL
                OR p.premium_check IS NOT NULL
            )
        SQL);

        $now = now();
        foreach (self::PERMISSIONS as $row) {
            $exists = DB::table('permissions')->where('key', $row['key'])->exists();
            if ($exists) {
                DB::table('permissions')->where('key', $row['key'])->update($row + ['updated_at' => $now]);
            } else {
                DB::table('permissions')->insert($row + ['description' => null, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $grants = [
            'super_admin' => ['receipts.view', 'receipts.record', 'receipts.reopen'],
            'admin' => ['receipts.view', 'receipts.record', 'receipts.reopen'],
            'finance' => ['receipts.view', 'receipts.record', 'receipts.reopen'],
            'executive' => ['receipts.view'],
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

        Schema::table('policies', fn (Blueprint $t) => $t->dropColumn('imported_from_legacy'));

        Schema::table('commission_receipt_batches', fn (Blueprint $t) => $t->dropColumn(['statement_month', 'statement_total']));

        DB::table('commission_receivables')->where('commission_type', 'ADJ')->delete();
        Schema::table('commission_receivables', function (Blueprint $t): void {
            $t->unique(['policy_id', 'commission_type'], 'cr_policy_type_unq');
        });
        Schema::table('commission_receivables', function (Blueprint $t): void {
            $t->dropUnique('cr_policy_type_line_unq');
            $t->dropConstrainedForeignId('policy_event_id');
            $t->dropConstrainedForeignId('received_by_user_id');
            $t->dropColumn(['line_no', 'adjustment_kind', 'diff_reason', 'diff_note', 'source']);
        });
    }
};
