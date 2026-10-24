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

    // ── 1B: bank-account guard ────────────────────────────────────────────

    public function test_bankless_agent_is_flagged_needs_info_and_blocks_payment(): void
    {
        $agent = $this->payoutAgent(1, attrs: ['bank_account_no' => null, 'bank_account_name' => null]);
        $this->sellAndPay($agent, premium: 20000, rate: 0.10);

        // Preview warns.
        $preview = $this->service->preview((int) $this->tenant->id);
        $this->assertTrue(collect($preview['agents'])->firstWhere('agentCode', $agent->agent_code)['missingBank']);
        $this->assertNotEmpty(array_filter($preview['warnings'], fn ($w) => str_contains($w, 'ไม่มีเลขบัญชี')));

        // Batch flags the row + payment is refused.
        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $row = CommissionPayoutBatchAgent::where('batch_id', $batch->id)->where('agent_id', $agent->id)->firstOrFail();
        $this->assertTrue((bool) $row->needs_info);

        $this->service->approveBatch($batch, $this->staff->id);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ยังไม่มีเลขบัญชี/');
        $this->service->markPaid($batch->fresh(), now()->toDateString(), 'REF', $this->admin->id);
    }

    public function test_agent_with_bank_is_payable(): void
    {
        $agent = $this->payoutAgent(1); // default fixture gives a bank account
        $this->sellAndPay($agent, premium: 20000, rate: 0.10);

        // Preview (before batching — batching locks the policy out of preview).
        $this->assertFalse(collect($this->service->preview((int) $this->tenant->id)['agents'])->firstWhere('agentCode', $agent->agent_code)['missingBank']);

        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $row = CommissionPayoutBatchAgent::where('batch_id', $batch->id)->where('agent_id', $agent->id)->firstOrFail();
        $this->assertFalse((bool) $row->needs_info);
    }

    // ── 1C: exclude ฿0 / unrated rows from payable ────────────────────────

    public function test_zero_commission_policy_is_not_payable_and_goes_to_needs_review(): void
    {
        $agent = $this->payoutAgent(1);
        // Unrated policy — no commission amount (rate 0, amount forced to 0).
        $this->sellAndPay($agent, premium: 20000, rate: 0.0, policyAttrs: ['comm_hub_to_agent_amount' => 0]);

        $preview = $this->service->preview((int) $this->tenant->id);
        // Not in the payable agent list.
        $this->assertNull(collect($preview['agents'])->firstWhere('agentCode', $agent->agent_code));
        $this->assertSame(0.0, (float) $preview['totals']['totalAmount']);
        // Surfaced in needsReview instead.
        $this->assertSame(1, $preview['totals']['needsReviewCount']);
        $this->assertSame($agent->agent_code, $preview['needsReview'][0]['agentCode']);

        // And it cannot form a batch (no payable rows).
        $this->expectException(\RuntimeException::class);
        $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
    }

    public function test_zero_and_rated_mix_only_pays_the_rated_one(): void
    {
        $rated = $this->payoutAgent(1);
        $unrated = $this->payoutAgent(1);
        $this->sellAndPay($rated, premium: 20000, rate: 0.10);
        $this->sellAndPay($unrated, premium: 20000, rate: 0.0, policyAttrs: ['comm_hub_to_agent_amount' => 0]);

        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        // Only the rated agent gets a row; no ฿0 line item.
        $this->assertSame(1, CommissionPayoutBatchAgent::where('batch_id', $batch->id)->count());
        $this->assertSame($rated->id, CommissionPayoutBatchAgent::where('batch_id', $batch->id)->value('agent_id'));
    }

    // ── 1D: maker-checker (approver ≠ creator) ────────────────────────────

    public function test_creator_cannot_approve_own_batch(): void
    {
        $agent = $this->payoutAgent(1);
        $this->sellAndPay($agent, premium: 20000, rate: 0.10);
        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ผู้อนุมัติต้องไม่ใช่คนเดียวกับผู้สร้าง/');
        $this->service->approveBatch($batch, $this->admin->id); // same user as creator
    }

    public function test_a_different_user_can_approve(): void
    {
        $agent = $this->payoutAgent(1);
        $this->sellAndPay($agent, premium: 20000, rate: 0.10);
        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);

        $approved = $this->service->approveBatch($batch, $this->staff->id); // different user
        $this->assertSame('APPROVED', $approved->status);
        $this->assertSame($this->staff->id, $approved->approved_by_user_id);
    }

    public function test_self_approve_is_allowed_with_the_capability_flag(): void
    {
        $agent = $this->payoutAgent(1);
        $this->sellAndPay($agent, premium: 20000, rate: 0.10);
        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);

        $approved = $this->service->approveBatch($batch, $this->admin->id, allowSelfApprove: true);
        $this->assertSame('APPROVED', $approved->status);
    }

    public function test_payAgent_one_shot_requires_self_approve(): void
    {
        $agent = $this->payoutAgent(1);
        $this->sellAndPay($agent, premium: 20000, rate: 0.10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/อนุมัติรอบที่ตัวเองสร้าง/');
        $this->service->payAgent((int) $this->tenant->id, $agent->id, now()->toDateString(), 'REF', $this->admin->id);
    }

    // ── 1E: pre-receipt gate (carrier must have remitted) ─────────────────

    public function test_policy_not_received_from_carrier_is_not_payable_when_gate_on(): void
    {
        $this->tenant->update(['payout_require_insurer_received' => true]);
        $agent = $this->payoutAgent(1);
        $policy = $this->sellAndPay($agent, premium: 20000, rate: 0.10);
        // No 'Received' receivable → gated.
        \App\Models\CommissionReceivable::create([
            'tenant_id' => $this->tenant->id, 'policy_id' => $policy->id, 'commission_type' => 'MAIN',
            'expected_amount' => 2000, 'status' => 'Pending',
        ]);

        $preview = $this->service->preview((int) $this->tenant->id);
        $this->assertNull(collect($preview['agents'])->firstWhere('agentCode', $agent->agent_code));
        $this->assertSame(1, $preview['totals']['needsReviewCount']);
        $this->assertStringContainsString('รอรับค่าคอมจากบริษัทประกัน', $preview['needsReview'][0]['reason']);
    }

    public function test_received_policy_is_payable_when_gate_on(): void
    {
        $this->tenant->update(['payout_require_insurer_received' => true]);
        $agent = $this->payoutAgent(1);
        $policy = $this->sellAndPay($agent, premium: 20000, rate: 0.10);
        \App\Models\CommissionReceivable::create([
            'tenant_id' => $this->tenant->id, 'policy_id' => $policy->id, 'commission_type' => 'MAIN',
            'expected_amount' => 2000, 'received_amount' => 2000, 'status' => 'Received', 'received_date' => now()->toDateString(),
        ]);

        $this->assertNotNull(collect($this->service->preview((int) $this->tenant->id)['agents'])->firstWhere('agentCode', $agent->agent_code));
    }

    public function test_gate_off_keeps_back_compat(): void
    {
        // Fixture default: all payout_require_* are false → no gating.
        $agent = $this->payoutAgent(1);
        $this->sellAndPay($agent, premium: 20000, rate: 0.10);
        // No receivable at all, but gate off → still payable.
        $this->assertNotNull(collect($this->service->preview((int) $this->tenant->id)['agents'])->firstWhere('agentCode', $agent->agent_code));
    }
}
