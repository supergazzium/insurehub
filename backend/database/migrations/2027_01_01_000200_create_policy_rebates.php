<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dedicated ledger for policy rebates — replaces the scratchpad block on `policies`.
        // Source: insurehub.rebates_ledger (19,741 rows, one per policy).
        Schema::create('policy_rebates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();

            // InH (in-house) side.
            $table->string('rebate_status', 32)->nullable();
            $table->date('earn_date')->nullable();
            $table->string('ov_status', 32)->nullable();
            $table->date('ov_date')->nullable();
            $table->decimal('calculated_amount', 15, 2)->nullable();
            $table->decimal('calculated_ov', 15, 2)->nullable();
            $table->decimal('actual_amount', 15, 2)->nullable();
            $table->decimal('actual_ov', 15, 2)->nullable();
            $table->string('validate_amount', 16)->nullable();
            $table->string('validate_ov', 16)->nullable();

            // AG (agent) side.
            $table->string('agent_rebate_status', 32)->nullable();
            $table->date('agent_receive_date')->nullable();
            $table->decimal('calculated_agent_amount', 15, 2)->nullable();
            $table->decimal('actual_agent_amount', 15, 2)->nullable();
            $table->string('agent_check_status', 16)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'policy_id']);
            $table->unique(['policy_id']); // one row per policy in source
        });

        // Retire the rebate scratchpad columns on policies now that the dedicated table exists.
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn([
                'rebate_status',
                'rebate_earn_date',
                'ov_status',
                'rebate_ov_date',
                'cal_rebate_amt',
                'cal_rebate_ov',
                'act_rebate_amt',
                'act_rebate_ov',
                'validate_rebate_amt',
                'validate_rebate_ov',
                'rebate_status_ag',
                'rebate_rec_date_ag',
                'cal_rebate_amt_ag',
                'act_rebate_amt_ag',
                'check_ag_rebate',
            ]);
        });
    }

    public function down(): void
    {
        // Recreate the scratchpad columns for a clean rollback path.
        Schema::table('policies', function (Blueprint $table): void {
            $table->string('rebate_status', 32)->nullable();
            $table->date('rebate_earn_date')->nullable();
            $table->string('ov_status', 32)->nullable();
            $table->date('rebate_ov_date')->nullable();
            $table->decimal('cal_rebate_amt', 15, 2)->nullable();
            $table->decimal('cal_rebate_ov', 15, 2)->nullable();
            $table->decimal('act_rebate_amt', 15, 2)->nullable();
            $table->decimal('act_rebate_ov', 15, 2)->nullable();
            $table->string('validate_rebate_amt', 16)->nullable();
            $table->string('validate_rebate_ov', 16)->nullable();
            $table->string('rebate_status_ag', 32)->nullable();
            $table->date('rebate_rec_date_ag')->nullable();
            $table->decimal('cal_rebate_amt_ag', 15, 2)->nullable();
            $table->decimal('act_rebate_amt_ag', 15, 2)->nullable();
            $table->string('check_ag_rebate', 16)->nullable();
        });

        Schema::dropIfExists('policy_rebates');
    }
};
