<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Models\AuditEntry;
use App\Models\CommissionLedger;
use App\Models\CommissionPayoutAdjustment;
use App\Models\CommissionPayoutBatch;
use App\Models\CommissionPayoutBatchItem;
use App\Models\CommissionPayoutPayee;
use App\Models\PolicyPayment;
use App\Models\Role;
use App\Models\User;
use App\Services\Commission\CommissionPayoutService;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\Commission\Concerns\BuildsPayoutScenario;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class CommissionPayoutV2Test extends TestCase
{
    use BuildsPayoutScenario;
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    private CommissionPayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacPermissionSeeder::class);
        Cache::flush();
        $this->buildFixtures();
        $this->seedMgm();
        $this->service = app(CommissionPayoutService::class);

        // The 3-stage/MGM payout engine is built phase-by-phase (see
        // backend/PAYOUT_3STAGE_P1_DESIGN.md + COMMISSION_SUITE_BREAKDOWN.md).
        // A test is un-skipped once its phase lands; everything else stays
        // incomplete (green, not deleted — still the living spec).
        if (! in_array($this->name(), self::BUILT_TESTS, true)) {
            $this->markTestIncomplete('3-stage payout engine: this phase not yet built — see backend/PAYOUT_3STAGE_P1_DESIGN.md');
        }
    }

    /** Tests whose feature phase has landed (un-skipped). Grows per phase. */
    private const BUILT_TESTS = [
        // P1 — ledger-driven collection core:
        'test_upline_lines_are_paid_once_and_the_direct_base_is_never_paid_twice',
        'test_engine_caps_the_commission_base_cumulatively_at_net_premium',
        'test_a_payment_whose_commission_is_in_a_payout_cannot_be_voided',
    ];

    private function conditions(bool $freelook, bool $insurer, bool $customer): void
    {
        $this->tenant->update([
            'payout_require_freelook' => $freelook,
            'payout_require_insurer_received' => $insurer,
            'payout_require_customer_paid' => $customer,
        ]);
    }

    private function received(int $policyId, string $status = 'Received'): void
    {
        DB::table('commission_receivables')->insert([
            'tenant_id' => $this->tenant->id, 'policy_id' => $policyId, 'commission_type' => 'MAIN',
            'expected_amount' => 0, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function roleUser(string $roleKey): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => $roleKey.'-'.uniqid(), 'email' => $roleKey.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'), 'role' => $roleKey,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'locale' => 'th', 'active' => true,
        ]);
    }

    private function payBatch(CommissionPayoutBatch $batch, string $date = '2026-10-05'): CommissionPayoutBatch
    {
        return $this->service->markPaid($this->service->approveBatch($batch, $this->admin->id), $date, 'TRF', $this->admin->id);
    }

    public function test_upline_lines_are_paid_once_and_the_direct_base_is_never_paid_twice(): void
    {
        $top = $this->payoutAgent(5);
        $mid = $this->payoutAgent(3, $top);
        $seller = $this->payoutAgent(1, $mid);
        $policy = $this->sellAndPay($seller);

        $list = collect($this->service->byAgent((int) $this->tenant->id)['agents'])->keyBy('agentCode');
        $this->assertEquals(2000, $list[$seller->agent_code]['amount']);
        $this->assertEquals(1000, $list[$mid->agent_code]['amount']);
        $this->assertEquals(200, $list[$top->agent_code]['amount']);

        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $this->assertEquals(3200, (float) $batch->total_amount);
        $this->assertSame(['base', 'override', 'override', 'referral'], $batch->items()->orderBy('line_type')->pluck('line_type')->all());
        $this->assertSame(3, CommissionLedger::query()->where('payout_batch_id', $batch->id)->count());
        $this->assertNull(CommissionLedger::query()->where('payout_type', 'DIRECT_COMMISSION')->value('payout_batch_id'));

        $this->assertSame([], $this->service->collectLines((int) $this->tenant->id)['ready']);
        $this->expectException(RuntimeException::class);
        try {
            $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        } finally {
            $this->payBatch($batch);
            $this->assertSame(3, CommissionLedger::query()->where('status', 'settled')->count());
            $this->assertSame('paid', DB::table('policy_rebates')->where('policy_id', $policy->id)->value('agent_rebate_status'));
        }
    }

    public function test_installment_policy_is_paid_pro_rata_and_the_rest_survives_a_date_filter(): void
    {
        $this->conditions(false, false, true);
        $seller = $this->payoutAgent(1);
        $policy = $this->sellAndPay($seller, 20000, 0.10, ['premium_mode' => 'monthly', 'installment_term' => 10], 6000);
        $this->assertSame(3, $policy->installments()->where('status', 'paid')->count());

        $detail = $this->service->agentDetail((int) $this->tenant->id, (int) $seller->id);
        $this->assertEquals(600, $detail['totals']['totalAmount']);
        $this->assertEquals(1400, $detail['totals']['waitingAmount']);
        $this->assertSame('ชำระ 3/10 งวด', $detail['items'][0]['ratioLabel']);
        $this->payBatch($this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id));

        PolicyPayment::create(['policy_id' => $policy->id, 'payment_date' => now()->toDateString(), 'amount' => 4000, 'method' => 'cash']);
        $later = now()->addDays(3)->toDateString();
        $second = $this->service->createBatch((int) $this->tenant->id, $later, $later, $this->admin->id);
        $this->assertEquals(400, (float) $second->items()->where('line_type', 'base')->sum('snapshot_agent_commission'));
        $this->payBatch($second);

        PolicyPayment::create(['policy_id' => $policy->id, 'payment_date' => now()->toDateString(), 'amount' => 10000, 'method' => 'cash']);
        $third = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $this->assertEquals(1000, (float) $third->items()->where('line_type', 'base')->sum('snapshot_agent_commission'));
        $this->payBatch($third);
        $this->assertEquals(2000, (float) CommissionPayoutBatchItem::query()->where('policy_id', $policy->id)->where('line_type', 'base')->sum('snapshot_agent_commission'));
        $this->assertSame('paid', DB::table('policy_rebates')->where('policy_id', $policy->id)->value('agent_rebate_status'));
    }

    public function test_insurer_condition_holds_lines_until_received_and_override_needs_a_reason_and_audit(): void
    {
        $this->conditions(false, true, false);
        $upline = $this->payoutAgent(1);
        $seller = $this->payoutAgent(1, $upline);
        $waiting = $this->sellAndPay($seller);
        $overridden = $this->sellAndPay($seller);

        $detail = $this->service->agentDetail((int) $this->tenant->id, (int) $seller->id);
        $this->assertSame(0, $detail['totals']['itemCount']);
        $this->assertSame('insurer_not_received', $detail['waiting'][0]['reasons'][0]['code']);
        $this->assertSame([], collect($this->service->collectLines((int) $this->tenant->id)['ready'])->all());

        $this->received($waiting->id);
        $this->actingAsAdmin();
        $this->postJson("/api/v1/policies/{$overridden->id}/payout-override", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/policies/{$overridden->id}/payout-override", ['reason' => 'บริษัทยืนยันทางอีเมลแล้ว'])->assertCreated();
        $audit = AuditEntry::query()->where('action', 'commission_payout.override')->firstOrFail();
        $this->assertSame('insurer_not_received', $audit->metadata['bypassed'][0]['code']);

        $ready = collect($this->service->collectLines((int) $this->tenant->id)['ready']);
        $this->assertCount(4, $ready);
        $this->assertNotNull($ready->firstWhere('policyId', $overridden->id)['override']);

        $this->deleteJson("/api/v1/policies/{$overridden->id}/payout-override")->assertOk()->assertJsonPath('data.revoked', 1);
        $this->assertCount(2, $this->service->collectLines((int) $this->tenant->id)['ready']);
    }

    public function test_freelook_blocks_a_life_policy_until_the_period_ends(): void
    {
        $this->conditions(true, false, false);
        $this->mgmProduct->update(['type' => 'life']);
        $seller = $this->payoutAgent(1);
        $policy = $this->sellAndPay($seller, 20000, 0.10, ['delivered_date' => now()->toDateString()]);
        $this->assertNotNull($policy->freelook_end_date);

        $waiting = $this->service->agentDetail((int) $this->tenant->id, (int) $seller->id)['waiting'];
        $this->assertSame('freelook', $waiting[0]['reasons'][0]['code']);

        Carbon::setTestNow(now()->addDays(16));
        $this->assertEquals(2000, $this->service->agentDetail((int) $this->tenant->id, (int) $seller->id)['totals']['totalAmount']);
        Carbon::setTestNow();
    }

    public function test_permissions_are_enforced_and_the_finance_role_can_pay(): void
    {
        $seller = $this->payoutAgent(1);
        $this->sellAndPay($seller);

        Sanctum::actingAs($this->roleUser('sales_support'));
        $this->getJson('/api/v1/commission-payout-batches/by-agent')->assertForbidden();
        $this->postJson("/api/v1/commission-payout-batches/by-agent/{$seller->id}/pay", ['paymentDate' => '2026-10-05'])->assertForbidden();

        Sanctum::actingAs($this->roleUser('finance'));
        $this->getJson('/api/v1/commission-payout-batches/by-agent')->assertOk()->assertJsonPath('agents.0.netAmount', 1940);
        $this->getJson("/api/v1/commission-payout-batches/by-agent/{$seller->id}")->assertOk()
            ->assertJsonPath('meta.canPay', true)->assertJsonPath('meta.canOverride', false);
        $this->postJson("/api/v1/commission-payout-batches/by-agent/{$seller->id}/pay", ['paymentDate' => '2026-10-05'])->assertOk();
        $this->postJson("/api/v1/policies/{$this->makePolicy()->id}/payout-override", ['reason' => 'x'])->assertForbidden();
    }

    public function test_certificates_are_numbered_and_the_monthly_report_lists_withholding(): void
    {
        $upline = $this->payoutAgent(1, null, 'corporate', 'exclude');
        $seller = $this->payoutAgent(1, $upline);
        $this->sellAndPay($seller);
        $first = $this->payBatch($this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id));
        $this->sellAndPay($seller);
        $second = $this->payBatch($this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id), '2026-10-20');

        $numbers = CommissionPayoutPayee::query()->orderBy('cert_no')->get(['batch_id', 'cert_book', 'cert_no']);
        $this->assertSame([1, 2, 3, 4], $numbers->pluck('cert_no')->all());
        $this->assertSame(2569, (int) $numbers->first()->cert_book);
        $this->assertSame([(int) $first->id, (int) $first->id, (int) $second->id, (int) $second->id], $numbers->pluck('batch_id')->map(fn ($v) => (int) $v)->all());

        $this->actingAsAdmin();
        $this->getJson('/api/v1/commission-payout-batches/wht-report?month=2026-10&form=pnd3')->assertOk()
            ->assertJsonPath('data.totals.count', 2)->assertJsonPath('data.totals.wht', 120);
        $this->getJson('/api/v1/commission-payout-batches/wht-report?month=2026-10&form=pnd53')->assertOk()
            ->assertJsonPath('data.totals.count', 2)->assertJsonPath('data.rows.0.amount', 200);
        $this->get('/api/v1/commission-payout-batches/wht-report?month=2026-10&form=pnd53&format=csv')->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $payee = CommissionPayoutPayee::query()->where('batch_id', $first->id)->where('agent_id', $upline->id)->firstOrFail();
        $this->assertEquals(214, (float) $payee->total_amount);
        $this->assertEquals(208, (float) $payee->net_amount);
        $cert = $this->getJson("/api/v1/commission-payout-batches/{$first->id}/payees/{$payee->id}/certificate?as=base64")->assertOk();
        $this->assertStringStartsWith('JVBER', $cert->json('data.base64'));
        $statement = $this->getJson("/api/v1/commission-payout-batches/{$first->id}/agents/{$upline->agent_code}/pdf?as=base64")->assertOk();
        $this->assertStringStartsWith('JVBER', $statement->json('data.base64'));
        $bank = $this->get("/api/v1/commission-payout-batches/{$first->id}/bank.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('1940.00', $bank);
        $this->assertStringContainsString('208.00', $bank);
    }

    public function test_a_policy_cancelled_after_batching_is_excluded_on_pay_and_its_ledger_released(): void
    {
        $upline = $this->payoutAgent(1);
        $seller = $this->payoutAgent(1, $upline);
        $keep = $this->sellAndPay($seller);
        $drop = $this->sellAndPay($seller);
        $batch = $this->service->approveBatch($this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id), $this->admin->id);
        $drop->update(['status' => 'cancelled']);

        try {
            $this->service->markPaid($batch, '2026-10-05', null, $this->admin->id);
            $this->fail('expected exclusion');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2 รายการ', $e->getMessage());
        }
        $batch->refresh();
        $this->assertSame(CommissionPayoutBatch::STATUS_DRAFT, $batch->status);
        $this->assertSame(2, $batch->items()->where('item_status', 'excluded')->count());
        $this->assertSame(0, CommissionLedger::query()->where('policy_id', $drop->id)->whereNotNull('payout_batch_id')->count());
        $this->assertEquals(2000, (float) $batch->payees()->where('agent_id', $seller->id)->value('gross_amount'));

        $paid = $this->payBatch($batch);
        $this->assertSame(CommissionPayoutBatch::STATUS_PAID, $paid->status);
        $this->assertSame(1, CommissionLedger::query()->where('policy_id', $keep->id)->where('status', 'settled')->count());
    }

    public function test_cancelling_a_batch_releases_ledger_rows_and_merges_split_clawbacks(): void
    {
        $upline = $this->payoutAgent(1);
        $seller = $this->payoutAgent(1, $upline);
        CommissionPayoutAdjustment::create([
            'tenant_id' => $this->tenant->id, 'agent_id' => $seller->id, 'agent_code' => $seller->agent_code,
            'type' => 'clawback', 'status' => 'pending', 'amount' => 2500, 'reason' => 'เรียกคืน',
        ]);
        $this->sellAndPay($seller);
        $batch = $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $this->assertEquals(2000, (float) $batch->adjustments()->where('agent_id', $seller->id)->sum('amount'));
        $this->assertEquals(500, (float) CommissionPayoutAdjustment::query()->where('status', 'pending')->sum('amount'));

        $this->service->cancelBatch($batch, $this->admin->id);
        $pending = CommissionPayoutAdjustment::query()->where('status', 'pending')->whereNull('batch_id')->get();
        $this->assertCount(1, $pending);
        $this->assertEquals(2500, (float) $pending->first()->amount);
        $this->assertSame(0, CommissionLedger::query()->whereNotNull('payout_batch_id')->count());
        $this->assertCount(2, $this->service->collectLines((int) $this->tenant->id)['ready']);
    }

    public function test_legacy_batches_keep_their_old_behaviour_and_block_a_repay(): void
    {
        $policy = $this->makePolicy(['comm_hub_to_agent_amount' => 1000]);
        $legacy = CommissionPayoutBatch::create([
            'tenant_id' => $this->tenant->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-30',
            'status' => CommissionPayoutBatch::STATUS_DRAFT, 'calc_version' => CommissionPayoutBatch::CALC_V1,
        ]);
        CommissionPayoutBatchItem::create([
            'batch_id' => $legacy->id, 'tenant_id' => $this->tenant->id, 'policy_id' => $policy->id, 'agent_id' => $this->agent->id,
            'agent_code' => 'AG001', 'vat_type' => '1', 'snapshot_agent_commission' => 1000, 'item_status' => 'pending',
        ]);

        $this->assertSame([], collect($this->service->collectLines((int) $this->tenant->id)['ready'])->where('policyId', $policy->id)->all());
        $pdf = $this->service->buildAgentPdfData($legacy, (int) $this->agent->id, null);
        $this->assertSame(1, $pdf['version']);
        $this->assertEquals(1000, $pdf['totals']['payable']);

        $paid = $this->payBatch($legacy);
        $this->assertSame(CommissionPayoutBatch::STATUS_PAID, $paid->status);
        $this->assertSame(0, $paid->payees()->count());
    }

    public function test_engine_caps_the_commission_base_cumulatively_at_net_premium(): void
    {
        $seller = $this->payoutAgent(1);
        $policy = $this->sellAndPay($seller, 10000, 0.10, [], 6000);
        PolicyPayment::create(['policy_id' => $policy->id, 'payment_date' => now()->toDateString(), 'amount' => 6000, 'method' => 'cash']);

        $this->assertSame(['6000.00', '4000.00'], CommissionLedger::query()->where('policy_id', $policy->id)->where('payout_type', 'DIRECT_COMMISSION')->orderBy('id')->pluck('base_premium')->all());
    }

    public function test_a_payment_whose_commission_is_in_a_payout_cannot_be_voided(): void
    {
        $upline = $this->payoutAgent(1);
        $seller = $this->payoutAgent(1, $upline);
        $policy = $this->sellAndPay($seller);
        $this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $payment = $policy->payments()->firstOrFail();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/policies/{$policy->id}/payments/{$payment->id}/void", ['reason' => 'บันทึกผิดยอด'])
            ->assertStatus(409)->assertJsonPath('code', 'payment_commission_paid');
        $this->assertSame(3, CommissionLedger::query()->where('policy_payment_id', $payment->id)->count());
        $this->assertNull(PolicyPayment::withoutGlobalScopes()->find($payment->id)->voided_at);
    }

    public function test_upline_clawback_follows_the_seller_ratio_and_is_zero_when_not_applicable(): void
    {
        $upline = $this->payoutAgent(1);
        $seller = $this->payoutAgent(1, $upline);
        $policy = $this->sellAndPay($seller);
        $this->payBatch($this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id));
        $policy->update(['status' => 'cancelled']);

        $this->actingAsAdmin();
        $url = "/api/v1/policies/{$policy->id}/cancellation-review";
        $this->patchJson($url, ['clawbackAmount' => 1000, 'checks' => ['refund' => 'na', 'clawback' => 'done', 'carrierDeduct' => 'done'], 'markReviewed' => true])
            ->assertOk()->assertJsonPath('data.uplineRatio', 0.5)->assertJsonPath('data.uplines.0.clawback', 100)
            ->assertJsonPath('data.suggestion.paidCommission', 2000);
        $this->assertEquals(100, (float) CommissionPayoutAdjustment::query()->where('agent_id', $upline->id)->where('status', 'pending')->sum('amount'));

        $this->patchJson($url, ['checks' => ['clawback' => 'na']])->assertOk()->assertJsonPath('data.uplineRatio', 0);
        $this->assertSame(0, CommissionPayoutAdjustment::query()->where('agent_id', $upline->id)->count());
    }

    public function test_portal_earnings_show_ready_waiting_and_paid_history_with_own_documents(): void
    {
        $upline = $this->payoutAgent(1);
        $seller = $this->payoutAgent(1, $upline);
        $agentUser = User::create([
            'tenant_id' => $this->tenant->id, 'agent_id' => $upline->id, 'name' => 'Upline', 'email' => 'up-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'), 'role' => 'agent', 'role_id' => Role::query()->where('key', 'agent')->value('id'),
            'locale' => 'th', 'active' => true,
        ]);
        $this->sellAndPay($seller);
        $batch = $this->payBatch($this->service->createBatch((int) $this->tenant->id, null, null, $this->admin->id));
        $this->sellAndPay($seller);

        Sanctum::actingAs($agentUser);
        $this->getJson('/api/v1/me/agent/earnings')->assertOk()
            ->assertJsonPath('summary.readyAmount', 200)
            ->assertJsonPath('history.0.referral', 200)
            ->assertJsonPath('history.0.wht', 6)
            ->assertJsonPath('history.0.net', 194);
        $this->assertStringStartsWith('JVBER', $this->getJson("/api/v1/me/agent/payouts/{$batch->id}/statement?as=base64")->assertOk()->json('data.base64'));
        $this->getJson("/api/v1/me/agent/payouts/{$batch->id}/certificate?as=base64")->assertOk();
    }
}
