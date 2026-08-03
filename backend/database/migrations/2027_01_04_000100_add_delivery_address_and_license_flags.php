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
        Schema::table('agents', function (Blueprint $t): void {
            // Second address used for document delivery (tax invoice address
            // stays in the existing address/sub_district/district/province/
            // postcode columns). If delivery_same_as_tax=true, we ignore the
            // delivery_* fields and reuse the tax address at render time.
            $t->boolean('delivery_same_as_tax')->default(true)->after('postcode');
            $t->string('delivery_address', 255)->nullable()->after('delivery_same_as_tax');
            $t->string('delivery_sub_district', 120)->nullable()->after('delivery_address');
            $t->string('delivery_district', 120)->nullable()->after('delivery_sub_district');
            $t->string('delivery_province', 120)->nullable()->after('delivery_district');
            $t->string('delivery_postcode', 16)->nullable()->after('delivery_province');

            // "Has license" booleans — separate from the detailed license_*
            // fields already on the table so the portal UI can capture a
            // simple yes/no without forcing the number+expiry to be filled.
            $t->boolean('has_life_license')->default(false)->after('license_life_expiry');
            $t->boolean('has_non_life_license')->default(false)->after('license_non_life_expiry');
        });

        // Backfill has_*_license from existing rows that have a license number.
        DB::statement("UPDATE agents SET has_life_license = 1 WHERE license_life_no IS NOT NULL AND license_life_no <> ''");
        DB::statement("UPDATE agents SET has_non_life_license = 1 WHERE license_non_life_no IS NOT NULL AND license_non_life_no <> ''");
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $t): void {
            $t->dropColumn([
                'delivery_same_as_tax',
                'delivery_address',
                'delivery_sub_district',
                'delivery_district',
                'delivery_province',
                'delivery_postcode',
                'has_life_license',
                'has_non_life_license',
            ]);
        });
    }
};
