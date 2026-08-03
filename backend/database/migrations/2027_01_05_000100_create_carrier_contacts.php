<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual contact rows per carrier — mirrors the carrier_bank_accounts
 * pattern (one row per person). Distinct from carrier_contact_groups which
 * is a department-based email routing concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_contacts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $t->string('first_name', 120)->nullable();
            $t->string('last_name', 120)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('email', 255)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['tenant_id', 'carrier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_contacts');
    }
};
