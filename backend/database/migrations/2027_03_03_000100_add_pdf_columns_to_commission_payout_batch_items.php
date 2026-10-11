<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the batch-item snapshot with the columns the formal per-agent payout
 * statement (ค่านายหน้า / ค่าแนะนำประจำเดือน — the IN240455 reference PDF) needs
 * but the original snapshot did not carry.
 *
 * These are all FROZEN at batch creation alongside the existing snapshot_*
 * columns (spec §12 "Source of Truth = Batch Snapshot"). A re-issued (corrected)
 * batch re-snapshots fresh from the policy at that time.
 *
 * Column groups mirror the printed table:
 *   เบี้ยประกัน   → snapshot_net_premium (สุทธิ), snapshot_total_premium (ทั้งหมด)
 *   ค่าคอม        → snapshot_main_rate (%หลัก), snapshot_rider_rate (%พ่วง)
 *   ยอดหัก (B)    → snapshot_payoff_discount (จ่ายขาด/ส่วนลด/ผ่อน),
 *                   snapshot_card_fee (ค่าธรรมเนียมบัตรเครดิต)
 *   หัก ณ ที่จ่าย → snapshot_wht_rate, snapshot_wht_amount (3%)
 *   labels        → snapshot_customer_name (ชื่อผู้เอาประกัน),
 *                   snapshot_rider_name (สัญญาเพิ่มเติม), snapshot_note (หมายเหตุ)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_payout_batch_items', function (Blueprint $table): void {
            // เบี้ยประกัน group.
            $table->decimal('snapshot_net_premium', 15, 2)->default(0)->after('snapshot_base_premium');
            $table->decimal('snapshot_total_premium', 15, 2)->default(0)->after('snapshot_net_premium');
            // ค่าคอม rates (per leg) — stored as fractions (0.12 = 12%).
            $table->decimal('snapshot_main_rate', 8, 4)->nullable()->after('snapshot_agent_commission');
            $table->decimal('snapshot_rider_rate', 8, 4)->nullable()->after('snapshot_rider_commission');
            // ยอดหัก (B) group — reduce the agent's payout.
            $table->decimal('snapshot_payoff_discount', 15, 2)->default(0)->after('snapshot_rider_rate');
            $table->decimal('snapshot_card_fee', 15, 2)->default(0)->after('snapshot_payoff_discount');
            // หัก ณ ที่จ่าย (WHT) — rate as fraction (0.03 = 3%).
            $table->decimal('snapshot_wht_rate', 8, 4)->default(0)->after('snapshot_card_fee');
            $table->decimal('snapshot_wht_amount', 15, 2)->default(0)->after('snapshot_wht_rate');
            // Printed labels frozen so the PDF survives later master edits.
            $table->string('snapshot_customer_name')->nullable()->after('snapshot_wht_amount');
            $table->string('snapshot_rider_name')->nullable()->after('snapshot_customer_name');
            $table->string('snapshot_note', 255)->nullable()->after('snapshot_rider_name');
        });
    }

    public function down(): void
    {
        Schema::table('commission_payout_batch_items', function (Blueprint $table): void {
            $table->dropColumn([
                'snapshot_net_premium',
                'snapshot_total_premium',
                'snapshot_main_rate',
                'snapshot_rider_rate',
                'snapshot_payoff_discount',
                'snapshot_card_fee',
                'snapshot_wht_rate',
                'snapshot_wht_amount',
                'snapshot_customer_name',
                'snapshot_rider_name',
                'snapshot_note',
            ]);
        });
    }
};
