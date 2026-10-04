<?php

declare(strict_types=1);

namespace Tests\Feature\Policy\Concerns;

use App\Models\Agent;
use App\Models\Carrier;
use App\Models\Customer;
use App\Models\Policy;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

trait BuildsPolicyFixtures
{
    protected Tenant $tenant;

    protected User $admin;

    protected User $staff;

    protected Carrier $carrierA;

    protected Carrier $carrierB;

    protected Product $productA;

    protected Product $productB;

    protected Customer $customer;

    protected Agent $agent;

    private int $seq = 0;

    protected function buildFixtures(): void
    {
        $this->tenant = Tenant::create([
            'slug' => 'test-'.uniqid(), 'name' => 'Test Broker', 'active' => true,
            'payout_require_freelook' => false, 'payout_require_insurer_received' => false, 'payout_require_customer_paid' => false,
        ]);
        $this->admin = $this->makeUser('admin');
        $this->staff = $this->makeUser('staff');
        $this->carrierA = Carrier::create(['tenant_id' => $this->tenant->id, 'code' => 'CA', 'name' => 'Carrier A', 'insure_type' => 'Non-Life', 'active' => true]);
        $this->carrierB = Carrier::create(['tenant_id' => $this->tenant->id, 'code' => 'CB', 'name' => 'Carrier B', 'insure_type' => 'Non-Life', 'active' => true]);
        $this->productA = $this->makeProduct('PA', $this->carrierA);
        $this->productB = $this->makeProduct('PB', $this->carrierB);
        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id, 'customer_code' => 'C001', 'customer_type' => 'individual',
            'first_name' => 'สมชาย', 'last_name' => 'ทดสอบ', 'id_card' => '1100000000001', 'active' => true,
        ]);
        $this->agent = Agent::create([
            'tenant_id' => $this->tenant->id, 'agent_code' => 'AG001', 'agent_type' => 'AG',
            'first_name' => 'วิไล', 'last_name' => 'ขายดี', 'kind' => 'individual', 'vat_type' => '1',
            'level' => 'l1', 'active' => true, 'approval_status' => 'approved',
        ]);
    }

    protected function makeUser(string $role): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'),
            'role' => $role,
            'locale' => 'th',
            'active' => true,
        ]);
    }

    protected function makeProduct(string $code, Carrier $carrier, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => $this->tenant->id, 'carrier_id' => $carrier->id, 'code' => $code,
            'name' => "Product {$code}", 'type' => 'motor', 'coverage' => 500000, 'duration_years' => 1,
            'pay_years' => 1, 'premium_mode' => 'annual', 'allowed_premium_modes' => ['annual', 'monthly'],
            'min_premium' => 0, 'max_premium' => 1000000, 'min_age' => 0, 'max_age' => 99, 'gender' => 'all', 'active' => true,
        ], $attrs));
    }

    protected function makePolicy(array $attrs = []): Policy
    {
        $n = ++$this->seq;

        return Policy::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'product_id' => $this->productA->id,
            'carrier_id' => $this->carrierA->id,
            'writing_agent_id' => $this->agent->id,
            'application_no' => "APP{$n}",
            'policy_no' => "POL{$n}",
            'effective_date' => now()->subYear()->addDays(20)->toDateString(),
            'expiry_date' => now()->addDays(20)->toDateString(),
            'policy_year' => 1,
            'customer_year' => 1,
            'act_year' => 1,
            'new_or_renew' => 'new',
            'coverage' => 500000,
            'annual_premium' => 12000,
            'main_premium' => 12000,
            'net_premium' => 11152.42,
            'duty_stamp' => 45,
            'vat' => 783.86,
            'total_premium_paid' => 12000,
            'discount_amount' => 1500,
            'premium_mode' => 'annual',
            'status' => 'active',
            'motor_license_no' => "กข {$n}00",
        ], $attrs));
    }

    protected function actingAsAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    protected function actingAsStaff(): void
    {
        Sanctum::actingAs($this->staff);
    }

    protected function draftPayload(Policy $source, array $extra = []): array
    {
        return array_merge([
            'customerId' => (string) $source->customer_id,
            'writingAgentId' => (string) $source->writing_agent_id,
            'productId' => (string) $source->product_id,
            'carrierId' => (string) $source->carrier_id,
            'refAppToId' => (string) $source->id,
            'newOrRenew' => 'renew',
            'effectiveDate' => $source->expiry_date?->toDateString(),
            'expiryDate' => $source->expiry_date?->copy()->addYear()->toDateString(),
            'coverage' => 500000,
            'netPremium' => 11152.42,
            'mainPremium' => 12000,
            'premiumMode' => 'annual',
        ], $extra);
    }
}
