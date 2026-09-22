<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent commission payout batches (ระบบทำจ่ายค่าคอมประจำเดือน — ตัวแทน).
 *
 * A batch is one payout run: staff pick a date range (by วันแจ้งงาน =
 * policies.created_at), the system snapshots the eligible agent-commission
 * items into commission_payout_batch_items, and later marks the whole batch
 * PAID in one transaction. The snapshot is the source of truth — amounts are
 * frozen at batch creation and never re-queried (DEV Spec ชุดที่ 2 §12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // วันแจ้งงาน range the batch was built from.
            $table->date('from_date');
            $table->date('to_date');
            // DRAFT → GENERATED → APPROVED → PAID → CANCELLED (spec §8.1).
            $table->string('status', 16)->default('DRAFT');
            // Snapshot roll-ups (denormalised for the list page).
            $table->unsignedInteger('total_agents')->default(0);
            $table->unsignedInteger('total_items')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            // Lifecycle actors + timestamps.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('payment_reference', 128)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'cpb_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payout_batches');
    }
};
