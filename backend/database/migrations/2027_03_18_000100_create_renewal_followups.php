<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_followups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('result', 24);
            $table->string('reason_code', 32)->nullable();
            $table->string('reason_note', 500)->nullable();
            $table->text('note')->nullable();
            $table->date('next_followup_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'policy_id']);
            $table->index(['tenant_id', 'next_followup_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_followups');
    }
};
