<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent VAT settings (has_vat + vat_mode include/exclude) and a note history
 * so staff can log follow-ups on new/existing agents (e.g. "โทรคุยแล้ว").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->boolean('has_vat')->default(false)->after('vat_type');
            // 'include' = VAT included in the amount, 'exclude' = VAT added on top.
            $table->string('vat_mode', 8)->nullable()->after('has_vat');
        });

        Schema::create('agent_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->text('note');
            // Optional category so a follow-up can be tagged (call / follow-up / general).
            $table->string('kind', 16)->default('general');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'agent_id'], 'agent_notes_tenant_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_notes');
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn(['has_vat', 'vat_mode']);
        });
    }
};
