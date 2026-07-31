<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OTPs issued for public email-verification during agent signup.
        // We store a hash of the code, never the plaintext, plus enough
        // metadata to enforce rate-limits and attempt caps.
        Schema::create('email_otps', function (Blueprint $t): void {
            $t->id();
            $t->string('email', 255)->index();
            $t->string('code_hash', 255);
            $t->timestamp('expires_at')->index();
            $t->timestamp('consumed_at')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('ip', 45)->nullable();
            $t->timestamps();
        });

        // Short-lived signed tokens returned after successful OTP verify.
        // Consumed by AgentRegisterRequest to prove the email is verified.
        Schema::create('email_verification_tokens', function (Blueprint $t): void {
            $t->id();
            $t->string('token', 128)->unique();
            $t->string('email', 255)->index();
            $t->timestamp('expires_at')->index();
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
        Schema::dropIfExists('email_otps');
    }
};
