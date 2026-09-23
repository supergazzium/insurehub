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
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * CommissionE2ESeeder — a focused end-to-end commission test set.
 *
 * Reuses the existing TST agent line (same สายงาน, three levels):
 *   สมชาย ใจดีมั่นคง  (TST-A01, Lv5)   ← top
 *    └─ สมหญิง รักการขาย (TST-A02, Lv3)
 *        └─ อนุชา พากเพียรยิ่ง (TST-A03, Lv1)  ← the seller
 *
 * Creates 2 NEW products (prefix E2E-) and 2 policies, each sold by อนุชา (Lv1)
 * with a payment that fires MgmCommissionEngine. Because the seller is at the
 * bottom of a 3-level line, every commission type fires — DIRECT (อนุชา),
 * REFERRAL (สมหญิง), and MANAGEMENT_DIFFERENTIAL up the chain (สมหญิง + สมชาย) —
 * so the differential is fully exercised.
 *
 * carrier→hub commission is also set so the insurer-RECEIPT side has data for
 * the full round-trip (รับค่าคอม → ทำจ่าย).
 *
 *   Products:  E2E-P-MOTOR  (motor, tier_full), E2E-P-FIRE (non-life)
 *   Policies:  E2E-POL-MOTOR (เบี้ย ฿20,000), E2E-POL-FIRE (เบี้ย ฿30,000)
 *   Payments:  E2E-PAY-MOTOR, E2E-PAY-FIRE
 *
 * Cleanup: delete rows where code/application_no/reference LIKE 'E2E%'.
 */
class CommissionE2ESeeder extends Seeder
{
    private int $tenantId;

    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) { $this->command?->error('No tenant — run TenantSeeder.'); return; }
        $this->tenantId = (int) $tenant->id;

        // The seller line must exist (from TestScenarioSeeder). Fail loudly otherwise.
        $seller = Agent::where('tenant_id', $this->tenantId)->where('agent_code', 'TST-A03')->first();
        if (! $seller) {
            $this->command?->error('TST agent line missing — run TestScenarioSeeder first (db:seed --class=TestScenarioSeeder).');

            return;
        }

        $nonLifeCarrier = Carrier::where('tenant_id', $this->tenantId)->where('insure_type', 'non-life')->first()
            ?? Carrier::updateOrCreate(['tenant_id' => $this->tenantId, 'code' => 'E2ENL'],
                ['name' => 'E2E บริษัทประกันวินาศภัย', 'insure_type' => 'non-life', 'active' => true]);

        $customer = Customer::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'customer_code' => 'E2E-C01'],
            ['customer_type' => 'individual', 'first_name' => 'ประเสริฐ', 'last_name' => 'ทดสอบคอม', 'phone' => '0811111111', 'active' => true],
        );

        DB::transaction(function () use ($nonLifeCarrier, $seller, $customer): void {
            // ── 2 products ──
            $motor = $this->product('E2E-P-MOTOR', $nonLifeCarrier, 'MOTOR_CLASS1_GARAGE', 'motor');
            $fire = $this->product('E2E-P-FIRE', $nonLifeCarrier, 'FIRE_HOUSE_BASIC', 'non_life');

            // ── 2 policies sold by อนุชา (Lv1) — full cascade fires ──
            // hubRate = the DIRECT base rate (InsureHub → agent). carrierComm =
            // what the insurer pays InsureHub (for the receipt side).
            $p1 = $this->policy('E2E-POL-MOTOR', $motor, $nonLifeCarrier, $seller, $customer, 20000, hubRate: 0.10, carrierComm: 4000);
            $this->pay($p1, 20000, 'E2E-PAY-MOTOR');

            $p2 = $this->policy('E2E-POL-FIRE', $fire, $nonLifeCarrier, $seller, $customer, 30000, hubRate: 0.12, carrierComm: 6600);
            $this->pay($p2, 30000, 'E2E-PAY-FIRE');
        });

        $this->printSummary();
        $this->command?->info('CommissionE2ESeeder complete. Prefix E2E.');
    }

    private function product(string $code, Carrier $carrier, string $typeCode, string $type): Product
    {
        $pt = ProductType::where('tenant_id', $this->tenantId)->where('code', $typeCode)->first();

        return Product::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'code' => $code],
            [
                'carrier_id' => $carrier->id,
                'name' => "E2E {$code}",
                'type' => $type,
                'product_type_id' => $pt?->id,
                'commission_tier_id' => $pt?->tier_id,
                'coverage' => 200000, 'duration_years' => 1, 'pay_years' => 1,
                'premium_mode' => 'annual', 'min_premium' => 0, 'max_premium' => 5000000,
                'min_age' => 0, 'max_age' => 99, 'gender' => 'all', 'active' => true,
            ],
        );
    }

    private function policy(string $slug, Product $product, Carrier $carrier, Agent $seller, Customer $customer, float $premium, float $hubRate, float $carrierComm): Policy
    {
        return Policy::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'application_no' => strtoupper(str_replace('-', '', $slug))],
            [
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'carrier_id' => $carrier->id,
                'writing_agent_id' => $seller->id,
                'policy_no' => strtoupper(str_replace('-', '', $slug)),
                'app_date' => now()->toDateString(),
                'effective_date' => now()->subDays(5)->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
                'policy_year' => 1, 'act_year' => 1, 'new_or_renew' => 'new',
                'coverage' => 200000,
                'annual_premium' => $premium, 'main_premium' => $premium, 'net_premium' => $premium,
                'premium_mode' => 'annual', 'status' => 'active',
                'mailing_date' => now()->subDays(2)->toDateString(),
                // Commission basis: InsureHub → agent (DIRECT base rate) + carrier → hub.
                'comm_hub_to_agent_rate' => $hubRate,
                'comm_hub_to_agent_amount' => round($premium * $hubRate, 2),
                'comm_carrier_to_hub_amount' => $carrierComm,
                'vehicle_on_non_motor' => false,
            ],
        );
    }

    private function pay(Policy $policy, float $amount, string $reference): PolicyPayment
    {
        $existing = PolicyPayment::where('policy_id', $policy->id)->where('reference', $reference)->first();
        if ($existing) {
            DB::table('commission_ledgers')->where('policy_payment_id', $existing->id)->delete();
            $existing->delete();
        }

        return PolicyPayment::create([
            'policy_id' => $policy->id, 'payment_date' => now()->toDateString(),
            'amount' => $amount, 'method' => 'bankTransfer', 'reference' => $reference,
        ]);
    }

    private function printSummary(): void
    {
        $rows = DB::table('commission_ledgers as cl')
            ->join('policy_payments as pp', 'pp.id', '=', 'cl.policy_payment_id')
            ->join('agents as ba', 'ba.id', '=', 'cl.beneficiary_agent_id')
            ->where('pp.reference', 'like', 'E2E-PAY%')
            ->orderBy('pp.reference')->orderBy('cl.payout_type')->orderBy('cl.id')
            ->get(['pp.reference', 'cl.payout_type', 'ba.agent_code', 'cl.amount', 'cl.rate_applied']);

        $this->command?->line('  ── commission ledger produced ──');
        foreach ($rows as $r) {
            $this->command?->line(sprintf('  %s  %-26s %s  ฿%s (rate %s)', $r->reference, $r->payout_type, $r->agent_code, number_format((float) $r->amount, 2), $r->rate_applied));
        }
    }
}
