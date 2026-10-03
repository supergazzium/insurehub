<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ตารางงวดชำระ (installment schedule) per policy.
 *
 * Kept OUT of policy_payments on purpose: policy_payments is money actually
 * received — its created event fires the MGM commission pipeline and every
 * collections/report query sums it as "paid". A pending งวด living there
 * would be counted as paid and commissioned. Instead each งวด is a row here
 * and a payment points at the งวด it settles via policy_installment_id.
 *
 * Built by App\Services\Policy\InstallmentSchedule when a policy is issued;
 * amount_paid / status are re-derived from the policy's payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_no');     // 1-based งวดที่
            $table->unsignedSmallInteger('installment_total');  // จากทั้งหมด N งวด
            $table->date('due_date')->nullable();
            $table->decimal('amount_due', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->string('status', 16)->default('pending');   // pending | partial | paid
            $table->date('paid_date')->nullable();               // when it became fully paid
            $table->timestamps();

            $table->unique(['policy_id', 'installment_no'], 'pi_policy_no_uniq');
            $table->index(['tenant_id', 'status', 'due_date'], 'pi_tenant_status_due_idx');
        });

        Schema::table('policy_payments', function (Blueprint $table) {
            $table->foreignId('policy_installment_id')
                ->nullable()
                ->after('policy_id')
                ->constrained('policy_installments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('policy_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('policy_installment_id');
        });
        Schema::dropIfExists('policy_installments');
    }
};
