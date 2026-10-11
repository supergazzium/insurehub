<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Models\CommissionReceivable;
use App\Models\Customer;
use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Commission\CommissionPayoutService;
use App\Services\Commission\PayoutEligibility;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class CommissionReceiptV2Test extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    private const LIST = '/api/v1/commission-receivables';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacPermissionSeeder::class);
        Cache::flush();
        $this->buildFixtures();
    }

    private function roleUser(string $roleKey): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => $roleKey.'-'.uniqid(), 'email' => $roleKey.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'), 'role' => $roleKey,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'locale' => 'th', 'active' => true,
        ]);
    }

    private function commPolicy(array $attrs = []): Policy
    {
        return $this->makePolicy(array_merge([
            'effective_date' => '2026-09-10',
            'expiry_date' => '2027-09-10',
            'net_premium' => 12000,
            'comm_carrier_to_hub_rate' => 0.15,
            'comm_carrier_to_hub_amount' => 1800,
        ], $attrs));
    }

    private function list(array $query = []): array
    {
        $res = $this->getJson(self::LIST.'?'.http_build_query(array_merge(['insurerId' => $this->carrierA->id, 'type' => 'MAIN_ADJ'], $query)));
        $res->assertOk();

        return $res->json('data');
    }

    private function main(Policy $p): CommissionReceivable
    {
        return CommissionReceivable::query()->where('policy_id', $p->id)->where('commission_type', 'MAIN')->where('line_no', 0)->firstOrFail();
    }

    public function test_list_requires_insurer_and_pulls_policies_by_effective_month_with_outstanding_and_cancelled(): void
    {
        $this->actingAsAdmin();
        $sept = $this->commPolicy(['policy_no' => 'SEP1']);
        $aug = $this->commPolicy(['policy_no' => 'AUG1', 'effective_date' => '2026-08-05']);
        $cancelled = $this->commPolicy(['policy_no' => 'CAN1', 'effective_date' => '2026-09-20', 'status' => 'cancelled', 'cancel_date' => '2026-09-25']);
        $this->commPolicy(['policy_no' => 'DRF1', 'effective_date' => '2026-09-01', 'status' => 'draft']);
        $this->commPolicy(['policy_no' => 'OCT1', 'effective_date' => '2026-10-02']);
        $this->commPolicy(['policy_no' => 'OTH1', 'carrier_id' => $this->carrierB->id, 'product_id' => $this->productB->id]);

        $this->getJson(self::LIST.'?type=MAIN')->assertStatus(422);

        $rows = $this->list(['monthFrom' => '2026-09', 'monthTo' => '2026-09']);
        $this->assertSame(['AUG1', 'SEP1', 'CAN1'], array_column($rows, 'policyNo'));
        $this->assertSame(1800.0, (float) $rows[1]['expectedAmount']);
        $can = collect($rows)->firstWhere('policyNo', 'CAN1');
        $this->assertContains('cancellation', array_column($can['hints'], 'code'));

        $this->list(['monthFrom' => '2026-09', 'monthTo' => '2026-09']);
        $this->assertSame(3, CommissionReceivable::query()->where('commission_type', 'MAIN')->count());
        $this->assertSame(0, CommissionReceivable::query()->where('commission_type', 'OV')->count());

        $only = $this->list(['monthFrom' => '2026-09', 'monthTo' => '2026-09', 'includeOutstanding' => 0]);
        $this->assertSame(['SEP1', 'CAN1'], array_column($only, 'policyNo'));
        $this->assertSame($sept->id, (int) $rows[1]['policyId']);
        $this->assertSame($aug->id, (int) $rows[0]['policyId']);
        $this->assertSame($cancelled->id, (int) $can['policyId']);
    }

    public function test_legacy_complete_policy_is_received_and_opens_the_insurer_gate_but_is_kept_out_of_payouts(): void
    {
        $this->actingAsAdmin();
        $this->tenant->update(['payout_require_insurer_received' => true]);
        $legacy = $this->commPolicy([
            'policy_no' => 'LEG1', 'imported_from_legacy' => true, 'com_rec_check' => 'Complete',
            'comm_hub_to_agent_amount' => 900, 'comm_hub_to_agent_rate' => 0.075,
        ]);
        $web = $this->commPolicy(['policy_no' => 'WEB1', 'comm_hub_to_agent_amount' => 900, 'comm_hub_to_agent_rate' => 0.075]);

        $this->assertSame([], $this->list(['monthFrom' => '2026-09', 'monthTo' => '2026-09', 'includeOutstanding' => 0, 'q' => 'LEG1']));
        $rec = $this->main($legacy);
        $this->assertSame('Received', $rec->status);
        $this->assertSame('legacy', $rec->source);
        $this->assertSame(1800.0, (float) $rec->received_amount);

        $gates = app(PayoutEligibility::class)->evaluate($this->tenant->id, [$legacy->id, $web->id]);
        $this->assertNotContains('insurer_not_received', array_column($gates[$legacy->id]['gates'], 'code'));
        $this->assertContains('insurer_not_received', array_column($gates[$web->id]['gates'], 'code'));

        $this->tenant->update(['payout_require_insurer_received' => false]);
        $lines = app(CommissionPayoutService::class)->collectLines($this->tenant->id);
        $policyIds = array_map(fn ($l) => (int) $l['policyId'], array_merge($lines['ready'], $lines['waiting']));
        $this->assertNotContains($legacy->id, $policyIds);
        $this->assertContains($web->id, $policyIds);
    }

    public function test_renewal_of_a_legacy_policy_is_not_flagged_as_legacy(): void
    {
        $this->actingAsAdmin();
        $legacy = $this->commPolicy(['imported_from_legacy' => true, 'com_rec_check' => 'Complete', 'recorded_by_username' => 'olduser']);

        $id = $this->postJson('/api/v1/policies/draft', $this->draftPayload($legacy))->assertCreated()->json('data.id');

        $draft = Policy::query()->findOrFail((int) $id);
        $this->assertFalse($draft->imported_from_legacy);
        $this->assertNull($draft->recorded_by_username);
    }

    public function test_review_without_note_is_mismatch_and_receiving_a_difference_needs_a_reason(): void
    {
        $this->actingAsAdmin();
        $p = $this->commPolicy();
        $this->list(['monthTo' => '2026-09']);
        $rec = $this->main($p);

        $this->postJson(self::LIST."/{$rec->id}/receive", ['receivedDate' => '2026-10-01', 'version' => $rec->version])
            ->assertStatus(422)->assertJsonPath('message', 'กรุณาระบุยอดที่ได้รับจริง');

        $this->patchJson(self::LIST."/{$rec->id}/review", ['statementAmount' => 1500, 'version' => $rec->version])
            ->assertOk()->assertJsonPath('data.status', 'Mismatch');
        $rec->refresh();

        $this->postJson(self::LIST."/{$rec->id}/receive", ['receivedDate' => '2026-10-01', 'version' => $rec->version])
            ->assertStatus(422);
        $this->postJson(self::LIST."/{$rec->id}/receive", ['receivedDate' => '2026-10-01', 'diffReason' => 'other', 'version' => $rec->version])
            ->assertStatus(422);
        $this->postJson(self::LIST."/{$rec->id}/receive", ['receivedDate' => '2026-10-01', 'diffReason' => 'rate', 'diffNote' => 'บริษัทใช้อัตรา 12.5%', 'version' => $rec->version])
            ->assertOk()
            ->assertJsonPath('data.status', 'Received')
            ->assertJsonPath('data.receivedAmount', 1500)
            ->assertJsonPath('data.diffReason', 'rate');

        $this->assertSame(1, DB::table('audit_entries')->where('target', "receivable:{$rec->id}")->where('action', 'receivable.receive')->count());
    }

    public function test_bulk_review_and_bulk_receive_link_the_statement_batch_and_are_all_or_nothing(): void
    {
        $this->actingAsAdmin();
        $p1 = $this->commPolicy();
        $p2 = $this->commPolicy();
        $this->list(['monthTo' => '2026-09']);
        [$r1, $r2] = [$this->main($p1), $this->main($p2)];

        $batchId = (int) $this->postJson('/api/v1/commission-receipt-batches', [
            'insurerId' => $this->carrierA->id, 'statementMonth' => '2026-09', 'statementTotal' => 3600,
        ])->assertCreated()->json('data.id');
        $otherBatch = (int) $this->postJson('/api/v1/commission-receipt-batches', ['insurerId' => $this->carrierB->id])->json('data.id');

        $this->postJson(self::LIST.'/bulk-review', ['items' => [
            ['id' => $r1->id, 'statementAmount' => 1800, 'version' => $r1->version],
            ['id' => $r2->id, 'statementAmount' => 1800, 'version' => $r2->version],
        ]])->assertOk()->assertJsonPath('data.0.status', 'Matched');
        [$r1, $r2] = [$r1->fresh(), $r2->fresh()];

        $this->postJson(self::LIST.'/bulk-receive', ['receivedDate' => '2026-10-02', 'receiptBatchId' => $batchId, 'items' => [
            ['id' => $r1->id, 'version' => $r1->version],
            ['id' => $r2->id, 'version' => $r2->version - 1],
        ]])->assertStatus(422)->assertJsonCount(1, 'errors');
        $this->assertSame('Matched', $r1->fresh()->status);

        $this->postJson(self::LIST.'/bulk-receive', ['receivedDate' => '2026-10-02', 'receiptBatchId' => $otherBatch, 'items' => [
            ['id' => $r1->id, 'version' => $r1->version],
        ]])->assertStatus(422);

        $this->postJson(self::LIST.'/bulk-receive', ['receivedDate' => '2026-10-02', 'receiptBatchId' => $batchId, 'items' => [
            ['id' => $r1->id, 'version' => $r1->version],
            ['id' => $r2->id, 'version' => $r2->version],
        ]])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame('Received', $r2->fresh()->status);
        $this->assertSame($batchId, (int) $r2->fresh()->receipt_batch_id);
        $this->getJson("/api/v1/commission-receipt-batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.receivedCount', 2)
            ->assertJsonPath('data.receivedTotal', 3600)
            ->assertJsonPath('data.difference', 0);
    }

    public function test_statement_match_finds_policies_by_number_and_reports_problems(): void
    {
        $this->actingAsAdmin();
        $a = $this->commPolicy(['policy_no' => 'POL-77/1', 'application_no' => 'APP-77']);
        $b = $this->commPolicy(['policy_no' => 'POL-88', 'application_no' => 'APP-88']);
        $this->commPolicy(['policy_no' => 'OTH-1', 'carrier_id' => $this->carrierB->id, 'product_id' => $this->productB->id]);
        $closed = $this->commPolicy(['policy_no' => 'DONE-1', 'imported_from_legacy' => true, 'com_rec_check' => 'Complete']);

        $data = $this->postJson(self::LIST.'/statement-match', [
            'insurerId' => $this->carrierA->id,
            'lines' => [
                ['ref' => 'POL 77 1', 'amount' => 1800],
                ['ref' => 'app88', 'amount' => 1700],
                ['ref' => 'NOPE-404', 'amount' => 10],
                ['ref' => 'OTH-1', 'amount' => 10],
                ['ref' => 'DONE-1', 'amount' => 1800],
                ['ref' => 'POL-77/1', 'amount' => 1800],
            ],
        ])->assertOk()->json('data');

        $this->assertSame([$a->id, $b->id], array_map('intval', array_column($data['matched'], 'policyId')));
        $this->assertSame(-100.0, (float) $data['matched'][1]['difference']);
        $this->assertSame(['NOPE-404'], array_column($data['notFound'], 'ref'));
        $this->assertSame('Carrier B', $data['otherInsurer'][0]['insurerName']);
        $this->assertSame((string) $closed->id, $data['alreadyClosed'][0]['policyId']);
        $this->assertSame(['POL-77/1'], array_column($data['duplicates'], 'ref'));
    }

    public function test_adjustment_lines_allow_negative_clawbacks_and_tick_the_carrier_deduct_check(): void
    {
        $this->actingAsAdmin();
        $p = $this->commPolicy(['status' => 'cancelled', 'cancel_date' => '2026-09-30', 'cancel_check_carrier_deduct' => 'pending']);
        $this->list(['monthTo' => '2026-09']);
        $main = $this->main($p);
        $this->patchJson(self::LIST."/{$main->id}/review", ['statementAmount' => -5, 'version' => $main->version])->assertStatus(422);
        $this->postJson(self::LIST."/{$main->id}/receive", ['receivedAmount' => 1800, 'receivedDate' => '2026-09-15', 'version' => $main->version])->assertOk();

        $this->getJson(self::LIST.'/adjustments/suggest?'.http_build_query(['policyId' => $p->id, 'kind' => 'cancellation']))
            ->assertOk()->assertJsonPath('data.amount', -1800);

        $this->postJson(self::LIST.'/adjustments', ['policyId' => $p->id, 'kind' => 'cancellation', 'expectedAmount' => 100])->assertStatus(422);
        $this->postJson(self::LIST.'/adjustments', ['policyId' => $p->id, 'kind' => 'other', 'expectedAmount' => 50])->assertStatus(422);
        $adj = $this->postJson(self::LIST.'/adjustments', ['policyId' => $p->id, 'kind' => 'cancellation', 'expectedAmount' => -1800])
            ->assertCreated()->assertJsonPath('data.lineNo', 1)->json('data');
        $this->postJson(self::LIST.'/adjustments', ['policyId' => $p->id, 'kind' => 'other', 'expectedAmount' => 50, 'note' => 'ค่าบริการพิเศษ'])
            ->assertCreated()->assertJsonPath('data.lineNo', 2);

        $this->postJson(self::LIST."/{$adj['id']}/receive", ['receivedAmount' => 200, 'receivedDate' => '2026-10-03', 'version' => $adj['version']])->assertStatus(422);
        $this->postJson(self::LIST."/{$adj['id']}/receive", ['receivedAmount' => -1800, 'receivedDate' => '2026-10-03', 'version' => $adj['version']])
            ->assertOk()->assertJsonPath('data.status', 'Received');

        $this->assertSame('done', $p->fresh()->cancel_check_carrier_deduct);
        $this->assertSame(3, CommissionReceivable::query()->where('policy_id', $p->id)->count());
        $show = $this->getJson(self::LIST."/{$main->id}")->assertOk()->json('rows');
        $this->assertSame(['ADJ', 'ADJ', 'MAIN'], array_column($show, 'commissionType'));
    }

    public function test_endorsement_suggestion_and_hint_use_the_carrier_rate_and_skip_voided_endorsements(): void
    {
        $this->actingAsAdmin();
        $p = $this->commPolicy();
        $ok = PolicyEvent::create(['policy_id' => $p->id, 'type' => 'endorsement.premium_change', 'endorsement_seq' => 1, 'occurred_at' => now(), 'payload' => ['additionalPremium' => 1000, 'additionalTotal' => 1077]]);
        PolicyEvent::create(['policy_id' => $p->id, 'type' => 'endorsement.premium_change', 'endorsement_seq' => 2, 'occurred_at' => now(), 'voided_at' => now(), 'payload' => ['additionalPremium' => 500, 'additionalTotal' => 535]]);

        $s = $this->getJson(self::LIST.'/adjustments/suggest?'.http_build_query(['policyId' => $p->id, 'kind' => 'endorsement']))->assertOk()->json('data');
        $this->assertSame(150, (int) $s['amount']);
        $this->assertCount(1, $s['events']);

        $row = $this->list(['monthTo' => '2026-09'])[0];
        $hint = collect($row['hints'])->firstWhere('code', 'endorsement');
        $this->assertSame(150.0, (float) $hint['estimate']);

        $this->postJson(self::LIST.'/adjustments', ['policyId' => $p->id, 'kind' => 'endorsement', 'expectedAmount' => 150, 'policyEventId' => $ok->id])->assertCreated();
        $this->postJson(self::LIST.'/adjustments', ['policyId' => $p->id, 'kind' => 'endorsement', 'expectedAmount' => 150, 'policyEventId' => $ok->id])->assertStatus(422);
    }

    public function test_reopen_needs_its_own_permission_a_reason_and_clears_the_receipt(): void
    {
        $p = $this->commPolicy();
        Sanctum::actingAs($this->admin);
        $this->list(['monthTo' => '2026-09']);
        $rec = $this->main($p);
        $this->patchJson(self::LIST."/{$rec->id}/review", ['statementAmount' => 1800, 'version' => $rec->version])->assertOk();
        $rec->refresh();
        $this->postJson(self::LIST."/{$rec->id}/receive", ['receivedDate' => '2026-10-01', 'version' => $rec->version])->assertOk();
        $rec->refresh();

        Sanctum::actingAs($this->roleUser('executive'));
        $this->postJson(self::LIST."/{$rec->id}/reopen", ['reason' => 'x', 'version' => $rec->version])->assertForbidden();

        Sanctum::actingAs($this->roleUser('finance'));
        $this->postJson(self::LIST."/{$rec->id}/reopen", ['reason' => '', 'version' => $rec->version])->assertStatus(422);
        $this->postJson(self::LIST."/{$rec->id}/reopen", ['reason' => 'บริษัทแจ้งยอดใหม่', 'version' => $rec->version])
            ->assertOk()
            ->assertJsonPath('data.status', 'Matched')
            ->assertJsonPath('data.receivedAmount', null)
            ->assertJsonPath('data.receivedDate', null);
    }

    public function test_permissions_view_record_and_reopen_are_enforced(): void
    {
        $p = $this->commPolicy();
        Sanctum::actingAs($this->admin);
        $this->list(['monthTo' => '2026-09']);
        $rec = $this->main($p);

        Sanctum::actingAs($this->roleUser('sales_support'));
        $this->getJson(self::LIST.'?all=1')->assertForbidden();
        Sanctum::actingAs($this->roleUser('agent'));
        $this->getJson(self::LIST.'?all=1')->assertForbidden();
        $this->postJson(self::LIST."/{$rec->id}/receive", ['receivedAmount' => 1800, 'receivedDate' => '2026-10-01'])->assertForbidden();

        Sanctum::actingAs($this->roleUser('executive'));
        $this->getJson(self::LIST.'?all=1')->assertOk();
        $this->patchJson(self::LIST."/{$rec->id}/review", ['statementAmount' => 1800])->assertForbidden();

        Sanctum::actingAs($this->roleUser('finance'));
        $this->patchJson(self::LIST."/{$rec->id}/review", ['statementAmount' => 1800])->assertOk();
    }

    public function test_export_csv_has_a_bom_and_neutralises_formulas(): void
    {
        $this->actingAsAdmin();
        $evil = Customer::create([
            'tenant_id' => $this->tenant->id, 'customer_code' => 'C666', 'customer_type' => 'individual',
            'first_name' => '=HYPERLINK("x")', 'last_name' => 'ทดสอบ', 'active' => true,
        ]);
        $this->commPolicy(['customer_id' => $evil->id, 'policy_no' => 'CSV1']);

        $res = $this->get(self::LIST.'/export?'.http_build_query(['insurerId' => $this->carrierA->id, 'monthTo' => '2026-09', 'type' => 'MAIN_ADJ']));
        $res->assertOk();
        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringContainsString('CSV1', $body);
    }

    public function test_dashboard_summarises_reasons_adjustments_and_base_only_agent_payments(): void
    {
        $this->actingAsAdmin();
        $p = $this->commPolicy(['comm_hub_to_agent_amount' => 900]);
        $this->list(['monthTo' => '2026-09']);
        $rec = $this->main($p);
        $this->postJson(self::LIST."/{$rec->id}/receive", ['receivedAmount' => 1700, 'receivedDate' => '2026-10-01', 'diffReason' => 'rate', 'version' => $rec->version])->assertOk();
        $adj = $this->postJson(self::LIST.'/adjustments', ['policyId' => $p->id, 'kind' => 'other', 'expectedAmount' => 50, 'note' => 'โบนัส'])->json('data');
        $this->postJson(self::LIST."/{$adj['id']}/receive", ['receivedAmount' => 50, 'receivedDate' => '2026-10-02', 'version' => $adj['version']])->assertOk();

        $batchId = DB::table('commission_payout_batches')->insertGetId([
            'tenant_id' => $this->tenant->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-30', 'status' => 'paid',
            'calc_version' => 2, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['base', 900], ['referral', 120]] as [$type, $amount]) {
            DB::table('commission_payout_batch_items')->insert([
                'batch_id' => $batchId, 'tenant_id' => $this->tenant->id, 'policy_id' => $p->id, 'line_type' => $type,
                'agent_id' => $this->agent->id, 'snapshot_base_premium' => 12000, 'snapshot_agent_commission' => $amount,
                'snapshot_rider_commission' => 0, 'condition_override' => false, 'item_status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $d = $this->getJson('/api/v1/commission-reconciliation/dashboard?'.http_build_query(['insurerId' => $this->carrierA->id]))->assertOk()->json('data');
        $this->assertSame(1700.0, (float) $d['kpi']['receivedInsurer']);
        $this->assertSame(50.0, (float) $d['kpi']['adjustmentReceived']);
        $this->assertSame(900.0, (float) $d['kpi']['agentPaid']);
        $this->assertSame(120.0, (float) $d['kpi']['agentMgmPaid']);
        $this->assertSame('rate', $d['mismatchByReason'][0]['reason']);
        $this->assertSame(-100.0, (float) $d['mismatchByReason'][0]['difference']);
        $this->assertSame('Received', $d['policyLevel'][0]['mainStatus']);
        $this->assertNull($d['policyLevel'][0]['ovStatus']);
        $this->assertSame(1, $d['policyLevel'][0]['adjustments']);
    }
}
