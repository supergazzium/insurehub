<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payout eligibility policy change (business decision): commission is payable as
 * soon as the CUSTOMER has paid the premium — freelook and insurer-received are
 * no longer gating. So the system defaults become:
 *   payout_require_customer_paid      = true   (keep — wait for the customer)
 *   payout_require_freelook           = false  (drop)
 *   payout_require_insurer_received   = false  (drop — InsureHub fronts the pay)
 *
 * Changes the column defaults (new tenants) AND flips every existing tenant to
 * the new policy. A tenant can still re-enable any gate from ตั้งค่าหน่วยงาน.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t): void {
            $t->boolean('payout_require_freelook')->default(false)->change();
            $t->boolean('payout_require_insurer_received')->default(false)->change();
            $t->boolean('payout_require_customer_paid')->default(true)->change();
        });

        DB::table('tenants')->update([
            'payout_require_freelook' => false,
            'payout_require_insurer_received' => false,
            'payout_require_customer_paid' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t): void {
            $t->boolean('payout_require_freelook')->default(true)->change();
            $t->boolean('payout_require_insurer_received')->default(true)->change();
            $t->boolean('payout_require_customer_paid')->default(true)->change();
        });
    }
};
