<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7b — admin payout cycle.
 *
 * One row per (agent, period) that batches together all unsettled
 * commission_transactions in the range. After creation the payout runs
 * through draft → issued → paid, with the corresponding txns marked as
 * `settled` and linked via commission_transactions.settled_by_payout_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_payouts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $t->date('period_from');
            $t->date('period_to');
            $t->string('status', 16)->default('draft');   // draft|issued|paid|void
            $t->decimal('gross_amount', 15, 2)->default(0);
            $t->decimal('wht_rate', 8, 4)->default(0);    // 0.03 = 3%
            $t->decimal('wht_amount', 15, 2)->default(0);
            $t->decimal('net_amount', 15, 2)->default(0);
            $t->string('bank_ref', 128)->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['tenant_id', 'agent_id', 'status'], 'ap_tenant_agent_status_idx');
            $t->index(['tenant_id', 'period_from', 'period_to'], 'ap_tenant_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_payouts');
    }
};
