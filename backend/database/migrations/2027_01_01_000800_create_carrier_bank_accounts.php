<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('bank_name', 128)->nullable();
            $table->string('branch', 128)->nullable();
            $table->string('account_no', 64)->nullable();
            $table->string('account_name', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['carrier_id', 'sort_order']);
            $table->unique(['carrier_id', 'account_no'], 'cba_carrier_account_no_unique');
        });

        // One-off migration: promote existing carriers.bank_account_1 free-text
        // into a single row per carrier. The legacy column stays put so nothing
        // that reads it breaks; a follow-up migration can drop it once the
        // frontend no longer references it.
        $now = now();
        DB::table('carriers')
            ->whereNotNull('bank_account_1')
            ->where('bank_account_1', '<>', '')
            ->orderBy('id')
            ->each(function ($carrier) use ($now): void {
                DB::table('carrier_bank_accounts')->insert([
                    'carrier_id' => $carrier->id,
                    'bank_name' => mb_substr((string) $carrier->bank_account_1, 0, 128),
                    'is_primary' => true,
                    'sort_order' => 0,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_bank_accounts');
    }
};
