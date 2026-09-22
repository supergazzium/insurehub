<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Insurer commission receivables (DEV Spec ชุดที่ 3 §9.1). One row per
 * (policy × commission_type MAIN/OV). expected_amount is seeded from the
 * existing commission data (policies.comm_carrier_to_hub_amount / policy_
 * rebates.calculated_ov) — never recomputed here. Staff manually key the
 * statement/actual amount; the system computes the difference and suggests
 * Matched/Mismatch. `version` powers optimistic locking (spec §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_receivables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurer_id')->nullable()->constrained('carriers')->nullOnDelete();
            $table->unsignedSmallInteger('policy_year')->nullable();
            $table->string('commission_type', 8);                 // MAIN | OV
            $table->decimal('expected_amount', 15, 2)->default(0);
            $table->decimal('statement_amount', 15, 2)->nullable(); // what the insurer said
            $table->decimal('received_amount', 15, 2)->nullable();  // actually received on confirm
            // Pending | Matched | Mismatch | Received | No Commission
            $table->string('status', 16)->default('Pending');
            $table->date('received_date')->nullable();
            $table->foreignId('receipt_batch_id')->nullable()->constrained('commission_receipt_batches')->nullOnDelete();
            $table->text('note')->nullable();
            $table->unsignedInteger('version')->default(0);        // optimistic lock
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['policy_id', 'commission_type'], 'cr_policy_type_unq');
            $table->index(['tenant_id', 'insurer_id', 'policy_year', 'commission_type', 'status'], 'cr_filter_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_receivables');
    }
};
