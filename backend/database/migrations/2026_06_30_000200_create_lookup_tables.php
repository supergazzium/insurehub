<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Access: BankName_Para (11 rows)
        Schema::create('banks', function (Blueprint $table): void {
            $table->id();
            $table->string('name_th');
            $table->string('name_en')->nullable();
            $table->string('code', 16)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['name_th']);
        });

        // Access: nation (242 rows)
        Schema::create('nationalities', function (Blueprint $table): void {
            $table->id();
            $table->string('iso2', 2)->nullable()->index();
            $table->string('iso3', 3)->nullable()->index();
            $table->string('nation_name_th');
            $table->string('nation_name_en')->nullable();
            $table->string('country_name_th')->nullable();
            $table->string('country_name_en')->nullable();
            $table->timestamps();
            $table->unique(['nation_name_th']);
        });

        // Access: religion (11 rows)
        Schema::create('religions', function (Blueprint $table): void {
            $table->id();
            $table->string('name_th')->unique();
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        // Access: Occupation (48 rows)
        Schema::create('occupations', function (Blueprint $table): void {
            $table->id();
            $table->string('access_code', 16)->nullable()->index();
            $table->string('type', 32)->nullable(); // Individual | Individual / Corporate
            $table->string('name_th')->index();
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        // Access: prefix_para (244 rows; composite of Insured_Type_ID + Title_Code)
        Schema::create('name_prefixes', function (Blueprint $table): void {
            $table->id();
            $table->string('insured_type', 16); // Corporate | Individual
            $table->unsignedInteger('insured_type_id');
            $table->unsignedInteger('title_code');
            $table->string('description_th');
            $table->string('description_en')->nullable();
            $table->timestamps();
            $table->unique(['insured_type_id', 'title_code']);
        });

        // Access: Location (7,460 rows — province/amphoe/district/zip)
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('location_code', 16)->nullable()->index();
            $table->string('province')->index();
            $table->string('amphur')->index();
            $table->string('district')->index();
            $table->string('zip', 16)->index();
            $table->timestamps();
        });

        // Access: Policies_status_para (11 rows)
        Schema::create('policy_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_th');
            $table->string('group_name_th')->nullable();
            $table->string('code', 64)->nullable()->unique(); // mapped to frontend PolicyStatus
            $table->timestamps();
        });

        // Access: paidstatus_para (16 rows)
        Schema::create('paid_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('group_name_th');
            $table->string('name_th');
            $table->string('finance_comp', 32)->nullable();
            $table->timestamps();
        });

        // Access: Payment_method_para (3 rows)
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name_th')->unique();
            $table->string('code', 32)->nullable(); // bankTransfer/creditCard/cash/cheque/directDebit
            $table->timestamps();
        });

        // Access: Payment_InsComp_Status_para (3 rows)
        Schema::create('payment_inscomp_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_th')->unique();
            $table->timestamps();
        });

        // Access: Payment_InsComp_to_para (2 rows)
        Schema::create('payment_inscomp_tos', function (Blueprint $table): void {
            $table->id();
            $table->string('name_th')->unique();
            $table->timestamps();
        });

        // Access: MotorMarketGrouppara (10 rows)
        Schema::create('motor_market_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('group_code', 4)->unique();
            $table->string('desc_en');
            $table->string('desc_th');
            $table->string('redbook_type', 8)->nullable();
            $table->timestamps();
        });

        // Access: Motor_para (32,664 rows — vehicle Redbook catalogue)
        Schema::create('motor_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('serial', 32)->nullable()->index();
            $table->string('brand_code', 16)->nullable();
            $table->string('model_code', 16)->nullable();
            $table->string('submodel_code', 16)->nullable();
            $table->string('vehicle_brand')->nullable()->index();
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_submodel')->nullable();
            $table->string('vh_year_beg', 4)->nullable();
            $table->string('vh_year_end', 4)->nullable();
            $table->string('cover_01_flag', 4)->nullable();
            $table->string('cover_plus_flag', 4)->nullable();
            $table->foreignId('motor_market_group_id')->nullable()->constrained('motor_market_groups');
            $table->string('sedan_type', 16)->nullable();
            $table->string('sell_stat', 8)->nullable();
            $table->string('submodel_year', 8)->nullable();
            $table->date('effect_date')->nullable();
            $table->string('scale', 16)->nullable();
            $table->string('weight', 16)->nullable();
            $table->string('cc', 16)->nullable();
            $table->string('seat_no', 8)->nullable();
            $table->string('chassis_no_format')->nullable();
            $table->string('engine_no_format')->nullable();
            $table->decimal('standard_sumins', 15, 2)->nullable();
            $table->decimal('standard_theft', 15, 2)->nullable();
            $table->decimal('eighty_sumins', 15, 2)->nullable(); // Access: 80_SUMINS
            $table->string('car_group', 16)->nullable();
            $table->decimal('old_std_sumins', 15, 2)->nullable();
            $table->decimal('old_std_theft', 15, 2)->nullable();
            $table->string('model_status', 16)->nullable();
            $table->string('rbm_serial', 32)->nullable();
            $table->string('redbook_code', 32)->nullable();
            $table->timestamps();
            $table->index(['vehicle_brand', 'vehicle_model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motor_vehicles');
        Schema::dropIfExists('motor_market_groups');
        Schema::dropIfExists('payment_inscomp_tos');
        Schema::dropIfExists('payment_inscomp_statuses');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('paid_statuses');
        Schema::dropIfExists('policy_statuses');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('name_prefixes');
        Schema::dropIfExists('occupations');
        Schema::dropIfExists('religions');
        Schema::dropIfExists('nationalities');
        Schema::dropIfExists('banks');
    }
};
