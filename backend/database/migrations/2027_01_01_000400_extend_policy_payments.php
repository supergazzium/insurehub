<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_payments', function (Blueprint $table): void {
            $table->foreignId('payment_inscomp_to_id')
                ->nullable()
                ->after('reference')
                ->constrained('payment_inscomp_tos')
                ->nullOnDelete();

            $table->foreignId('payment_inscomp_status_id')
                ->nullable()
                ->after('payment_inscomp_to_id')
                ->constrained('payment_inscomp_statuses')
                ->nullOnDelete();

            $table->unsignedInteger('count_slip')->nullable()->after('payment_inscomp_status_id');
            $table->string('validate_amount', 16)->nullable()->after('count_slip');
        });
    }

    public function down(): void
    {
        Schema::table('policy_payments', function (Blueprint $table): void {
            $table->dropForeign(['payment_inscomp_status_id']);
            $table->dropForeign(['payment_inscomp_to_id']);
            $table->dropColumn([
                'payment_inscomp_to_id',
                'payment_inscomp_status_id',
                'count_slip',
                'validate_amount',
            ]);
        });
    }
};
