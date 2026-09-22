<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting statement files attached to a receipt batch (DEV Spec ชุดที่ 3
 * §9.3). Original filename + stored path + validation metadata. Files are not
 * overwritten in place — a new row is a new version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_receipt_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('commission_receipt_batches')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename', 255);
            $table->string('storage_path', 512);
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'batch_id'], 'crf_tenant_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_receipt_files');
    }
};
