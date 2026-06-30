<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frontend: pages/carriers/CarrierManagement.vue: Carrier
        // Access: Insure_company (45 rows)
        Schema::create('carriers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 8); // INC_Code (e.g. AIA, AKN)
            $table->string('name'); // name_th — frontend `name`
            $table->string('name_en')->nullable(); // INC_Name_En
            $table->string('nickname_th')->nullable(); // INC_nickname
            $table->string('insure_type', 16)->nullable(); // life | non-life | mixed (Company_Insure_Type)
            $table->string('sub_type', 64)->nullable();    // Sub_Insurer_type
            $table->text('comp_insure_code')->nullable(); // Comp_insure_code (multi-line legacy)
            $table->string('oic_insure_com_code', 16)->nullable(); // OIC_InsureCom_Code
            $table->string('oic_license', 32)->nullable(); // our agency-side license
            $table->string('tax_id', 20)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable(); // Address_for_WH
            $table->string('bank_account_1', 64)->nullable();
            $table->string('logo_url')->nullable();
            $table->string('since', 8)->nullable(); // BE year (frontend `since`)
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
        });

        // Frontend: stores/carrierContacts.ts: CarrierContactGroup
        Schema::create('carrier_contact_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $table->string('name');
            $table->json('emails'); // ["a@x", "b@y"]
            $table->string('department', 32); // new_business|underwriting|policy_issue|claims|other
            $table->json('insurance_types'); // string[] (15-value union)
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'carrier_id', 'department']);
        });

        // Frontend: pages/products/ProductManagement.vue: Product
        // Access: Main_Product (894 rows)
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->restrictOnDelete();
            $table->string('code', 16); // Product_Code (PDAET0001)
            $table->string('name');     // Product_Name
            $table->string('name_en')->nullable();
            $table->string('type', 32)->nullable(); // ProductType (life/health/motor/...)
            $table->string('category')->nullable();     // Categories
            $table->string('sub_category')->nullable(); // Sub_Categories
            $table->string('sub_category_2', 64)->nullable(); // Sub_Categories2 (Motor/Non-Motor)
            $table->string('main_rider', 32)->nullable(); // Main | rider type
            $table->text('summary')->nullable();
            $table->decimal('coverage', 15, 2)->default(0);
            $table->unsignedSmallInteger('duration_years')->default(1);
            $table->unsignedSmallInteger('pay_years')->default(1);
            $table->string('premium_mode', 16)->default('annual'); // monthly|quarterly|semiannual|annual|single
            $table->decimal('min_premium', 15, 2)->default(0);
            $table->decimal('max_premium', 15, 2)->default(0);
            $table->unsignedSmallInteger('min_age')->default(0);
            $table->unsignedSmallInteger('max_age')->default(99);
            $table->decimal('min_sum_assure', 15, 2)->nullable();
            $table->decimal('max_sum_assure', 15, 2)->nullable();
            $table->decimal('min_rar', 8, 4)->nullable();
            $table->decimal('max_rar', 8, 4)->nullable();
            $table->string('gender', 16)->default('all'); // all|male|female
            $table->boolean('require_medical')->default(false);
            $table->boolean('smoker_accepted')->default(true);
            $table->boolean('preexisting_excluded')->default(false);
            $table->json('occupation_classes')->nullable(); // ['class1','class2',...]
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'carrier_id']);
        });

        // Access: Main_Product per-year commission % per channel (com/ag/in × 1..11)
        // Versioned via commission_code + valid_start/valid_end.
        Schema::create('product_commission_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('commission_code', 16)->nullable()->index(); // Access Commission_Code
            $table->date('valid_start')->nullable();
            $table->date('valid_end')->nullable();
            // Per-year rates for 3 channels.
            for ($yr = 1; $yr <= 11; $yr++) {
                $col = $yr === 11 ? '11up' : (string) $yr;
                // Widened to 15,4 so legacy bad rows (some Access cells carry dates/counts
                // in commission columns) import without an overflow. Application code
                // should still treat realistic rates as 0..1 or 0..100.
                $table->decimal("com_rate_yr_{$col}", 15, 4)->nullable(); // carrier-side (Access ComCommission_*)
                $table->decimal("ag_rate_yr_{$col}", 15, 4)->nullable();  // agent (Access AgCommission_*)
                $table->decimal("in_rate_yr_{$col}", 15, 4)->nullable();  // influencer (Access InCommission_*)
            }
            $table->timestamps();
            $table->index(['product_id', 'valid_start', 'valid_end']);
        });

        // Frontend: pages/contracts/ContractManagement.vue: Contract
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->restrictOnDelete();
            $table->string('contract_no', 64);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'contract_no']);
        });

        // Frontend ScheduleRow { productId, firstYearRate, renewalRate }
        Schema::create('contract_schedule_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('first_year_rate', 8, 4)->default(0);
            $table->decimal('renewal_rate', 8, 4)->default(0);
            $table->timestamps();
            $table->unique(['contract_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_schedule_rows');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('product_commission_rates');
        Schema::dropIfExists('products');
        Schema::dropIfExists('carrier_contact_groups');
        Schema::dropIfExists('carriers');
    }
};
