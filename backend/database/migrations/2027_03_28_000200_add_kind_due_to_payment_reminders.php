<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_reminders carried only staff-recorded dunning ("chased N times").
 * The annual premium-anniversary engine (ทวงเบี้ยรายปี) writes SYSTEM alerts
 * into the same log, so split the two:
 *
 *   - kind='manual'  → a human chased the customer (existing rows; the
 *     Collections "chased N times / last on" count stays about THIS only).
 *   - kind='system'  → an auto anniversary alert the cron raised; carries
 *     due_date = the anniversary it is about, so the cron stays idempotent
 *     (one system row per policy per due_date).
 *
 * Existing rows are all manual (they predate the engine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reminders', function (Blueprint $table): void {
            $table->string('kind', 16)->default('manual')->after('installment_no');
            // The premium anniversary this system alert targets (null for
            // manual dunning, which is about a running balance, not a date).
            $table->date('due_date')->nullable()->after('kind');
        });

        DB::table('payment_reminders')->whereNull('kind')->update(['kind' => 'manual']);

        // One system alert per policy per anniversary — the cron's idempotency
        // guard. Manual rows (due_date NULL) never collide here.
        Schema::table('payment_reminders', function (Blueprint $table): void {
            $table->unique(['policy_id', 'kind', 'due_date'], 'pr_policy_kind_due_uniq');
        });
    }

    public function down(): void
    {
        Schema::table('payment_reminders', function (Blueprint $table): void {
            $table->dropUnique('pr_policy_kind_due_uniq');
            $table->dropColumn(['kind', 'due_date']);
        });
    }
};
