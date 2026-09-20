<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-collection follow-up log (การติดตามเงิน / dunning).
 *
 * Staff record each time they chase a customer for an outstanding balance —
 * on the whole policy or on a specific installment งวด. Append-only history so
 * the collections page can show "chased N times, last on <date>".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            // Which งวด this reminder targets (null = whole-policy / cash case).
            $table->unsignedSmallInteger('installment_no')->nullable();
            // How the customer was contacted.
            $table->string('channel', 16)->default('phone'); // phone/line/email/sms/inperson/other
            $table->text('note')->nullable();
            // The outstanding amount at the time of the reminder (snapshot).
            $table->decimal('amount_due', 15, 2)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'policy_id'], 'pr_tenant_policy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminders');
    }
};
