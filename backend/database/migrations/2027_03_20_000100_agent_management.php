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
        Schema::table('agents', function (Blueprint $table): void {
            $table->string('license_life_doc_path')->nullable()->after('license_life_expiry');
            $table->string('license_non_life_doc_path')->nullable()->after('license_non_life_expiry');
        });

        Schema::table('commission_payout_batch_items', function (Blueprint $table): void {
            $table->string('payee_name')->nullable()->after('vat_type');
            $table->string('payee_kind', 16)->nullable()->after('payee_name');
            $table->string('payee_tax_id', 20)->nullable()->after('payee_kind');
        });

        DB::table('agents')->where('vat_type', '2')->where('has_vat', false)->update(['has_vat' => true, 'vat_mode' => 'exclude']);
        DB::table('agents')->where('vat_type', '3')->where('has_vat', false)->update(['has_vat' => true, 'vat_mode' => 'include']);
    }

    public function down(): void
    {
        Schema::table('commission_payout_batch_items', function (Blueprint $table): void {
            $table->dropColumn(['payee_name', 'payee_kind', 'payee_tax_id']);
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn(['license_life_doc_path', 'license_non_life_doc_path']);
        });
    }
};
