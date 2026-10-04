<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->string('notice_no', 32);
            $table->decimal('annual_premium', 14, 2);
            $table->decimal('net_premium', 14, 2)->nullable();
            $table->decimal('duty_stamp', 14, 2)->nullable();
            $table->decimal('vat', 14, 2)->nullable();
            $table->decimal('discount', 14, 2)->nullable();
            $table->decimal('coverage', 16, 2)->nullable();
            $table->string('premium_mode', 24)->nullable();
            $table->date('due_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('carrier_document_id')->nullable()->constrained('policy_documents')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('policy_documents')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'notice_no']);
            $table->index(['tenant_id', 'policy_id']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->text('payment_channels')->nullable()->after('email_signature');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('payment_channels');
        });
        Schema::dropIfExists('renewal_notices');
    }
};
