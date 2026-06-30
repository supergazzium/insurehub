<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frontend: stores/policies.ts: Policy
        // Access: Application (515 rows, 140 cols)
        Schema::create('policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Three-stage ID columns (frontend: only one filled per lifecycle stage).
            $table->string('quote_no', 32)->nullable();
            $table->string('application_no', 32)->nullable();     // Access Application_code (PK in legacy)
            $table->string('policy_no', 64)->nullable();          // Access Policy_Number
            $table->string('notion_no', 32)->nullable();          // Access Notion_No

            // Core FKs.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->restrictOnDelete();
            $table->foreignId('writing_agent_id')->constrained('agents')->restrictOnDelete();
            $table->foreignId('ref_app_to_id')->nullable()->constrained('policies')->nullOnDelete(); // Access Ref_app_to

            // Lifecycle dates.
            $table->date('quote_date')->nullable();
            $table->date('app_date')->nullable();
            $table->date('effective_date')->nullable();    // Coverage_start
            $table->date('expiry_date')->nullable();       // Coverage_End
            $table->date('issue_date')->nullable();
            $table->date('next_premium_due')->nullable();
            $table->date('cancel_date')->nullable();
            $table->date('lapse_date')->nullable();
            $table->date('period_paid_end')->nullable();
            $table->date('policy_end')->nullable();

            // Year tracking.
            $table->unsignedSmallInteger('policy_year')->default(1);
            $table->unsignedSmallInteger('act_year')->default(1);
            $table->string('new_or_renew', 8)->default('new'); // new | renew

            // Coverage + premium.
            $table->decimal('coverage', 15, 2)->default(0);
            $table->decimal('annual_premium', 15, 2)->default(0);
            $table->decimal('main_premium', 15, 2)->nullable();
            $table->decimal('duty_stamp', 15, 2)->nullable();
            $table->decimal('vat', 15, 2)->nullable();
            $table->decimal('total_premium_paid', 15, 2)->nullable();
            $table->decimal('net_customer_paid', 15, 2)->nullable();
            $table->string('premium_mode', 16)->default('annual'); // monthly|quarterly|semiannual|annual|single
            $table->string('type_of_paid', 64)->nullable();
            $table->text('type_of_paid_note')->nullable();
            $table->string('finance_company')->nullable();
            $table->decimal('first_due_inst', 15, 2)->nullable();
            $table->date('first_due_inst_date')->nullable();      // parsed from legacy varchar
            $table->decimal('next_due_inst', 15, 2)->nullable();
            $table->string('installment_term', 32)->nullable();
            $table->date('last_due_inst_date')->nullable();

            // WHT.
            $table->string('wht_status', 32)->nullable();
            $table->decimal('wht_amt', 15, 2)->nullable();

            // Subsidies + fees.
            $table->decimal('subsidise_from_agent', 15, 2)->nullable();
            $table->decimal('front_end_fee', 15, 2)->nullable();
            $table->decimal('discount_amount', 15, 2)->nullable();
            $table->decimal('subsidise_to_finance', 15, 2)->nullable();
            $table->decimal('credit_card_fee', 15, 2)->nullable();

            // Status (frontend PolicyStatus union).
            $table->string('status', 32)->default('quote');
            $table->foreignId('legacy_policy_status_id')->nullable()->constrained('policy_statuses')->nullOnDelete();
            $table->text('status_note')->nullable();
            $table->boolean('freelook_active')->default(false);

            // Motor block (nullable: only for motor policies).
            $table->string('motor_type_driver', 64)->nullable();
            $table->string('motor_type_vehicle', 64)->nullable();
            $table->string('motor_vehicle_brand')->nullable();
            $table->string('motor_vehicle_model')->nullable();
            $table->string('motor_license_no', 32)->nullable();
            $table->string('motor_engine_no', 64)->nullable();
            $table->string('motor_chassis_no', 64)->nullable();
            $table->string('motor_register_year', 8)->nullable();
            $table->unsignedSmallInteger('motor_no_passenger')->nullable();
            $table->text('motor_notes')->nullable();

            // Property block (fire/non-motor).
            $table->string('property_insured_name')->nullable();    // Insured_CompName
            $table->string('property_insured_address')->nullable();
            $table->decimal('property_building_cov', 15, 2)->nullable();
            $table->decimal('property_furniture_cov', 15, 2)->nullable();
            $table->decimal('property_stock_cov', 15, 2)->nullable();
            $table->decimal('property_other_cov', 15, 2)->nullable();
            $table->text('property_other_detail')->nullable();
            $table->text('property_notes')->nullable();
            $table->string('property_phone', 32)->nullable();

            // Cancellation / refund.
            $table->string('cancel_status', 32)->nullable();
            $table->decimal('refund_premium', 15, 2)->nullable();
            $table->decimal('refund_vat', 15, 2)->nullable();
            $table->decimal('refund_total_premium', 15, 2)->nullable();
            $table->decimal('refund_discount', 15, 2)->nullable();
            $table->decimal('net_refund_amount', 15, 2)->nullable();
            $table->decimal('refund_rebate_amt', 15, 2)->nullable();
            $table->decimal('refund_rebate_ov', 15, 2)->nullable();

            // Mailing.
            $table->string('mailing_add_by_policy')->nullable();
            $table->date('mailing_date')->nullable();
            $table->text('mailing_note')->nullable();

            // Commission settlement scratchpad (kept on Access; ledger lives in commission_transactions).
            $table->string('rebate_status', 32)->nullable();
            $table->date('rebate_earn_date')->nullable();
            $table->string('ov_status', 32)->nullable();
            $table->date('rebate_ov_date')->nullable();
            $table->decimal('cal_rebate_amt', 15, 2)->nullable();
            $table->decimal('cal_rebate_ov', 15, 2)->nullable();
            $table->decimal('act_rebate_amt', 15, 2)->nullable();
            $table->decimal('act_rebate_ov', 15, 2)->nullable();
            $table->string('validate_rebate_amt', 16)->nullable();
            $table->string('validate_rebate_ov', 16)->nullable();
            $table->string('rebate_status_ag', 32)->nullable();
            $table->date('rebate_rec_date_ag')->nullable();
            $table->decimal('cal_rebate_amt_ag', 15, 2)->nullable();
            $table->decimal('act_rebate_amt_ag', 15, 2)->nullable();
            $table->string('check_ag_rebate', 16)->nullable();

            // Payment status.
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('payment_inscomp_status_id')->nullable()->constrained('payment_inscomp_statuses')->nullOnDelete();
            $table->foreignId('payment_inscomp_to_id')->nullable()->constrained('payment_inscomp_tos')->nullOnDelete();
            $table->decimal('payment_amount', 15, 2)->nullable();
            $table->date('payment_date')->nullable();
            $table->unsignedInteger('count_slip')->nullable();
            $table->string('validate_payment_amount', 16)->nullable();

            // Bookkeeping.
            $table->string('internal_note')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_username')->nullable(); // Access U field
            $table->string('com_rec_check', 16)->nullable();    // Pending | Complete
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'application_no']);
            $table->unique(['tenant_id', 'policy_no']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'writing_agent_id']);
            $table->index(['tenant_id', 'effective_date']);
        });

        // Riders — Access has 5 fixed slots; frontend has open array.
        Schema::create('policy_riders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot')->nullable(); // 1..5 from Access, null for new
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name');
            $table->decimal('premium', 15, 2)->default(0);
            $table->decimal('com_rate_inh', 8, 4)->nullable();
            $table->decimal('com_rate_ag', 8, 4)->nullable();
            $table->decimal('com_amt_inh', 15, 2)->nullable();
            $table->decimal('com_amt_ag', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['policy_id', 'slot']);
        });

        // Beneficiaries — Access has 4 fixed slots; frontend has share %.
        Schema::create('policy_beneficiaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot')->nullable(); // 1..4 from Access
            $table->string('name');
            $table->string('relation', 32)->nullable();
            $table->decimal('share', 6, 2)->default(100.00); // percent
            $table->timestamps();
        });

        // Event-sourced audit trail (frontend PolicyEvent[]).
        Schema::create('policy_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->string('type', 32); // PolicyEventType union
            $table->timestamp('occurred_at')->useCurrent();
            $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['policy_id', 'type']);
            $table->index(['policy_id', 'occurred_at']);
        });

        // Payments (frontend PolicyPayment[]).
        Schema::create('policy_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('method', 32)->default('bankTransfer'); // PaymentMethod union
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['policy_id', 'payment_date']);
        });

        // Documents (frontend PolicyDocument[] + Access App_Doc_Control paths).
        Schema::create('policy_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->string('type', 32); // PolicyDocType union + Access slots (idCard/idKid/passport/carReg/polRE/extPol/slip/oth)
            $table->string('file_name');
            $table->string('file_path')->nullable();           // E_path_* equivalent (local)
            $table->string('original_path')->nullable();        // O_path_* (legacy network share)
            $table->unsignedSmallInteger('slot')->nullable();   // for othN columns 1..6
            $table->timestamp('uploaded_at')->useCurrent();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['policy_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_documents');
        Schema::dropIfExists('policy_payments');
        Schema::dropIfExists('policy_events');
        Schema::dropIfExists('policy_beneficiaries');
        Schema::dropIfExists('policy_riders');
        Schema::dropIfExists('policies');
    }
};
