<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Carrier;
use App\Models\CommissionPayoutBatch;
use App\Models\CommissionTier;
use App\Models\Customer;
use App\Models\Policy;
use App\Models\PolicyPayment;
use App\Models\Product;
use App\Models\Rank;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commission\CommissionPayoutService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for testing the 6 payout-PDF formats (direct × referral, each in
 * Non-VAT / VAT-EX / VAT-IN). Creates, for each VAT mode, a seller + upline
 * chain, sells policies (so the MGM engine accrues DIRECT / REFERRAL /
 * MANAGEMENT_DIFFERENTIAL ledgers), then builds → approves → pays a v2 batch.
 *
 * Idempotent-ish: re-running creates a NEW batch (fresh demo agents each run,
 * suffixed by a short run id) so it never collides with real data.
 *
 * Run:  php artisan db:seed --class=Database\Seeders\PdfExportDemoSeeder
 */
class PdfExportDemoSeeder extends Seeder
{
    private int $tenantId;

    private Product $product;

    private int $carrierId;

    private int $customerId;

    private string $run;

    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->firstOrFail();
        $this->tenantId = (int) $tenant->id;
        $this->run = substr((string) time(), -5);

        // Remember the tenant's real payout-gate policy so we can RESTORE it at
        // the end — a seeder must never leave the money system in an unsafe
        // "pay without the customer paying" state.
        $savedGates = [
            'payout_require_freelook' => $tenant->payout_require_freelook,
            'payout_require_insurer_received' => $tenant->payout_require_insurer_received,
            'payout_require_customer_paid' => $tenant->payout_require_customer_paid,
        ];
        // Temporarily open the gates so the demo lines are all ready. (The
        // demo policies are paid in full anyway, so customer_paid would pass —
        // but drop freelook/insurer too for the life/insurer demo rows.)
        $tenant->forceFill([
            'payout_require_freelook' => false,
            'payout_require_insurer_received' => false,
            'payout_require_customer_paid' => false,
        ])->save();

        $tier = CommissionTier::query()->where('tenant_id', $this->tenantId)->where('code', 'TIER_FULL')->firstOrFail();
        $this->product = Product::query()->where('commission_tier_id', $tier->id)->firstOrFail();
        $this->carrierId = (int) (Carrier::query()->value('id'));
        $this->customerId = (int) (Customer::query()->where('tenant_id', $this->tenantId)->value('id') ?? Customer::query()->value('id'));

        $creator = (int) User::query()->whereIn('role', ['admin', 'super_admin'])->value('id');
        // Approver must differ from creator (maker-checker).
        $approver = (int) (User::query()->where('role', 'finance')->value('id')
            ?? User::query()->where('id', '!=', $creator)->value('id') ?? $creator);

        $vatModes = [
            ['label' => 'NonVAT', 'has_vat' => false, 'vat_mode' => null],
            ['label' => 'VAT-EX', 'has_vat' => true, 'vat_mode' => 'exclude'],
            ['label' => 'VAT-IN', 'has_vat' => true, 'vat_mode' => 'include'],
        ];

        foreach ($vatModes as $i => $vm) {
            $upline = $this->makeAgent("PDFUP{$this->run}{$i}", 5, null, $vm);
            $seller = $this->makeAgent("PDFSL{$this->run}{$i}", 1, $upline, $vm);
            // Two sales so the seller has direct lines and the upline earns
            // referral + management-differential.
            $this->sellAndPay($seller, 25000);
            $this->sellAndPay($seller, 40000);
        }

        $service = app(CommissionPayoutService::class);
        $batch = $service->createBatch($this->tenantId, null, null, $creator, 'PDF export demo');
        $batch = $service->approveBatch($batch, $approver);
        $batch = $service->markPaid($batch, now()->toDateString(), 'PDF-DEMO-'.$this->run, $creator);

        // Restore the tenant's real payout-gate policy — never leave it open.
        $tenant->forceFill($savedGates)->save();

        $this->command?->info('');
        $this->command?->info('✅ PDF-export demo ready.');
        $this->command?->info("   Paid batch #{$batch->id} (status {$batch->status}).");
        $this->command?->info('   Open the payout detail:  /insurehub/commissions/payouts/'.$batch->id);
        $this->command?->info('   Admin PDF per agent:');
        $this->command?->info("     …/commission-payout-batches/{$batch->id}/agents/{code}/pdf/direct");
        $this->command?->info("     …/commission-payout-batches/{$batch->id}/agents/{code}/pdf/referral");
        foreach ($batch->agentRows()->get(['agent_code', 'vat_type']) as $r) {
            $this->command?->info("       agent {$r->agent_code}  (vat_type {$r->vat_type})");
        }
    }

    private function makeAgent(string $code, int $level, ?Agent $parent, array $vm): Agent
    {
        $rank = Rank::query()->where('level', $level)->firstOrFail();

        return Agent::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'agent_code' => $code],
            [
                'agent_type' => 'AG',
                'first_name' => $level === 1 ? 'ผู้ขาย' : 'อัพไลน์',
                'last_name' => $vm['label'],
                'kind' => 'individual',
                'id_card' => str_pad((string) random_int(1, 9_999_999_999_999), 13, '0', STR_PAD_LEFT),
                'has_vat' => $vm['has_vat'],
                'vat_mode' => $vm['vat_mode'],
                'vat_type' => $vm['has_vat'] ? ($vm['vat_mode'] === 'include' ? '3' : '2') : '1',
                'level' => 'l'.min($level, 5),
                'rank_id' => $rank->id,
                'parent_agent_id' => $parent?->id,
                'active' => true,
                'approval_status' => 'approved',
                'joined_at' => now()->subYears(2)->toDateString(),
                'bank_name_text' => 'กสิกรไทย',
                'bank_account_no' => '123-4-'.random_int(10000, 99999),
                'bank_account_name' => $code,
            ],
        );
    }

    private function sellAndPay(Agent $seller, float $premium): Policy
    {
        $n = $seller->agent_code.'-'.random_int(1000, 9999);
        $policy = Policy::create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $this->customerId,
            'product_id' => $this->product->id,
            'carrier_id' => $this->carrierId,
            'writing_agent_id' => $seller->id,
            'application_no' => "A{$n}",
            'policy_no' => "P{$n}",
            'effective_date' => now()->subDays(10)->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'policy_year' => 1, 'customer_year' => 1, 'act_year' => 1,
            'new_or_renew' => 'new',
            'coverage' => 500000,
            'annual_premium' => $premium,
            'main_premium' => $premium,
            'net_premium' => $premium,
            'total_premium_paid' => $premium,
            'premium_mode' => 'annual',
            'comm_hub_to_agent_rate' => 0.10,
            'comm_hub_to_agent_amount' => round($premium * 0.10, 2),
            'status' => 'active',
        ]);
        PolicyPayment::create([
            'policy_id' => $policy->id, 'payment_date' => now()->toDateString(),
            'amount' => $premium, 'method' => 'bankTransfer',
        ]);

        return $policy->fresh();
    }
}
