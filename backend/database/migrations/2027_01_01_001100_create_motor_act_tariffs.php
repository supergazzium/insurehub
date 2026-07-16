<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compulsory Motor Insurance (ACT/พ.ร.บ.) tariff table.
 * Access previously hardcoded these values in Form_Quo_motor_manual VBA:
 *   - Sedan 4-door (110) → 645.21
 *   - Van 2-door (320)    → 967.28
 *   - Truck (210)         → 1182.35
 *
 * Extracted from AccessExportForm_Quo_motor_manual.cls lines 6-45.
 * Moved to DB so admins can adjust rates without a code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motor_act_tariffs', function (Blueprint $t): void {
            $t->id();
            $t->string('vehicle_type_code', 8)->unique();  // 110, 320, 210, ...
            $t->string('label_th', 128);
            $t->string('label_en', 128)->nullable();
            $t->decimal('premium', 10, 2);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        DB::table('motor_act_tariffs')->insert([
            ['vehicle_type_code' => '110', 'label_th' => 'รถเก๋ง 4 ประตู', 'label_en' => 'Sedan 4-door', 'premium' => 645.21, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['vehicle_type_code' => '320', 'label_th' => 'รถตู้ 2 ประตู', 'label_en' => 'Van 2-door', 'premium' => 967.28, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['vehicle_type_code' => '210', 'label_th' => 'รถบรรทุก / อื่นๆ', 'label_en' => 'Truck / Other', 'premium' => 1182.35, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('motor_act_tariffs');
    }
};
