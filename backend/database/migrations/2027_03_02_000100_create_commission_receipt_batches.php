<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt batches for insurer commission statements (DEV Spec ชุดที่ 3 §8).
 * Each batch groups the Excel/PDF files an insurer sent for one round, plus
 * the receivable rows reconciled against them. Phase 1 is manual — files are
 * supporting documents only, not auto-parsed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_receipt_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no', 32)->nullable();           // REC-202609-0001
            $table->foreignId('insurer_id')->constrained('carriers')->cascadeOnDelete();
            $table->unsignedSmallInteger('policy_year')->nullable();
            $table->date('statement_date')->nullable();           // date printed on the statement
            $table->date('received_file_date')->nullable();       // when the team received the file
            $table->text('remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'insurer_id', 'policy_year'], 'crb_tenant_insurer_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_receipt_batches');
    }
};
