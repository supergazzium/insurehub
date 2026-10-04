<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Carrier;
use App\Models\Customer;
use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\PolicyPayment;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RenewalScenarioSeeder extends Seeder
{
    private int $tenantId;

    private Customer $cust;

    private Agent $agent;

    private Carrier $carrierA;

    private Product $motorA;

    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            $this->command?->error('No tenant — run TenantSeeder.');

            return;
        }
        $this->tenantId = (int) $tenant->id;

        DB::transaction(function (): void {
            $this->seedParties();
            $this->seedProducts();
            $this->seedPolicies();
        });

        $this->command?->info('RenewalScenarioSeeder complete. All rows prefixed TSTRN.');
    }

    private function seedParties(): void
    {
        $this->cust = Customer::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'customer_code' => 'TSTRN-C01'],
            [
                'customer_type' => 'individual', 'first_name' => 'สมศักดิ์', 'last_name' => 'ต่ออายุดี',
                'id_card' => '1100000000017', 'phone' => '0810000017', 'active' => true,
            ],
        );
        $this->agent = Agent::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'agent_code' => 'TSTRN-A01'],
            [
                'agent_type' => 'AG', 'first_name' => 'วิไล', 'last_name' => 'ขายต่อเนื่อง', 'kind' => 'individual',
                'vat_type' => '1', 'level' => 'l1', 'active' => true, 'approval_status' => 'approved',
                'joined_at' => now()->subYears(3)->toDateString(),
            ],
        );
    }

    private function seedProducts(): void
    {
        $this->carrierA = Carrier::query()->where('tenant_id', $this->tenantId)->where('insure_type', 'Non-Life')->where('active', true)->orderBy('id')->first()
            ?? Carrier::updateOrCreate(['tenant_id' => $this->tenantId, 'code' => 'TSTRNA'],
                ['name' => 'TSTRN บริษัทประกันภัย ก', 'insure_type' => 'Non-Life', 'active' => true]);

        $type = ProductType::query()->where('tenant_id', $this->tenantId)->where('code', 'MOTOR_CLASS1_GARAGE')->first();
        $this->motorA = Product::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'code' => 'TSTRN-P-MOTOR'],
            [
                'carrier_id' => $this->carrierA->id, 'name' => 'TSTRN ประกันรถยนต์ชั้น 1 (ซ่อมอู่)', 'type' => 'motor',
                'category' => 'การประกันรถโดยความสมัครใจ', 'sub_category' => 'ป1.', 'sub_category_2' => 'Motor',
                'product_type_id' => $type?->id, 'commission_tier_id' => $type?->tier_id,
                'coverage' => 500000, 'duration_years' => 1, 'pay_years' => 1, 'premium_mode' => 'annual',
                'allowed_premium_modes' => ['annual', 'monthly'],
                'min_premium' => 0, 'max_premium' => 1000000, 'min_age' => 0, 'max_age' => 99, 'gender' => 'all', 'active' => true,
            ],
        );
    }

    private function seedPolicies(): void
    {
        $s1 = $this->policy('TSTRN-S1', 'active', now()->subMonths(2)->subDays(5), [
            'premium_mode' => 'monthly', 'installment_term' => '12', 'installment_mode' => 'A',
            'first_due_inst_date' => now()->subMonths(2)->subDays(5)->toDateString(),
            'motor_license_no' => 'กข 1701 กรุงเทพมหานคร',
        ]);
        $this->pay($s1, 1000, 'TSTRN-S1-PAY1', now()->subMonths(2));
        $this->pay($s1, 1000, 'TSTRN-S1-PAY2', now()->subMonth());

        $s2p = $this->policy('TSTRN-S2P', 'expired', now()->subYears(2)->subDays(10), [
            'policy_year' => 1, 'customer_year' => 2, 'motor_license_no' => 'ฆค 2002 นนทบุรี',
        ]);
        $this->policy('TSTRN-S2', 'expired', now()->subYear()->subDays(10), [
            'policy_year' => 2, 'customer_year' => 3, 'new_or_renew' => 'renew', 'ref_app_to_id' => $s2p->id,
            'motor_license_no' => 'ฆค 2002 นนทบุรี',
        ]);

        $s3 = $this->policy('TSTRN-S3', 'active', now()->subMonths(4), ['motor_license_no' => 'งจ 3003 ชลบุรี']);
        $this->event($s3, 'endorsement.coverage_change', now()->subMonths(3), ['note' => 'TSTRN เพิ่มผู้ขับขี่', 'reason' => 'เพิ่มผู้ขับขี่']);
        $this->event($s3, 'endorsement.other', now()->subMonth(), ['note' => 'TSTRN แก้ที่อยู่', 'reason' => 'แก้ที่อยู่ผู้เอาประกัน']);
        $this->resequenceEndorsements($s3);

        $s4 = $this->policy('TSTRN-S4', 'active', now()->subYear()->addDays(20), ['motor_license_no' => 'ฉช 4004 เชียงใหม่']);
        $this->policy('TSTRN-S4R', 'draft', now()->addDays(20), [
            'policy_no' => null, 'new_or_renew' => 'renew', 'ref_app_to_id' => $s4->id, 'policy_year' => 2, 'customer_year' => 2,
            'motor_license_no' => 'ฉช 4004 เชียงใหม่',
        ]);
        $this->event($s4, 'renewalStarted', now()->subDays(2), ['source' => 'renewal-draft', 'seed' => 'TSTRN']);

        $s5 = $this->policy('TSTRN-S5', 'active', now()->subYear()->addDays(25), ['motor_license_no' => 'ซฌ 5005 ระยอง']);
        $this->policy('TSTRN-S5R', 'cancelled', now()->addDays(25), [
            'policy_no' => null, 'new_or_renew' => 'renew', 'ref_app_to_id' => $s5->id, 'policy_year' => 2, 'customer_year' => 2,
            'motor_license_no' => 'ซฌ 5005 ระยอง',
        ]);
        $this->event($s5, 'renewalStarted', now()->subDays(5), ['source' => 'renewal-draft', 'seed' => 'TSTRN']);
    }

    private function policy(string $slug, string $status, \DateTimeInterface $effective, array $extra = []): Policy
    {
        $eff = \Illuminate\Support\Carbon::instance($effective)->startOfDay();
        $attrs = array_merge([
            'customer_id' => $this->cust->id,
            'product_id' => $this->motorA->id,
            'carrier_id' => $this->carrierA->id,
            'writing_agent_id' => $this->agent->id,
            'application_no' => str_replace('-', '', $slug).'APP',
            'policy_no' => str_replace('-', '', $slug),
            'app_date' => $eff->copy()->subDays(7)->toDateString(),
            'effective_date' => $eff->toDateString(),
            'expiry_date' => $eff->copy()->addYear()->toDateString(),
            'policy_year' => 1, 'customer_year' => 1, 'act_year' => 1, 'new_or_renew' => 'new',
            'coverage' => 500000, 'discount_amount' => 1500,
            'annual_premium' => 12000, 'main_premium' => 12000, 'net_premium' => 11152.42,
            'duty_stamp' => 45, 'vat' => 783.86, 'total_premium_paid' => 12000,
            'premium_mode' => 'annual', 'status' => $status,
            'motor_vehicle_brand' => 'TOYOTA', 'motor_vehicle_model' => 'COROLLA ALTIS', 'motor_register_year' => '2021',
            'comm_carrier_to_hub_rate' => 0.18, 'comm_carrier_to_hub_amount' => 2007.44,
            'comm_hub_to_agent_rate' => 0.10, 'comm_hub_to_agent_amount' => 1115.24,
            'vehicle_on_non_motor' => false,
        ], $extra);

        return Policy::updateOrCreate(['tenant_id' => $this->tenantId, 'application_no' => $attrs['application_no']], $attrs);
    }

    private function event(Policy $policy, string $type, \DateTimeInterface $at, array $payload): void
    {
        PolicyEvent::query()->where('policy_id', $policy->id)->where('type', $type)
            ->where('payload->seed', 'TSTRN')->delete();
        PolicyEvent::create([
            'policy_id' => $policy->id, 'type' => $type, 'occurred_at' => $at,
            'payload' => $payload + ['seed' => 'TSTRN'],
        ]);
    }

    private function resequenceEndorsements(Policy $policy): void
    {
        $events = PolicyEvent::query()->where('policy_id', $policy->id)->where('type', 'like', 'endorsement.%')
            ->orderBy('occurred_at')->orderBy('id')->get();
        PolicyEvent::query()->whereIn('id', $events->pluck('id'))->update(['endorsement_seq' => null]);
        foreach ($events->values() as $i => $e) {
            PolicyEvent::query()->whereKey($e->id)->update(['endorsement_seq' => $i + 1]);
        }
        Policy::query()->whereKey($policy->id)->update(['endorsement_count' => $events->count()]);
    }

    private function pay(Policy $policy, float $amount, string $reference, \DateTimeInterface $date): void
    {
        if (PolicyPayment::query()->where('policy_id', $policy->id)->where('reference', $reference)->exists()) {
            return;
        }
        PolicyPayment::create([
            'policy_id' => $policy->id, 'payment_date' => \Illuminate\Support\Carbon::instance($date)->toDateString(),
            'amount' => $amount, 'method' => 'bankTransfer', 'reference' => $reference,
        ]);
    }
}
