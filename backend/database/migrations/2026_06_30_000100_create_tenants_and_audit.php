<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->string('oic_license', 32)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('district')->nullable();
            $table->string('amphoe')->nullable();
            $table->string('province')->nullable();
            $table->string('postcode', 16)->nullable();

            // Commission mode: asEarned | advance (frontend stores/commissions).
            $table->string('commission_mode', 16)->default('asEarned');

            // Payout config (frontend pages/settings/TenantSettings.vue).
            $table->string('payout_cycle', 16)->default('monthly'); // weekly|biweekly|monthly
            $table->decimal('payout_min_balance', 15, 2)->default(0);
            $table->boolean('payout_auto_approve')->default(false);

            // Branding.
            $table->string('brand_color', 16)->default('#0e74e8');
            $table->text('email_signature')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Audit log — frontend AuditEntry { id, time, user, action, target, ip, result }.
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('actor')->nullable(); // display name fallback
            $table->string('action', 64);
            $table->string('target')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('result', 16)->default('success'); // success|failed
            $table->json('metadata')->nullable();
            $table->index(['tenant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
        Schema::dropIfExists('tenants');
    }
};
