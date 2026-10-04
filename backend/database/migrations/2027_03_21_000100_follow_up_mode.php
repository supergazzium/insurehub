<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->string('sales_channel', 16)->default('face_to_face')->after('new_or_renew');
            $table->date('freelook_doc_sent_at')->nullable()->after('freelook_end_date');
            $table->date('freelook_doc_signed_at')->nullable()->after('freelook_doc_sent_at');
            $table->date('freelook_doc_returned_at')->nullable()->after('freelook_doc_signed_at');
            $table->timestamp('cancellation_reviewed_at')->nullable()->after('cancel_status');
            $table->foreignId('cancellation_reviewed_by_user_id')->nullable()->after('cancellation_reviewed_at')->constrained('users')->nullOnDelete();
            $table->decimal('refund_amount', 15, 2)->nullable()->after('cancellation_reviewed_by_user_id');
            $table->decimal('commission_clawback_amount', 15, 2)->nullable()->after('refund_amount');
            $table->string('cancel_check_refund', 8)->default('pending')->after('commission_clawback_amount');
            $table->string('cancel_check_clawback', 8)->default('pending')->after('cancel_check_refund');
            $table->string('cancel_check_carrier_deduct', 8)->default('pending')->after('cancel_check_clawback');
            $table->text('cancel_review_note')->nullable()->after('cancel_check_carrier_deduct');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('policy_verify_required')->default(false);
            $table->unsignedSmallInteger('freelook_days_standard')->default(15);
            $table->unsignedSmallInteger('freelook_days_remote')->default(30);
            $table->unsignedSmallInteger('no_commission_window_days')->default(180);
        });

        Schema::table('commission_payout_adjustments', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
        });
        Schema::table('commission_payout_adjustments', function (Blueprint $table): void {
            $table->unsignedBigInteger('batch_id')->nullable()->change();
            $table->foreign('batch_id')->references('id')->on('commission_payout_batches')->cascadeOnDelete();
            $table->foreignId('policy_id')->nullable()->after('agent_code')->constrained('policies')->nullOnDelete();
            $table->string('status', 16)->default('applied')->after('type');
            $table->timestamp('applied_at')->nullable()->after('status');
            $table->index(['tenant_id', 'agent_id', 'status']);
        });

        Schema::create('pending_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 16);
            $table->json('payload')->nullable();
            $table->json('before')->nullable();
            $table->json('fields')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('maker_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checker_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['entity_type', 'entity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_changes');

        Schema::table('commission_payout_adjustments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'agent_id', 'status']);
            $table->dropConstrainedForeignId('policy_id');
            $table->dropColumn(['status', 'applied_at']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['policy_verify_required', 'freelook_days_standard', 'freelook_days_remote', 'no_commission_window_days']);
        });

        Schema::table('policies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancellation_reviewed_by_user_id');
            $table->dropColumn([
                'sales_channel', 'freelook_doc_sent_at', 'freelook_doc_signed_at', 'freelook_doc_returned_at',
                'cancellation_reviewed_at', 'refund_amount', 'commission_clawback_amount',
                'cancel_check_refund', 'cancel_check_clawback', 'cancel_check_carrier_deduct', 'cancel_review_note',
            ]);
        });
    }
};
