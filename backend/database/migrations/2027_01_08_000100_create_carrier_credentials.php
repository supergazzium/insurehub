<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-carrier portal login credentials.
 *
 * One carrier can have N credentials (e.g. broker portal + claims portal +
 * commission portal). Password is encrypted at rest via Laravel's Crypt
 * cast (same pattern as `agents.id_card`) so it's reversibly readable to
 * anyone who can access the drawer.
 *
 * Label is free-text (128 chars) — the UI treats it like a sticky-note
 * tag with autocomplete over previously-used labels for that carrier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_credentials', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $t->string('url', 512)->nullable();
            $t->string('username', 255)->nullable();
            // Encrypted — column type has to be TEXT because Crypt strings
            // are much longer than the plaintext they wrap.
            $t->text('password')->nullable();
            $t->string('label', 128)->nullable();
            // Manual sort within a carrier's credentials list.
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['tenant_id', 'carrier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_credentials');
    }
};
