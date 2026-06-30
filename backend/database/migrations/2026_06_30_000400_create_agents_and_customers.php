<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frontend: stores/agents.ts: Agent
        // Access: Agent_para (392 rows)
        Schema::create('agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('agent_code', 16); // Insure_Agent_Code (AG200000)
            $table->string('agent_type', 4)->default('AG'); // AG | IN
            $table->string('first_name')->nullable();      // Agent_Name_Thai
            $table->string('last_name')->nullable();       // Agent_Surname_Thai
            $table->string('first_name_en')->nullable();   // Agent_Name_Eng
            $table->string('last_name_en')->nullable();    // Agent_Surname_Eng
            $table->string('nickname')->nullable();
            $table->string('gender', 8)->nullable();       // male|female|other|''
            $table->string('email')->nullable();
            $table->string('email2')->nullable();
            $table->string('phone', 32)->nullable();       // Mobile_phone
            $table->string('tel_phone', 32)->nullable();
            $table->string('line_id', 64)->nullable();
            $table->string('facebook_name')->nullable();
            $table->string('id_card', 20)->nullable();     // National ID (Agent_Tax_ID per Access)
            $table->date('birth_date')->nullable();

            // Single address (frontend Agent).
            $table->string('address')->nullable();          // Address_no + Building + Moo + Soi + Road compacted
            $table->string('building_floor')->nullable();
            $table->string('moo', 8)->nullable();
            $table->string('moo_ban')->nullable();
            $table->string('soi')->nullable();
            $table->string('road')->nullable();
            $table->string('sub_district')->nullable();     // Kwang (tambon)
            $table->string('district')->nullable();         // Khet (amphoe)
            $table->string('province')->nullable();
            $table->string('postcode', 16)->nullable();

            // Kind: individual | corporate.
            $table->string('kind', 16)->default('individual');
            $table->string('juristic_name')->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->string('branch')->nullable();
            $table->date('company_register_date')->nullable();

            // VAT type — frontend: ''|none|vat7|wht1|wht3|wht5 ; Access VAT_TYPE varchar(1)
            $table->string('vat_type', 16)->default('');

            // Bank.
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('bank_name_text')->nullable(); // free-text fallback
            $table->string('bank_account_no', 64)->nullable();
            $table->string('bank_account_name')->nullable();

            // Licenses (frontend split: life + non-life; legacy single also kept).
            $table->string('license_number', 32)->nullable();
            $table->string('license_issuer')->nullable();
            $table->date('license_expiry')->nullable();
            $table->string('license_life_no', 32)->nullable();
            $table->date('license_life_expiry')->nullable();
            $table->string('license_non_life_no', 32)->nullable();
            $table->date('license_non_life_expiry')->nullable();

            // MLM tree — frontend parentAgentId; Access MLM_Upline (by code).
            $table->foreignId('parent_agent_id')->nullable()->index();
            $table->string('mlm_upline_2', 16)->nullable(); // legacy second-upline column
            $table->string('level', 4)->default('l5');      // l1..l5 (frontend Agent.level)
            $table->string('team', 32)->nullable();
            $table->string('team_lv1', 32)->nullable();
            $table->string('team_lv2', 32)->nullable();
            $table->string('team_no', 16)->nullable();
            $table->decimal('commission_pct', 8, 4)->default(0); // overall % (frontend)

            // Onboarding flags.
            $table->date('joined_at')->nullable();           // Date_Apply
            $table->string('head_start_date_raw', 32)->nullable(); // legacy varchar dd/m/yyyy
            $table->string('grace_period_end_raw', 32)->nullable();
            $table->string('source', 32)->nullable();        // TEST | In-House
            $table->string('signup_type', 32)->nullable();   // personal | corporate

            // Onboarding doc completion checklist.
            $table->boolean('doc_status')->default(false);
            $table->text('doc_description')->nullable();

            // Per-agent dashboard links (income / jobs / expiry reminders).
            $table->string('income_url')->nullable();
            $table->string('job_url')->nullable();
            $table->string('expire_reminder_url')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'agent_code']);
            $table->index(['tenant_id', 'agent_type']);
        });

        // Self-FK constraint added after table create.
        Schema::table('agents', function (Blueprint $table): void {
            $table->foreign('parent_agent_id')->references('id')->on('agents')->nullOnDelete();
        });

        // Late FK from users.agent_id (created before agents table existed).
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });

        // Frontend: stores/agents.ts: RecruitmentLink
        Schema::create('recruitment_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('generated_at')->useCurrent();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('signups')->default(0);
            $table->unsignedInteger('pending_signups')->default(0);
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });

        // Frontend: stores/customers.ts: Customer
        // Access: Client (357 rows)
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('customer_code', 16); // Client_Code (C9901093)
            $table->string('customer_type', 16)->default('individual'); // individual|corporate (Type_Cust 1/2)
            $table->string('title_th')->nullable();
            $table->string('title_en')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('first_name_en')->nullable();
            $table->string('last_name_en')->nullable();
            $table->string('nickname')->nullable();
            $table->string('juristic_name')->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->string('id_card', 20)->nullable();          // National_ID
            $table->date('national_id_expiry')->nullable();
            $table->string('passport', 32)->nullable();
            // Nationality / religion: keep raw string + nullable FK (Access stores by-name).
            $table->string('nationality')->nullable();
            $table->foreignId('nationality_id')->nullable()->constrained('nationalities')->nullOnDelete();
            $table->string('religion')->nullable();
            $table->foreignId('religion_id')->nullable()->constrained('religions')->nullOnDelete();
            $table->string('race')->nullable();
            $table->string('gender', 8)->nullable();
            $table->string('marital_status', 16)->nullable();   // single|married|divorced|widowed
            $table->date('birth_date')->nullable();
            $table->string('occupation')->nullable();           // free text mirror
            $table->foreignId('occupation_id')->nullable()->constrained('occupations')->nullOnDelete();
            $table->string('position')->nullable();
            $table->string('employer_name')->nullable();
            $table->decimal('monthly_income', 15, 2)->default(0); // frontend monthlyIncome
            $table->decimal('annual_income_raw', 15, 2)->nullable(); // Access Cus_Annually_Income (for audit)
            $table->string('email')->nullable();
            $table->string('email2')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('tel_phone', 32)->nullable();
            $table->string('line_id', 64)->nullable();
            $table->string('facebook_name')->nullable();

            // Permanent / registered address.
            $table->string('address')->nullable();
            $table->string('building_floor')->nullable();
            $table->string('moo', 8)->nullable();
            $table->string('moo_ban')->nullable();
            $table->string('soi')->nullable();
            $table->string('road')->nullable();
            $table->string('sub_district')->nullable(); // tambon / kwang
            $table->string('amphoe')->nullable();       // district / khet
            $table->string('province')->nullable();
            $table->string('postcode', 16)->nullable();

            // Mailing address.
            $table->boolean('mailing_same_as_registered')->default(true);
            $table->string('mailing_address')->nullable();
            $table->string('mailing_sub_district')->nullable();
            $table->string('mailing_district')->nullable();
            $table->string('mailing_province')->nullable();
            $table->string('mailing_postcode', 16)->nullable();

            // Corporate contact.
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_position')->nullable();
            $table->string('contact_person_receive')->nullable();
            $table->string('contact_person_receive_address')->nullable();

            // Assignment.
            $table->foreignId('created_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_contact')->nullable();
            $table->text('notes')->nullable();

            // Denormalized counts (kept warm by triggers/observers later).
            $table->unsignedInteger('active_policy_count')->default(0);
            $table->unsignedInteger('total_policy_count')->default(0);

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'customer_code']);
            $table->index(['tenant_id', 'assigned_agent_id']);
        });

        // Frontend: KycDoc { id, type, fileName, uploadedAt, uploadedByAgentId, verified }
        Schema::create('customer_kyc_docs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type', 32); // idCard|houseReg|bankBook|income|medical|photo|signature|other
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->foreignId('uploaded_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->boolean('verified')->default(false);
            $table->timestamps();
            $table->index(['customer_id', 'type']);
        });

        // Frontend: AssignmentHistoryEntry { id, fromAgentId, toAgentId, reason, byUserId, at }
        Schema::create('customer_assignment_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('from_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('to_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        // Frontend: CustomerReferralLink
        Schema::create('customer_referral_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('campaign')->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('generated_at')->useCurrent();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('policies')->default(0);
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_referral_links');
        Schema::dropIfExists('customer_assignment_history');
        Schema::dropIfExists('customer_kyc_docs');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('recruitment_links');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['tenant_id']);
        });
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropForeign(['parent_agent_id']);
        });
        Schema::dropIfExists('agents');
    }
};
