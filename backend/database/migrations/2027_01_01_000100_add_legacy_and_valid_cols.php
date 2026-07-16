<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('commission_code', 16)->nullable()->after('code')->index();
            $table->date('valid_start')->nullable()->after('active');
            $table->date('valid_end')->nullable()->after('valid_start');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('legacy_id', 32)->nullable()->after('customer_code')->index();
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->string('head_status', 32)->nullable()->after('signup_type');
        });

        Schema::table('policies', function (Blueprint $table): void {
            $table->date('create_date')->nullable()->after('app_date');
            $table->decimal('net_premium', 15, 2)->nullable()->after('main_premium');

            // Main-product commission split (kept on the policy row; riders live on policy_riders).
            $table->decimal('main_com_rate_inh', 8, 4)->nullable()->after('net_premium');
            $table->decimal('main_com_amt_inh', 15, 2)->nullable()->after('main_com_rate_inh');
            $table->decimal('main_com_rate_ag', 8, 4)->nullable()->after('main_com_amt_inh');
            $table->decimal('main_com_amt_ag', 15, 2)->nullable()->after('main_com_rate_ag');

            // Import data-quality flags.
            $table->string('premium_check', 16)->nullable();          // ok | mismatch
            $table->boolean('vehicle_on_non_motor')->default(false);  // source-data drift flag
            $table->text('import_notes')->nullable();                 // free-text audit trail
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn([
                'create_date',
                'net_premium',
                'main_com_rate_inh',
                'main_com_amt_inh',
                'main_com_rate_ag',
                'main_com_amt_ag',
                'premium_check',
                'vehicle_on_non_motor',
                'import_notes',
            ]);
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn('head_status');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['legacy_id']);
            $table->dropColumn('legacy_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['valid_start', 'valid_end']);
            $table->dropIndex(['commission_code']);
            $table->dropColumn('commission_code');
        });
    }
};
