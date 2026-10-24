<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank-account guard (money-safety 1B).
 *
 * An agent with no bank account used to be added to a batch and "paid" with a
 * NULL account — no block, no warning. This flags such agents as "รอข้อมูล"
 * (needs_info) at batch creation; markPaid then refuses to pay a batch that
 * still has a needs_info agent, so money never goes to a bankless row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_payout_batch_agents', function (Blueprint $table): void {
            // รอข้อมูล — set true when the agent has no bank account at batch time.
            $table->boolean('needs_info')->default(false)->after('bank_account_name');
            $table->string('needs_info_reason', 255)->nullable()->after('needs_info');
        });
    }

    public function down(): void
    {
        Schema::table('commission_payout_batch_agents', function (Blueprint $table): void {
            $table->dropColumn(['needs_info', 'needs_info_reason']);
        });
    }
};
