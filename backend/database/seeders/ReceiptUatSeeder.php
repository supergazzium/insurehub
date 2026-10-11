<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Policy;
use App\Models\PolicyEvent;
use Illuminate\Database\Seeder;
use RuntimeException;

class ReceiptUatSeeder extends Seeder
{
    public function run(): void
    {
        $template = Policy::query()->where('policy_no', 'TSTRC1PENDING')->first();
        if ($template === null) {
            throw new RuntimeException('Run TestScenarioSeeder first (needs policy TSTRC1PENDING).');
        }

        $cases = [
            ['TSTRCV01', 20000, 0.18, 'active', []],
            ['TSTRCV02', 15000, 0.18, 'active', []],
            ['TSTRCV03', 10000, 0.18, 'active', ['endorsement_count' => 1]],
            ['TSTRCV04', 12000, 0.18, 'cancelled', ['cancel_date' => '2026-09-25', 'refund_premium' => 6000, 'cancel_check_carrier_deduct' => 'pending']],
            ['TSTRCV05', 8000, null, 'active', []],
            ['TSTRCV06', 12000, 0.18, 'active', []],
        ];

        foreach ($cases as $i => [$no, $premium, $rate, $status, $extra]) {
            $policy = Policy::updateOrCreate(
                ['tenant_id' => $template->tenant_id, 'application_no' => 'APP-'.$no],
                array_merge([
                    'customer_id' => $template->customer_id,
                    'product_id' => $template->product_id,
                    'carrier_id' => $template->carrier_id,
                    'writing_agent_id' => $template->writing_agent_id,
                    'policy_no' => $no,
                    'app_date' => '2026-09-01',
                    'effective_date' => sprintf('2026-09-%02d', 3 + $i * 4),
                    'expiry_date' => sprintf('2027-09-%02d', 3 + $i * 4),
                    'policy_year' => 1, 'customer_year' => 1, 'act_year' => 1, 'new_or_renew' => 'new',
                    'coverage' => 500000,
                    'annual_premium' => $premium, 'main_premium' => $premium, 'net_premium' => $premium,
                    'premium_mode' => 'annual', 'status' => $status,
                    'comm_carrier_to_hub_rate' => $rate,
                    'comm_carrier_to_hub_amount' => $rate !== null ? round($premium * $rate, 2) : null,
                    'comm_hub_to_agent_rate' => 0.1,
                    'comm_hub_to_agent_amount' => round($premium * 0.1, 2),
                    'vehicle_on_non_motor' => false,
                ], $extra),
            );

            if ($no === 'TSTRCV03') {
                PolicyEvent::firstOrCreate(
                    ['policy_id' => $policy->id, 'type' => 'endorsement.premium_change', 'endorsement_seq' => 1],
                    [
                        'occurred_at' => '2026-09-20 10:00:00',
                        'payload' => [
                            'reason' => 'เพิ่มทุนประกันสิ่งปลูกสร้าง',
                            'effectiveDate' => '2026-09-20',
                            'additionalPremium' => 2000,
                            'additionalDuty' => 8,
                            'additionalVat' => 140.56,
                            'additionalTotal' => 2148.56,
                        ],
                    ],
                );
            }
        }
    }
}
