<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per email conversation. The thread_id is also the plus-address
        // suffix used in Reply-To (no-reply+<thread_id>@...) so inbound mail
        // routes back to the right thread without parsing the subject.
        Schema::create('mail_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('thread_id', 32)->index(); // T-xxxx — short form used in Reply-To + subject
            $table->string('subject');
            $table->string('reply_to_address'); // no-reply+T-xxx@insurehub.co.th
            $table->string('from_address');     // canonical sender for this thread

            // Optional linkage: which support case / customer / policy is this about?
            // None of these are required — a thread can stand alone.
            $table->string('case_id', 64)->nullable()->index();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('policies')->nullOnDelete();
            $table->foreignId('carrier_id')->nullable()->constrained('carriers')->nullOnDelete();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->boolean('closed')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'thread_id']);
        });

        // One row per outbound or inbound message. messageId is what Zoho
        // returns; we also keep the Zoho scheduled-mail id for cancellation.
        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('mail_thread_id')->constrained('mail_threads')->cascadeOnDelete();

            $table->string('direction', 8); // outbound | inbound
            $table->string('zoho_message_id', 128)->nullable()->index();
            $table->string('zoho_scheduled_mail_id', 128)->nullable();

            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->text('to_addresses');     // comma-separated; can be long
            $table->text('cc_addresses')->nullable();
            $table->text('bcc_addresses')->nullable();
            $table->string('reply_to')->nullable();

            $table->string('subject');
            $table->string('mail_format', 16)->default('html'); // plaintext | html
            $table->longText('content');

            $table->string('status', 24)->default('queued');
            // queued | sent | scheduled | failed | received
            $table->text('error')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();

            // Raw Zoho payload (for debug / audit). Sparse.
            $table->json('zoho_payload')->nullable();

            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'direction']);
            $table->index(['tenant_id', 'sent_at']);
        });

        // Attachments uploaded to Zoho — we keep the Zoho refs so we can reuse
        // them across messages within a thread.
        Schema::create('mail_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('mail_message_id')->nullable()->constrained('mail_messages')->cascadeOnDelete();

            // Zoho's attachment trio — the proxy returns these to the frontend
            // verbatim and the frontend stores them in AttachmentRef.
            $table->string('store_name');         // Zoho store id
            $table->string('attachment_name');    // filename
            $table->string('attachment_path');    // Zoho file id

            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'mail_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_attachments');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_threads');
    }
};
