<?php

declare(strict_types=1);

namespace Tests\Feature\Commission\Concerns;

use App\Models\Agent;
use App\Models\CommissionTier;
use App\Models\Policy;
use App\Models\PolicyPayment;
use App\Models\Product;
use App\Models\Rank;
use Database\Seeders\CommissionTierSeeder;
use Database\Seeders\RankSeeder;

trait BuildsPayoutScenario
{
    protected Product $mgmProduct;

    protected int $agentSeq = 0;

    protected function seedMgm(): void
    {
        $this->seed(RankSeeder::class);
        $this->seed(CommissionTierSeeder::class);
        $tier = CommissionTier::query()->where('tenant_id', $this->tenant->id)->where('code', 'TIER_FULL')->firstOrFail();
        $this->mgmProduct = $this->makeProduct('MGM', $this->carrierA, ['commission_tier_id' => $tier->id]);
    }

    protected function rankId(int $level): int
    {
        return (int) Rank::query()->where('level', $level)->value('id');
    }

    protected function thaiTaxId(string $prefix12): string
    {
        $sum = 0;
        foreach (str_split($prefix12) as $i => $d) {
            $sum += (int) $d * (13 - $i);
        }

        return $prefix12.((11 - $sum % 11) % 10);
    }

    protected function payoutAgent(int $level, ?Agent $parent = null, string $kind = 'individual', string $vat = 'none', array $attrs = []): Agent
    {
        $n = ++$this->agentSeq;
        $vatAttrs = match ($vat) {
            'include' => ['has_vat' => true, 'vat_mode' => 'include', 'vat_type' => '3'],
            'exclude' => ['has_vat' => true, 'vat_mode' => 'exclude', 'vat_type' => '2'],
            default => ['has_vat' => false, 'vat_mode' => null, 'vat_type' => '1'],
        };

        return Agent::create(array_merge([
            'tenant_id' => $this->tenant->id, 'agent_code' => sprintf('PY%03d', $n), 'agent_type' => 'IN',
            'first_name' => "ผู้รับ{$n}", 'last_name' => 'ทดสอบ', 'kind' => $kind,
            'juristic_name' => $kind === 'corporate' ? "บริษัท ผู้รับ{$n} จำกัด" : null,
            'tax_id' => $kind === 'corporate' ? $this->thaiTaxId(sprintf('0105562%05d', $n)) : null,
            'id_card' => $kind === 'individual' ? $this->thaiTaxId(sprintf('1100700%05d', $n)) : null,
            'rank_id' => $this->rankId($level), 'level' => 'l'.$level, 'parent_agent_id' => $parent?->id,
            'active' => true, 'approval_status' => 'approved',
            'bank_name_text' => 'กสิกรไทย', 'bank_account_no' => sprintf('12345%05d', $n), 'bank_account_name' => "ผู้รับ{$n} ทดสอบ",
        ], $vatAttrs, $attrs));
    }

    protected function sellAndPay(Agent $seller, float $premium = 20000, float $rate = 0.10, array $policyAttrs = [], ?float $paid = null): Policy
    {
        $policy = $this->makePolicy(array_merge([
            'writing_agent_id' => $seller->id,
            'product_id' => $this->mgmProduct->id,
            'net_premium' => $premium,
            'main_premium' => $premium,
            'comm_hub_to_agent_rate' => $rate,
            'comm_hub_to_agent_amount' => round($premium * $rate, 2),
        ], $policyAttrs));
        PolicyPayment::create([
            'policy_id' => $policy->id, 'payment_date' => now()->toDateString(), 'amount' => $paid ?? $premium, 'method' => 'bankTransfer',
        ]);

        return $policy->fresh();
    }
}
