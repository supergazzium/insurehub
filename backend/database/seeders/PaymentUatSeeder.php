<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Policy;
use Illuminate\Database\Seeder;
use RuntimeException;

class PaymentUatSeeder extends Seeder
{
    public function run(): void
    {
        $template = Policy::query()->where('policy_no', 'TSTRC1PENDING')->first();
        if ($template === null) {
            throw new RuntimeException('Run TestScenarioSeeder first (needs policy TSTRC1PENDING).');
        }

        $cases = [
            ['TSTPAY01', 20000, 'active'],
            ['TSTPAY02', 15000, 'active'],
            ['TSTPAY03', 12000, 'active'],
            ['TSTPAY04', 10000, 'issued'],
            ['TSTPAY05', 8000, 'active'],
        ];

        foreach ($cases as $i => [$no, $premium, $status]) {
            Policy::updateOrCreate(
                ['tenant_id' => $template->tenant_id, 'application_no' => 'APP-'.$no],
                [
                    'customer_id' => $template->customer_id,
                    'product_id' => $template->product_id,
                    'carrier_id' => $template->carrier_id,
                    'writing_agent_id' => $template->writing_agent_id,
                    'policy_no' => $no,
                    'app_date' => '2026-09-01',
                    'effective_date' => sprintf('2026-09-%02d', 5 + $i * 3),
                    'expiry_date' => sprintf('2027-09-%02d', 5 + $i * 3),
                    'policy_year' => 1, 'customer_year' => 1, 'act_year' => 1, 'new_or_renew' => 'new',
                    'coverage' => 500000,
                    'annual_premium' => $premium, 'main_premium' => $premium, 'net_premium' => $premium,
                    'premium_mode' => 'annual', 'status' => $status,
                    'comm_carrier_to_hub_rate' => 0.18,
                    'comm_carrier_to_hub_amount' => round($premium * 0.18, 2),
                    'comm_hub_to_agent_rate' => 0.1,
                    'comm_hub_to_agent_amount' => round($premium * 0.1, 2),
                    'vehicle_on_non_motor' => false,
                ],
            );
        }
    }
}
