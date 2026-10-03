<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ปีของลูกค้า — which year the customer is with InsureHub, as of this policy.
 * Stored (not derived from dates) because renewal reports and renewal-year
 * commission need the real figure, and date maths drifts across a lapse or a
 * carrier switch. Sits next to policy_year (ปีของกรมธรรม์). Null on legacy
 * rows until `policies:backfill-years` fills it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('customer_year')->nullable()->after('policy_year');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn('customer_year');
        });
    }
};
