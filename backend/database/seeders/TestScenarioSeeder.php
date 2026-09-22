<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Carrier;
use App\Models\Customer;
use App\Models\Policy;
use App\Models\PolicyPayment;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Rank;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TestScenarioSeeder — a labelled, idempotent cohort covering every case in
 * the commission / follow-up / collections / receipt features. Everything is
 * prefixed `TST-` (or `TST` for policy_no) so it is easy to spot in the UI and
 * clean up. Existing production data is untouched.
 *
 * Cleanup:  php artisan db:seed --class=TestScenarioSeeder  (re-runs, idempotent)
 *           or delete rows where code/reference LIKE 'TST%'.
 *
 * Structure:
 *   A. Agent hierarchy (Thai names, MGM tree, teams, a pending-approval agent)
 *   B. Products (motor / non-life / life) on the right carriers
 *   C. Policies — status × premium-mode × commission matrix (fires MGM engine)
 *   D. ติดตามงาน — one policy per follow-up category
 *   E. ติดตามเงิน — cash / installment / split × paid/partial/overdue + reminders
 *   F. Commission payout eligibility
 *   G. Insurer receipt — receivables in every status + a receipt batch
 */
class TestScenarioSeeder extends Seeder
{
    private int $tenantId;
    private array $ranksByLevel = [];
    private array $typesByCode = [];
    private ?Carrier $lifeCarrier = null;
    private ?Carrier $nonLifeCarrier = null;
    private array $agents = [];
    private ?Customer $cust = null;
    private array $products = [];

    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) { $this->command?->error('No tenant — run TenantSeeder.'); return; }
        $this->tenantId = (int) $tenant->id;

        $this->ranksByLevel = Rank::all()->keyBy('level')->all();
        $this->typesByCode = ProductType::query()->where('tenant_id', $this->tenantId)->get()->keyBy('code')->all();

        // Carriers: dispatch is on insure_type. Pick a real life + non-life carrier,
        // or synthesise them so the cohort is self-contained.
        $this->lifeCarrier = Carrier::query()->where('tenant_id', $this->tenantId)->where('insure_type', 'life')->first()
            ?? Carrier::updateOrCreate(['tenant_id' => $this->tenantId, 'code' => 'TSTLIFE'],
                ['name' => 'TST บริษัทประกันชีวิตทดสอบ', 'insure_type' => 'life', 'active' => true]);
        $this->nonLifeCarrier = Carrier::query()->where('tenant_id', $this->tenantId)->where('insure_type', 'non-life')->first()
            ?? Carrier::updateOrCreate(['tenant_id' => $this->tenantId, 'code' => 'TSTNL'],
                ['name' => 'TST บริษัทประกันวินาศภัยทดสอบ', 'insure_type' => 'non-life', 'active' => true]);

        DB::transaction(function (): void {
            $this->seedAgents();
            $this->seedCustomer();
            $this->seedProducts();
            $this->seedCommissionMatrixPolicies();
            $this->seedFollowUpCases();
            $this->seedCollectionsCases();
            $this->seedReceiptCases();
        });

        $this->command?->info('TestScenarioSeeder complete. All rows prefixed TST.');
    }

    // ── A. Agent hierarchy (Thai names) ───────────────────────────────────
    private function seedAgents(): void
    {
        // Tree:  หัวหน้า(Lv5) → รอง(Lv3) → ผู้ขาย(Lv1)  ;  and a second branch รอง2(Lv2)
        $head = $this->agent('TST-A01', 'สมชาย', 'ใจดีมั่นคง', 5, null, vatType: '1');
        $mid  = $this->agent('TST-A02', 'สมหญิง', 'รักการขาย', 3, $head, vatType: '2', vatMode: 'exclude', hasVat: true);
        $sell = $this->agent('TST-A03', 'อนุชา', 'พากเพียรยิ่ง', 1, $mid, vatType: '3', vatMode: 'include', hasVat: true);
        $mid2 = $this->agent('TST-A04', 'วิภาดา', 'ตั้งมั่นเสมอ', 2, $head, vatType: '1');
        // Pending-approval agent (tests the registration → approval flow).
        $pending = $this->agent('TST-A05', 'ธนพล', 'รอการอนุมัติ', 1, $head, vatType: '1', approval: 'pending', active: false);

        $this->agents = compact('head', 'mid', 'sell', 'mid2', 'pending');
    }

    private function agent(string $code, string $first, string $last, int $level, ?Agent $parent, string $vatType = '1', ?string $vatMode = null, bool $hasVat = false, string $approval = 'approved', bool $active = true): Agent
    {
        $rank = $this->ranksByLevel[$level] ?? null;

        return Agent::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'agent_code' => $code],
            [
                'agent_type' => 'AG',
                'first_name' => $first,
                'last_name' => $last,
                'kind' => 'individual',
                'vat_type' => $vatType,
                'has_vat' => $hasVat,
                'vat_mode' => $vatMode,
                'level' => 'l' . min($level, 5),
                'rank_id' => $rank?->id,
                'parent_agent_id' => $parent?->id,
                'active' => $active,
                'has_license' => $level >= 7,
                'approval_status' => $approval,
                'joined_at' => now()->subYears(1)->toDateString(),
            ],
        );
    }

    private function seedCustomer(): void
    {
        $this->cust = Customer::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'customer_code' => 'TST-C01'],
            ['customer_type' => 'individual', 'first_name' => 'มานี', 'last_name' => 'ทดสอบดี', 'phone' => '0800000001', 'active' => true],
        );
        Customer::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'customer_code' => 'TST-C02'],
            ['customer_type' => 'corporate', 'juristic_name' => 'TST บริษัท ทดสอบ จำกัด', 'first_name' => 'ฝ่าย', 'last_name' => 'จัดซื้อ', 'phone' => '0800000002', 'active' => true],
        );
    }

    // ── B. Products ───────────────────────────────────────────────────────
    private function seedProducts(): void
    {
        $this->products['motor'] = $this->product('TST-P-MOTOR', $this->nonLifeCarrier, 'MOTOR_CLASS1_GARAGE', 'motor');
        $this->products['nonlife'] = $this->product('TST-P-NONLIFE', $this->nonLifeCarrier, 'FIRE_HOUSE_BASIC', 'non_life');
        $this->products['life'] = $this->product('TST-P-LIFE', $this->lifeCarrier, 'WHOLE_LIFE_STANDARD', 'life');
    }

    private function product(string $code, Carrier $carrier, string $typeCode, string $loweType): Product
    {
        $type = $this->typesByCode[$typeCode] ?? null;

        return Product::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'code' => $code],
            [
                'carrier_id' => $carrier->id,
                'name' => "TST product {$code}",
                'type' => $loweType, // lowercase life/motor/non_life for FollowUp freelook
                'product_type_id' => $type?->id,
                'commission_tier_id' => $type?->tier_id,
                'coverage' => 100000, 'duration_years' => 1, 'pay_years' => 1,
                'premium_mode' => 'annual', 'min_premium' => 0, 'max_premium' => 1000000,
                'min_age' => 0, 'max_age' => 99, 'gender' => 'all', 'active' => true,
            ],
        );
    }

    // ── C. Commission-matrix policies (fire MGM engine via payment) ───────
    private function seedCommissionMatrixPolicies(): void
    {
        // Seller writes: engine produces DIRECT (seller) + REFERRAL (mid) + DIFFERENTIAL up the chain.
        $p1 = $this->policy('TST01-mgm-motor', $this->products['motor'], $this->nonLifeCarrier, $this->agents['sell'], 'active', 12000, comm: true, hubRate: 0.10);
        $this->pay($p1, 12000, 'TST01-PAY');

        $p2 = $this->policy('TST02-mgm-life', $this->products['life'], $this->lifeCarrier, $this->agents['sell'], 'active', 24000, life: true, comm: true, hubRate: 0.15);
        $this->pay($p2, 24000, 'TST02-PAY');
    }

    // ── D. ติดตามงาน (follow-up) — one policy per category ────────────────
    private function seedFollowUpCases(): void
    {
        // 1) approval — status=submitted
        $this->policy('TST-FU1-approval', $this->products['nonlife'], $this->nonLifeCarrier, $this->agents['sell'], 'submitted', 8000, policyNo: '');
        // 2) no_policy_no — active/issued, empty policy_no
        $this->policy('TST-FU2-nopolicyno', $this->products['nonlife'], $this->nonLifeCarrier, $this->agents['sell'], 'active', 8000, policyNo: '', comm: true);
        // 3) not_delivered — active, mailing_date null (default) + policy_no present + comm present
        $this->policy('TST-FU3-notdelivered', $this->products['nonlife'], $this->nonLifeCarrier, $this->agents['sell'], 'active', 8000, comm: true, mailing: false);
        // 4) freelook — life product + freelook_end_date null
        $this->policy('TST-FU4-freelook', $this->products['life'], $this->lifeCarrier, $this->agents['sell'], 'active', 8000, life: true, comm: true, mailing: true);
        // 5) no_commission — active + comm null/0
        $this->policy('TST-FU5-nocomm', $this->products['nonlife'], $this->nonLifeCarrier, $this->agents['sell'], 'active', 8000, comm: false, mailing: true);
        // 6) cancelled
        $this->policy('TST-FU6-cancelled', $this->products['nonlife'], $this->nonLifeCarrier, $this->agents['sell'], 'cancelled', 8000);
    }

    // ── E. ติดตามเงิน (collections) ───────────────────────────────────────
    private function seedCollectionsCases(): void
    {
        // Cash unpaid: totalDue>0, no payments → full outstanding
        $this->policy('TST-COL1-cash-unpaid', $this->products['nonlife'], $this->nonLifeCarrier, $this->agents['sell'], 'active', 10000, comm: true, mailing: true);
        // Cash partial: one payment < due
        $cp = $this->policy('TST-COL2-cash-partial', $this->products['nonlife'], $this->nonLifeCarrier, $this->agents['sell'], 'active', 10000, comm: true, mailing: true);
        DB::table('policy_payments')->updateOrInsert(['policy_id' => $cp->id, 'reference' => 'TST-COL2-PARTIAL'], ['payment_date' => now()->subDays(10)->toDateString(), 'amount' => 4000, 'method' => 'bankTransfer', 'created_at' => now(), 'updated_at' => now()]);
        // Installment overdue: installment_mode=A, term>1, first_due in the past, no payments
        $ip = $this->policy('TST-COL3-inst-overdue', $this->products['nonlife'], $this->nonLifeCarrier, $this->agents['sell'], 'active', 12000, comm: true, mailing: true, extra: [
            'installment_mode' => 'A', 'installment_term' => '6', 'premium_mode' => 'monthly',
            'first_due_inst_date' => now()->subMonths(3)->toDateString(),
        ]);
        // A reminder already logged on the installment policy
        DB::table('payment_reminders')->updateOrInsert(['tenant_id' => $this->tenantId, 'policy_id' => $ip->id, 'note' => 'TST โทรทวงงวดที่ 1 แล้ว'], ['installment_no' => 1, 'channel' => 'phone', 'amount_due' => 2000, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── G. Insurer receipt — receivables in every status ──────────────────
    private function seedReceiptCases(): void
    {
        // Create policies with a carrier-to-hub commission so Expected > 0, then
        // set the receivable rows directly to each status.
        $statuses = [
            ['TST-RC1-pending', 'Pending', null, null],
            ['TST-RC2-matched', 'Matched', 5000, null],
            ['TST-RC3-mismatch', 'Mismatch', 4500, null],
            ['TST-RC4-received', 'Received', 5000, now()->toDateString()],
            ['TST-RC5-nocomm', 'No Commission', null, null],
        ];
        foreach ($statuses as [$slug, $status, $statement, $recvDate]) {
            $p = $this->policy($slug, $this->products['nonlife'], $this->nonLifeCarrier, $this->agents['sell'], 'active', 10000, comm: true, mailing: true, carrierComm: 5000);
            foreach (['MAIN', 'OV'] as $type) {
                DB::table('commission_receivables')->updateOrInsert(
                    ['policy_id' => $p->id, 'commission_type' => $type],
                    [
                        'tenant_id' => $this->tenantId, 'insurer_id' => $this->nonLifeCarrier->id, 'policy_year' => 1,
                        'expected_amount' => $type === 'MAIN' ? 5000 : 0,
                        'statement_amount' => $type === 'MAIN' ? $statement : null,
                        'received_amount' => ($type === 'MAIN' && $status === 'Received') ? 5000 : null,
                        'status' => $type === 'MAIN' ? $status : 'Pending',
                        'received_date' => $type === 'MAIN' ? $recvDate : null,
                        'version' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ],
                );
            }
        }

        // A receipt batch (statement round) on the non-life carrier.
        DB::table('commission_receipt_batches')->updateOrInsert(
            ['tenant_id' => $this->tenantId, 'batch_no' => 'TST-REC-0001'],
            ['insurer_id' => $this->nonLifeCarrier->id, 'policy_year' => 1, 'statement_date' => now()->subDays(5)->toDateString(), 'received_file_date' => now()->toDateString(), 'remark' => 'TST รอบทดสอบ', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    // ── policy factory ────────────────────────────────────────────────────
    private function policy(string $slug, Product $product, Carrier $carrier, Agent $seller, string $status, float $premium, ?string $policyNo = null, bool $comm = false, bool $mailing = false, bool $life = false, float $carrierComm = 0, ?float $hubRate = null, array $extra = []): Policy
    {
        $attrs = array_merge([
            'customer_id' => $this->cust->id,
            'product_id' => $product->id,
            'carrier_id' => $carrier->id,
            'writing_agent_id' => $seller->id,
            'application_no' => strtoupper(str_replace('TST-', 'TSTAPP', $slug)),
            'policy_no' => $policyNo === '' ? null : ($policyNo ?? strtoupper(str_replace('-', '', $slug))),
            'app_date' => now()->toDateString(),
            'effective_date' => now()->subMonths(1)->toDateString(),
            'expiry_date' => now()->addMonths(11)->toDateString(),
            'policy_year' => 1, 'act_year' => 1, 'new_or_renew' => 'new',
            'coverage' => 100000,
            'annual_premium' => $premium, 'main_premium' => $premium, 'net_premium' => $premium,
            'premium_mode' => 'annual', 'status' => $status,
            'mailing_date' => $mailing ? now()->subDays(3)->toDateString() : null,
            'freelook_active' => $life, 'freelook_end_date' => null,
            'comm_hub_to_agent_amount' => $comm ? round($premium * 0.1, 2) : 0,
            'comm_hub_to_agent_rate' => $hubRate,
            'comm_carrier_to_hub_amount' => $carrierComm,
            'vehicle_on_non_motor' => false,
        ], $extra);

        return Policy::updateOrCreate(['tenant_id' => $this->tenantId, 'application_no' => $attrs['application_no']], $attrs);
    }

    private function pay(Policy $policy, float $amount, string $reference): PolicyPayment
    {
        $existing = PolicyPayment::query()->where('policy_id', $policy->id)->where('reference', $reference)->first();
        if ($existing) {
            DB::table('commission_ledgers')->where('policy_payment_id', $existing->id)->delete();
            $existing->delete();
        }

        return PolicyPayment::create([
            'policy_id' => $policy->id, 'payment_date' => now()->toDateString(),
            'amount' => $amount, 'method' => 'bankTransfer', 'reference' => $reference,
        ]);
    }
}
