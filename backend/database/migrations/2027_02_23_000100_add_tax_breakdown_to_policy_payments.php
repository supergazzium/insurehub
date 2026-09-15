<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-payment tax breakdown. For ผ่อน / แบ่งชำระ the net premium, duty stamp
 * and VAT of each งวด are derived from the amount ACTUALLY paid (proportional
 * to the policy's own net:duty:vat structure), not the full annual gross — so
 * every installment carries its own decomposed figures for accounting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_payments', function (Blueprint $table): void {
            $table->decimal('net_amount', 15, 2)->nullable()->after('amount');
            $table->decimal('duty_amount', 15, 2)->nullable()->after('net_amount');
            $table->decimal('vat_amount', 15, 2)->nullable()->after('duty_amount');
        });
    }

    public function down(): void
    {
        Schema::table('policy_payments', function (Blueprint $table): void {
            $table->dropColumn(['net_amount', 'duty_amount', 'vat_amount']);
        });
    }
};
