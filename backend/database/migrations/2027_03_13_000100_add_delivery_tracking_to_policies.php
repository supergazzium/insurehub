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
        Schema::table('policies', function (Blueprint $table) {
            $table->string('delivery_method', 16)->nullable()->after('mailing_note');
            $table->string('courier', 64)->nullable()->after('delivery_method');
            $table->string('tracking_no', 64)->nullable()->after('courier');
            $table->date('shipped_date')->nullable()->after('tracking_no');
            $table->date('delivered_date')->nullable()->after('shipped_date');
            $table->string('delivered_to')->nullable()->after('delivered_date');
            $table->index(['tenant_id', 'shipped_date'], 'policies_tenant_shipped_idx');
        });

        DB::table('policies')->whereNotNull('mailing_date')->update(['shipped_date' => DB::raw('mailing_date')]);
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropIndex('policies_tenant_shipped_idx');
            $table->dropColumn(['delivery_method', 'courier', 'tracking_no', 'shipped_date', 'delivered_date', 'delivered_to']);
        });
    }
};
