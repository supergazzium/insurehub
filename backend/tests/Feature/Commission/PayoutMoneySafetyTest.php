<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Models\CommissionPayoutBatchAgent;
use App\Services\Commission\CommissionPayoutService;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commission\Concerns\BuildsPayoutScenario;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

/**
 * Money-safety remediation (🔴 Phase 1). Self-contained — does NOT use the
 * rotted collectLines()/payee scenarios in CommissionPayoutV2Test; builds its
 * own via BuildsPayoutScenario + createBatch, which are current.
 */
class PayoutMoneySafetyTest extends TestCase
{
    use BuildsPolicyFixtures;
    use BuildsPayoutScenario;
    use RefreshDatabase;

    private CommissionPayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacPermissionSeeder::class);
        $this->buildFixtures();
        $this->seedMgm();
        $this->service = app(CommissionPayoutService::class);
    }

    // ── 1A: VAT visibility ────────────────────────────────────────────────

    public function test_vatFor_matches_the_pdf_rule(): void
    {
        // Exclude: +7% on top.
        $this->assertSame(700.0, CommissionPayoutService::vatFor(10000, '2'));
        // Include: carved out of the gross (10000 − 10000/1.07 = 654.21).
        $this->assertSame(654.21, CommissionPayoutService::vatFor(10000, '3'));
        // Non-VAT: none.
        $this->assertSame(0.0, CommissionPayoutService::vatFor(10000, '1'));
    }

    public function test_vat_exclude_agent_gets_vat_amount_stored_but_net_transfer_unchanged(): void
    {
        $agent = $this->payoutAgent(1, vat: 'exclude');
        $this->sellAndPay($agent, premium: 20000, rate: 0.10); // commission 2000

        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $row = CommissionPayoutBatchAgent::where('batch_id', $batch->id)->where('agent_id', $agent->id)->firstOrFail();

        // Assert the RELATIONSHIPS (gross is set by the MGM engine, not hardcoded):
        $gross = (float) $row->gross_commission;
        $this->assertGreaterThan(0, $gross);
        // VAT exclude = gross × 7%.
        $this->assertEqualsWithDelta(round($gross * 0.07, 2), (float) $row->vat_amount, 0.01);
        // net_transfer is UNCHANGED (no VAT): gross − deduct − wht.
        $expectedNet = round($gross - (float) $row->deduct_amount - (float) $row->wht_amount, 2);
        $this->assertEqualsWithDelta($expectedNet, (float) $row->net_transfer, 0.01);
        // vat_expected_net = net + vat, and it differs from net (discrepancy).
        $this->assertEqualsWithDelta(round($expectedNet + (float) $row->vat_amount, 2), (float) $row->vat_expected_net, 0.01);
        $this->assertNotEquals((string) $row->net_transfer, (string) $row->vat_expected_net);
    }

    public function test_non_vat_agent_has_no_vat_and_no_discrepancy(): void
    {
        $agent = $this->payoutAgent(1, vat: 'none');
        $this->sellAndPay($agent, premium: 20000, rate: 0.10);

        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $row = CommissionPayoutBatchAgent::where('batch_id', $batch->id)->where('agent_id', $agent->id)->firstOrFail();

        $this->assertSame('0.00', (string) $row->vat_amount);
        // net == vat_expected_net (no VAT) → no discrepancy.
        $this->assertSame((string) $row->net_transfer, (string) $row->vat_expected_net);
    }
}
