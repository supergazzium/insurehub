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
        Schema::table('commission_payout_batches', function (Blueprint $t): void {
            $t->unsignedTinyInteger('calc_version')->default(1)->after('status');
        });

        Schema::table('commission_payout_batch_items', function (Blueprint $t): void {
            $t->string('line_type', 16)->default('base')->after('policy_id');
            $t->foreignId('ledger_id')->nullable()->after('line_type')->constrained('commission_ledgers')->nullOnDelete();
            $t->foreignId('source_agent_id')->nullable()->after('agent_id')->constrained('agents')->nullOnDelete();
            $t->decimal('paid_ratio', 7, 4)->nullable()->after('snapshot_rider_commission');
            $t->decimal('entitlement_amount', 15, 2)->nullable()->after('paid_ratio');
            $t->string('ratio_label', 32)->nullable()->after('entitlement_amount');
            $t->boolean('condition_override')->default(false)->after('ratio_label');
            $t->index(['policy_id', 'line_type'], 'cpbi_policy_line_idx');
        });

        Schema::table('commission_ledgers', function (Blueprint $t): void {
            $t->foreignId('payout_batch_id')->nullable()->after('status')->constrained('commission_payout_batches')->nullOnDelete();
            $t->timestamp('paid_at')->nullable()->after('payout_batch_id');
            $t->decimal('mgmt_fee_amount', 15, 2)->nullable()->after('amount');
        });

        DB::table('commission_ledgers')
            ->where('payout_type', 'DIRECT_COMMISSION')
            ->update(['mgmt_fee_amount' => DB::raw('CASE WHEN amount - ROUND(base_premium * COALESCE(standard_rate, 0), 2) > 0 THEN amount - ROUND(base_premium * COALESCE(standard_rate, 0), 2) ELSE 0 END')]);

        Schema::table('commission_payout_adjustments', function (Blueprint $t): void {
            $t->foreignId('ledger_id')->nullable()->after('policy_id')->constrained('commission_ledgers')->nullOnDelete();
        });

        Schema::create('commission_payout_payees', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('batch_id')->constrained('commission_payout_batches')->cascadeOnDelete();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $t->string('agent_code', 32)->nullable();
            $t->string('payee_name')->nullable();
            $t->string('payee_kind', 16)->nullable();
            $t->text('payee_tax_id')->nullable();
            $t->text('payee_address')->nullable();
            $t->string('vat_type', 4)->default('1');
            $t->decimal('wht_rate', 5, 2)->default(0);
            $t->string('wht_form', 8)->nullable();
            $t->decimal('base_amount', 15, 2)->default(0);
            $t->decimal('self_mgmt_amount', 15, 2)->default(0);
            $t->decimal('referral_amount', 15, 2)->default(0);
            $t->decimal('override_amount', 15, 2)->default(0);
            $t->decimal('gross_amount', 15, 2)->default(0);
            $t->decimal('deduction_amount', 15, 2)->default(0);
            $t->decimal('taxable_amount', 15, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->decimal('wht_amount', 15, 2)->default(0);
            $t->decimal('net_amount', 15, 2)->default(0);
            $t->string('bank_name')->nullable();
            $t->text('bank_account_no')->nullable();
            $t->text('bank_account_name')->nullable();
            $t->timestamp('frozen_at')->nullable();
            $t->unsignedSmallInteger('cert_book')->nullable();
            $t->unsignedInteger('cert_no')->nullable();
            $t->date('cert_date')->nullable();
            $t->timestamps();
            $t->unique(['batch_id', 'agent_id'], 'cpp_batch_agent_unique');
            $t->index(['tenant_id', 'cert_date'], 'cpp_tenant_cert_date_idx');
        });

        Schema::create('tax_cert_sequences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_no')->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'year']);
        });

        Schema::create('policy_payout_overrides', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $t->text('reason');
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'policy_id']);
        });

        Schema::table('tenants', function (Blueprint $t): void {
            $t->boolean('payout_require_freelook')->default(true);
            $t->boolean('payout_require_insurer_received')->default(true);
            $t->boolean('payout_require_customer_paid')->default(true);
            $t->decimal('wht_rate_individual', 5, 2)->default(3);
            $t->decimal('wht_rate_corporate', 5, 2)->default(3);
        });

        Schema::table('agents', function (Blueprint $t): void {
            $t->decimal('wht_rate', 5, 2)->nullable();
        });

        $finance = DB::table('roles')->where('key', 'finance')->value('id');
        if ($finance !== null) {
            $now = now();
            foreach (DB::table('permissions')->where('key', 'like', 'payouts.%')->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $finance, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->dropColumn('wht_rate'));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn([
            'payout_require_freelook', 'payout_require_insurer_received', 'payout_require_customer_paid',
            'wht_rate_individual', 'wht_rate_corporate',
        ]));
        Schema::dropIfExists('policy_payout_overrides');
        Schema::dropIfExists('tax_cert_sequences');
        Schema::dropIfExists('commission_payout_payees');
        Schema::table('commission_payout_adjustments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('ledger_id');
        });
        Schema::table('commission_ledgers', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('payout_batch_id');
            $t->dropColumn(['paid_at', 'mgmt_fee_amount']);
        });
        Schema::table('commission_payout_batch_items', function (Blueprint $t): void {
            $t->dropIndex('cpbi_policy_line_idx');
            $t->dropConstrainedForeignId('ledger_id');
            $t->dropConstrainedForeignId('source_agent_id');
            $t->dropColumn(['line_type', 'paid_ratio', 'entitlement_amount', 'ratio_label', 'condition_override']);
        });
        Schema::table('commission_payout_batches', fn (Blueprint $t) => $t->dropColumn('calc_version'));
    }
};
