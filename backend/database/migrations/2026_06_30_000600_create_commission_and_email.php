<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frontend: stores/commissions.ts: CommissionTransaction (tall ledger).
        Schema::create('commission_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 16);   // earning|override|clawback|referralBonus
            $table->string('status', 16)->default('unsettled'); // unsettled|settled|reversed
            $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('policy_event_id')->nullable()->constrained('policy_events')->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('reverses_txn_id')->nullable()->constrained('commission_transactions')->nullOnDelete();
            $table->decimal('base_premium', 15, 2)->default(0);
            $table->string('payer_level', 4)->nullable(); // l1..l5
            $table->decimal('diff_pct', 8, 4)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->foreignId('settled_by_payout_id')->nullable();
            $table->index(['tenant_id', 'agent_id', 'status']);
            $table->index(['tenant_id', 'policy_id']);
            $table->unique(['tenant_id', 'idempotency_key']);
        });

        // Frontend: stores/commissions.ts: CommissionRun.
        Schema::create('commission_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('policy_event_id')->nullable()->constrained('policy_events')->nullOnDelete();
            $table->string('policy_event_type', 32)->nullable();
            $table->timestamp('run_at')->useCurrent();
            $table->timestamps();
            $table->index(['tenant_id', 'policy_id']);
        });

        // Pivot — CommissionRun.transactionIds[].
        Schema::create('commission_run_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commission_run_id')->constrained('commission_runs')->cascadeOnDelete();
            $table->foreignId('commission_transaction_id')->constrained('commission_transactions')->cascadeOnDelete();
            $table->unique(['commission_run_id', 'commission_transaction_id'], 'run_txn_unique');
        });

        // Frontend: stores/commissions.ts: ReferralBonusConfig (per tenant).
        Schema::create('referral_bonus_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete()->unique();
            $table->boolean('enabled')->default(false);
            $table->string('type', 16)->default('flat'); // flat | pctOfFirstYear
            $table->decimal('flat_amount', 15, 2)->default(0);
            $table->decimal('pct_value', 8, 4)->default(0);
            $table->timestamps();
        });

        // Frontend: stores/emailTemplates.ts: EmailTemplate.
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('department', 32); // new_business|underwriting|policy_issue|claims|other
            $table->string('subject');
            $table->longText('body');
            $table->boolean('is_built_in')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('referral_bonus_configs');
        Schema::dropIfExists('commission_run_transactions');
        Schema::dropIfExists('commission_runs');
        Schema::dropIfExists('commission_transactions');
    }
};
