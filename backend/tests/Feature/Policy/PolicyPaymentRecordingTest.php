<?php

declare(strict_types=1);

namespace Tests\Feature\Policy;

use App\Models\CommissionLedger;
use App\Models\CommissionPayoutAdjustment;
use App\Models\Policy;
use App\Models\PolicyDocument;
use App\Models\PolicyEvent;
use App\Models\PolicyPayment;
use App\Services\Commission\CommissionPayoutService;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commission\Concerns\BuildsPayoutScenario;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class PolicyPaymentRecordingTest extends TestCase
{
    use BuildsPayoutScenario;
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacPermissionSeeder::class);
        Cache::flush();
        $this->buildFixtures();
        $this->seedMgm();
        $this->actingAsAdmin();
    }

    private function pay(Policy $p, float $amount, array $extra = [], array $row = []): TestResponse
    {
        return $this->postJson("/api/v1/policies/{$p->id}/payments", array_merge([
            'payments' => [array_merge(['paymentDate' => now()->toDateString(), 'amount' => $amount, 'method' => 'transfer'], $row)],
        ], $extra));
    }

    private function mgmPolicy(array $attrs = [], bool $withUpline = false): Policy
    {
        $seller = $this->payoutAgent(1, $withUpline ? $this->payoutAgent(1) : null);

        return $this->makePolicy(array_merge([
            'writing_agent_id' => $seller->id,
            'product_id' => $this->mgmProduct->id,
            'net_premium' => 20000,
            'main_premium' => 20000,
            'total_premium_paid' => 20000,
            'comm_hub_to_agent_rate' => 0.10,
            'comm_hub_to_agent_amount' => 2000,
        ], $attrs));
    }

    public function test_it_records_payee_mode_note_and_the_summary_and_activates_an_issued_policy(): void
    {
        $p = $this->makePolicy(['status' => 'issued', 'main_premium' => 12000, 'total_premium_paid' => 12000]);

        $res = $this->pay($p, 12000, ['payMode' => 'full', 'payee' => 'insurehub'], ['note' => 'โอนเข้าบัญชีบริษัท', 'reference' => 'SLIP-001'])
            ->assertCreated();

        $res->assertJsonPath('data.0.payee', 'insurehub')
            ->assertJsonPath('data.0.payMode', 'full')
            ->assertJsonPath('data.0.note', 'โอนเข้าบัญชีบริษัท')
            ->assertJsonPath('data.0.reference', 'SLIP-001')
            ->assertJsonPath('data.0.recordedByName', $this->admin->name)
            ->assertJsonPath('meta.status', 'paid')
            ->assertJsonPath('meta.outstanding', 0);
        $this->assertSame('active', $p->fresh()->status);
        $this->assertSame(1, PolicyEvent::query()->where('policy_id', $p->id)->where('type', 'premiumPaid')->count());
    }

    public function test_index_returns_the_summary_and_hides_voided_rows_unless_asked(): void
    {
        $p = $this->makePolicy(['main_premium' => 12000, 'total_premium_paid' => 12000]);
        $this->pay($p, 5000)->assertCreated();
        $second = $this->pay($p, 4000, [], ['paymentDate' => now()->subDay()->toDateString()])->assertCreated()->json('data.0.id');
        $this->postJson("/api/v1/policies/{$p->id}/payments/{$second}/void", ['reason' => 'ลงยอดผิด'])->assertOk();

        $plain = $this->getJson("/api/v1/policies/{$p->id}/payments")->assertOk();
        $this->assertCount(1, $plain->json('data'));
        $plain->assertJsonPath('meta.due', 12000)->assertJsonPath('meta.paid', 5000)->assertJsonPath('meta.outstanding', 7000)
            ->assertJsonPath('meta.status', 'partial')->assertJsonPath('meta.payable', true)->assertJsonPath('meta.canRecord', true);

        $all = $this->getJson("/api/v1/policies/{$p->id}/payments?withVoided=1")->assertOk();
        $this->assertCount(2, $all->json('data'));
        $voided = collect($all->json('data'))->firstWhere('id', $second);
        $this->assertSame('ลงยอดผิด', $voided['voidReason']);
        $this->assertNotNull($voided['voidedAt']);
    }

    public function test_cancelled_and_expired_policies_cannot_take_a_payment(): void
    {
        foreach (['cancelled', 'expired'] as $status) {
            $p = $this->makePolicy(['status' => $status]);
            $this->pay($p, 1000)->assertStatus(422)->assertJsonPath('code', 'policy_not_payable');
            $this->assertSame(0, PolicyPayment::query()->where('policy_id', $p->id)->count());
            $this->getJson("/api/v1/policies/{$p->id}/payments")->assertJsonPath('meta.payable', false);
        }
    }

    public function test_drafts_and_submitted_policies_can_take_a_payment(): void
    {
        foreach (['draft', 'submitted', 'approved'] as $status) {
            $p = $this->makePolicy(['status' => $status]);
            $this->pay($p, 1000)->assertCreated();
        }
    }

    public function test_future_dates_and_zero_amounts_are_rejected(): void
    {
        $p = $this->makePolicy();

        $this->pay($p, 1000, [], ['paymentDate' => now()->addDays(3)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['payments.0.paymentDate']);
        $this->pay($p, 0)->assertStatus(422)->assertJsonValidationErrors(['payments.0.amount']);
        $this->assertSame(0, PolicyPayment::query()->where('policy_id', $p->id)->count());
    }

    public function test_the_proof_must_be_a_receipt_document_of_the_same_policy(): void
    {
        $p = $this->makePolicy();
        $other = $this->makePolicy();
        $foreign = PolicyDocument::create(['policy_id' => $other->id, 'type' => 'receipt', 'file_name' => 'x.pdf']);
        $wrongType = PolicyDocument::create(['policy_id' => $p->id, 'type' => 'application', 'file_name' => 'y.pdf']);
        $slip = PolicyDocument::create(['policy_id' => $p->id, 'type' => 'receipt', 'file_name' => 'slip.png']);

        $this->pay($p, 1000, [], ['proofDocumentId' => $foreign->id])->assertStatus(422);
        $this->pay($p, 1000, [], ['proofDocumentId' => $wrongType->id])->assertStatus(422);
        $this->pay($p, 1000, [], ['proofDocumentId' => $slip->id])->assertCreated()
            ->assertJsonPath('data.0.proof.fileName', 'slip.png')
            ->assertJsonPath('data.0.proof.documentId', (string) $slip->id);
    }

    public function test_users_without_the_permission_cannot_record_or_void(): void
    {
        $p = $this->makePolicy();
        $id = $this->pay($p, 1000)->assertCreated()->json('data.0.id');

        $this->actingAsStaff();
        $this->pay($p, 500)->assertForbidden();
        $this->postJson("/api/v1/policies/{$p->id}/payments/{$id}/void", ['reason' => 'ทดสอบสิทธิ์'])->assertForbidden();
        $this->getJson("/api/v1/policies/{$p->id}/payments")->assertOk()
            ->assertJsonPath('meta.canRecord', false)->assertJsonPath('meta.canVoid', false);
    }

    public function test_a_discount_payment_closes_the_premium_and_is_not_chased(): void
    {
        $p = $this->makePolicy(['main_premium' => 12000, 'total_premium_paid' => 12000, 'discount_amount' => 1500]);

        $this->pay($p, 10500, ['payMode' => 'with_discount', 'adjustmentAmount' => 1500])->assertCreated()
            ->assertJsonPath('data.0.adjustmentKind', 'discount')
            ->assertJsonPath('data.0.adjustmentAmount', 1500)
            ->assertJsonPath('data.0.creditAmount', 12000)
            ->assertJsonPath('meta.status', 'paid')
            ->assertJsonPath('meta.outstanding', 0)
            ->assertJsonPath('meta.cashReceived', 10500);

        $rows = $this->getJson('/api/v1/collections?q='.$p->policy_no)->assertOk()->json('data');
        $this->assertSame([], $rows);
        $this->assertSame('paid', $p->installments()->first()->status);
    }

    public function test_pay_to_carrier_counts_as_paid_but_is_split_in_the_summary_and_report(): void
    {
        $p = $this->makePolicy(['main_premium' => 12000, 'total_premium_paid' => 12000]);

        $this->pay($p, 12000, ['payee' => 'carrier'])->assertCreated()
            ->assertJsonPath('meta.status', 'paid')
            ->assertJsonPath('meta.toCarrier', 12000)
            ->assertJsonPath('meta.toInsureHub', 0);

        $this->getJson('/api/v1/reports/payment-history?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())->assertOk()
            ->assertJsonPath('meta.toCarrier', 12000)
            ->assertJsonPath('meta.toInsureHub', 0)
            ->assertJsonPath('data.0.payee', 'carrier');
    }

    public function test_commission_deducted_by_the_agent_is_credited_and_netted_out_of_the_payout(): void
    {
        $p = $this->mgmPolicy();

        $this->pay($p, 18000, ['payMode' => 'less_commission', 'adjustmentAmount' => 2000])->assertCreated()
            ->assertJsonPath('data.0.adjustmentKind', 'commission_deducted')
            ->assertJsonPath('meta.status', 'paid')
            ->assertJsonPath('meta.outstanding', 0);

        $payment = PolicyPayment::query()->where('policy_id', $p->id)->firstOrFail();
        $direct = CommissionLedger::query()->where('policy_payment_id', $payment->id)->where('payout_type', 'DIRECT_COMMISSION')->firstOrFail();
        $this->assertEquals(20000, (float) $direct->base_premium);
        $offset = CommissionLedger::query()->where('policy_payment_id', $payment->id)->where('payout_type', CommissionLedger::TYPE_COMMISSION_PAID_AT_SOURCE)->firstOrFail();
        $this->assertEquals(-2000, (float) $offset->amount);

        $service = app(CommissionPayoutService::class);
        $deductions = $service->pendingDeductions((int) $this->tenant->id, (int) $p->writing_agent_id);
        $this->assertCount(1, $deductions);
        $this->assertSame(CommissionPayoutAdjustment::TYPE_COMMISSION_DEDUCTED, $deductions[0]['type']);
        $this->assertEquals(2000, $deductions[0]['amount']);

        $batch = $service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $this->assertTrue(CommissionPayoutAdjustment::query()->where('batch_id', $batch->id)->where('type', 'commission_deducted')->exists());
    }

    public function test_the_commission_deducted_cannot_exceed_the_agents_commission_on_the_policy(): void
    {
        $p = $this->mgmPolicy();

        $this->pay($p, 17000, ['payMode' => 'less_commission', 'adjustmentAmount' => 3000])
            ->assertStatus(422)->assertJsonPath('code', 'commission_deduction_exceeds');
        $this->assertSame(0, PolicyPayment::query()->where('policy_id', $p->id)->count());
    }

    public function test_voiding_removes_the_money_reverses_unpaid_commission_and_allows_a_clean_rerecord(): void
    {
        $p = $this->mgmPolicy([], true);
        $id = $this->pay($p, 20000)->assertCreated()->json('data.0.id');
        $this->assertSame(3, CommissionLedger::query()->where('policy_payment_id', $id)->where('status', 'unsettled')->count());

        $this->postJson("/api/v1/policies/{$p->id}/payments/{$id}/void", ['reason' => 'ลูกค้าโอนผิดบัญชี'])->assertOk()
            ->assertJsonPath('data.voidReason', 'ลูกค้าโอนผิดบัญชี')
            ->assertJsonPath('meta.paid', 0)
            ->assertJsonPath('meta.status', 'unpaid');

        $this->assertSame(3, CommissionLedger::query()->where('policy_payment_id', $id)->where('status', 'reversed')->count());
        $this->assertSame('pending', $p->installments()->first()->status);
        $this->assertSame(1, PolicyEvent::query()->where('policy_id', $p->id)->where('type', 'premiumPaymentVoided')->count());
        $this->assertNotNull(PolicyPayment::withoutGlobalScopes()->find($id));

        $again = $this->pay($p, 20000)->assertCreated()->json('data.0.id');
        $fresh = CommissionLedger::query()->where('policy_payment_id', $again)->where('payout_type', 'DIRECT_COMMISSION')->firstOrFail();
        $this->assertEquals(20000, (float) $fresh->base_premium);
    }

    public function test_a_void_needs_a_reason_and_cannot_be_repeated(): void
    {
        $p = $this->makePolicy();
        $id = $this->pay($p, 1000)->assertCreated()->json('data.0.id');

        $this->postJson("/api/v1/policies/{$p->id}/payments/{$id}/void", [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/v1/policies/{$p->id}/payments/{$id}/void", ['reason' => 'ซ้ำกับรายการอื่น'])->assertOk();
        $this->postJson("/api/v1/policies/{$p->id}/payments/{$id}/void", ['reason' => 'ซ้ำกับรายการอื่น'])
            ->assertStatus(409)->assertJsonPath('code', 'payment_already_voided');
    }

    public function test_voided_payments_do_not_count_in_collections_volume_or_the_report(): void
    {
        $p = $this->mgmPolicy(['main_premium' => 20000, 'total_premium_paid' => 20000]);
        $id = $this->pay($p, 20000)->assertCreated()->json('data.0.id');
        $this->assertSame([], $this->getJson('/api/v1/collections?q='.$p->policy_no)->json('data'));

        $this->postJson("/api/v1/policies/{$p->id}/payments/{$id}/void", ['reason' => 'บันทึกผิดกรมธรรม์'])->assertOk();

        $rows = $this->getJson('/api/v1/collections?q='.$p->policy_no)->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(20000, $rows[0]['outstanding']);
        $this->assertEquals(0, (float) \DB::table('member_volume_accumulations')->where('agent_id', $p->writing_agent_id)->sum('personal_sales_volume'));
        $report = $this->getJson('/api/v1/reports/payment-history?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())->json();
        $this->assertSame([], $report['data']);
    }

    public function test_the_old_hard_delete_route_is_gone(): void
    {
        $p = $this->makePolicy();
        $id = $this->pay($p, 1000)->assertCreated()->json('data.0.id');

        $this->deleteJson("/api/v1/policies/{$p->id}/payments/{$id}")->assertNotFound();
        $this->assertNotNull(PolicyPayment::query()->find($id));
    }
}
