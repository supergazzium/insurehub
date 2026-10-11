<?php

declare(strict_types=1);

namespace Tests\Feature\Policy;

use App\Models\AuditEntry;
use App\Models\CommissionPayoutAdjustment;
use App\Models\Policy;
use App\Models\PolicyInstallment;
use App\Services\Commission\CommissionPayoutService;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class FollowUpModeTest extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->seed(RbacPermissionSeeder::class);
        Cache::flush();
        $this->buildFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function overdue(Policy $policy, array $rows): void
    {
        PolicyInstallment::query()->where('policy_id', $policy->id)->delete();
        foreach ($rows as $i => [$due, $amount, $status]) {
            PolicyInstallment::create([
                'tenant_id' => $this->tenant->id, 'policy_id' => $policy->id, 'installment_no' => $i + 1,
                'installment_total' => count($rows), 'due_date' => $due, 'amount_due' => $amount,
                'amount_paid' => $status === 'paid' ? $amount : 0, 'status' => $status,
            ]);
        }
    }

    private function followUps(array $query)
    {
        return $this->getJson('/api/v1/follow-ups?'.http_build_query($query))->assertOk();
    }

    public function test_unpaid_category_lists_overdue_installments_with_aging_buckets(): void
    {
        $recent = $this->makePolicy();
        $old = $this->makePolicy();
        $paid = $this->makePolicy();
        $this->overdue($recent, [['2026-10-12', 1000, 'pending'], ['2026-11-12', 1000, 'pending']]);
        $this->overdue($old, [['2026-08-31', 800, 'partial'], ['2026-09-30', 800, 'pending']]);
        $this->overdue($paid, [['2026-09-01', 500, 'paid']]);
        PolicyInstallment::query()->where('policy_id', $old->id)->where('installment_no', 1)->update(['amount_paid' => 300]);

        $this->actingAsAdmin();
        $res = $this->followUps(['category' => 'unpaid']);
        $this->assertSame(2, $res->json('meta.counts.unpaid'));
        $this->assertCount(8, $res->json('meta.counts'));
        $this->assertSame(['0-7' => 1, '8-30' => 0, '30+' => 1], $res->json('meta.aging'));
        $this->assertSame((string) $old->id, $res->json('data.0.policyId'));
        $this->assertSame(45, $res->json('data.0.agingDays'));
        $this->assertEquals(1300, $res->json('data.0.overdueAmount'));
        $this->assertSame(2, $res->json('data.0.overdueCount'));
        $this->assertEquals(1000, $res->json('data.1.overdueAmount'));

        $this->followUps(['category' => 'unpaid', 'aging' => '0-7'])->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.policyId', (string) $recent->id);
    }

    public function test_not_delivered_uses_shipped_and_delivered_dates(): void
    {
        $unshipped = $this->makePolicy(['status' => 'issued']);
        $inTransit = $this->makePolicy(['shipped_date' => '2026-10-10']);
        $this->makePolicy(['shipped_date' => '2026-10-01', 'delivered_date' => '2026-10-03']);
        $legacyMailed = $this->makePolicy(['mailing_date' => '2026-09-01', 'delivered_date' => '2026-09-05']);

        $this->actingAsAdmin();
        $ids = collect($this->followUps(['category' => 'not_delivered'])->json('data'))->pluck('policyId')->all();
        $this->assertContains((string) $unshipped->id, $ids);
        $this->assertNotContains((string) $inTransit->id, $ids);
        $this->assertNotContains((string) $legacyMailed->id, $ids);
        $this->followUps(['category' => 'not_delivered', 'stage' => 'in_transit'])
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.policyId', (string) $inTransit->id)
            ->assertJsonPath('data.0.agingDays', 5);
    }

    public function test_no_commission_window_counts_from_the_effective_date(): void
    {
        $recent = $this->makePolicy(['effective_date' => '2026-09-01', 'mailing_date' => '2026-09-05', 'comm_hub_to_agent_amount' => 0]);
        $old = $this->makePolicy(['effective_date' => '2026-01-01', 'mailing_date' => '2026-01-05', 'comm_hub_to_agent_amount' => 0]);
        Policy::query()->whereKey([$recent->id, $old->id])->update(['comm_hub_to_agent_amount' => 0, 'comm_carrier_to_hub_amount' => 0]);

        $this->actingAsAdmin();
        $ids = collect($this->followUps(['category' => 'no_commission'])->json('data'))->pluck('policyId')->all();
        $this->assertSame([(string) $recent->id], $ids);

        $this->tenant->update(['no_commission_window_days' => 365]);
        $this->assertSame(2, $this->followUps(['category' => 'no_commission'])->json('meta.counts.no_commission'));
    }

    public function test_freelook_end_date_is_computed_and_documents_are_tracked(): void
    {
        $life = $this->makeProduct('LIFE1', $this->carrierA, ['type' => 'life']);
        $face = $this->makePolicy(['product_id' => $life->id, 'delivered_date' => '2026-10-01']);
        $phone = $this->makePolicy(['product_id' => $life->id, 'delivered_date' => '2026-10-01', 'sales_channel' => 'telephone']);
        $this->makePolicy(['product_id' => $life->id, 'status' => 'draft', 'delivered_date' => '2026-10-01']);
        $this->makePolicy(['product_id' => $life->id, 'status' => 'cancelled', 'delivered_date' => '2026-10-01']);
        $motor = $this->makePolicy(['delivered_date' => '2026-10-01']);

        $this->assertSame('2026-10-16', $face->fresh()->freelook_end_date->toDateString());
        $this->assertSame('2026-10-31', $phone->fresh()->freelook_end_date->toDateString());
        $this->assertNull($motor->fresh()->freelook_end_date);

        $this->tenant->update(['freelook_days_standard' => 20]);
        $face->update(['delivered_date' => '2026-10-02']);
        $this->assertSame('2026-10-22', $face->fresh()->freelook_end_date->toDateString());

        $this->actingAsAdmin();
        $res = $this->followUps(['category' => 'freelook']);
        $this->assertEqualsCanonicalizing([(string) $face->id, (string) $phone->id], collect($res->json('data'))->pluck('policyId')->all());
        $this->assertSame(2, $res->json('meta.stages.not_sent'));

        $url = "/api/v1/policies/{$face->id}/freelook";
        $this->patchJson($url, ['signedAt' => '2026-10-05'])->assertStatus(422)->assertJsonValidationErrors('signedAt');
        $this->patchJson($url, ['sentAt' => '2026-10-03'])->assertOk()->assertJsonPath('data.freelookDocSentAt', '2026-10-03');
        $this->assertSame(1, $this->followUps(['category' => 'freelook', 'stage' => 'awaiting_return'])->json('meta.stages.awaiting_return'));
        $this->patchJson($url, ['signedAt' => '2026-10-05', 'returnedAt' => '2026-10-06'])->assertOk();
        $this->assertSame([(string) $phone->id], collect($this->followUps(['category' => 'freelook'])->json('data'))->pluck('policyId')->all());
        $this->assertTrue($face->events()->where('type', 'freelookDocUpdated')->exists());
    }

    public function test_cancellation_review_requires_a_complete_checklist_and_queues_clawback(): void
    {
        $cancelled = $this->makePolicy(['status' => 'cancelled', 'cancel_date' => '2026-10-01']);
        $this->actingAsAdmin();
        $url = "/api/v1/policies/{$cancelled->id}/cancellation-review";

        $this->assertSame(1, $this->followUps(['category' => 'cancelled'])->json('meta.counts.cancelled'));
        $this->patchJson($url, ['clawbackAmount' => 500, 'checks' => ['refund' => 'done'], 'markReviewed' => true])
            ->assertStatus(422)->assertJsonValidationErrors('checks');
        $this->assertNull($cancelled->fresh()->cancellation_reviewed_at);

        $this->patchJson($url, [
            'refundAmount' => 1200, 'clawbackAmount' => 500,
            'checks' => ['refund' => 'done', 'clawback' => 'done', 'carrierDeduct' => 'na'],
            'note' => 'คืนเบี้ยผ่านบริษัทแล้ว', 'markReviewed' => true,
        ])->assertOk()->assertJsonPath('data.clawbacks.0.status', 'pending')->assertJsonPath('data.reviewedByName', $this->admin->name);

        $this->assertSame(0, $this->followUps(['category' => 'cancelled'])->json('meta.counts.cancelled'));
        $this->followUps(['category' => 'cancelled', 'reviewed' => 1])->assertJsonCount(1, 'data');
        $this->assertTrue(AuditEntry::query()->where('action', 'policy.cancellation_reviewed')->exists());

        $adj = CommissionPayoutAdjustment::query()->where('policy_id', $cancelled->id)->firstOrFail();
        $this->assertSame(CommissionPayoutAdjustment::TYPE_CLAWBACK, $adj->type);
        $this->assertSame((int) $this->agent->id, (int) $adj->agent_id);
        $this->assertEquals(500, (float) $adj->amount);
        $this->assertNull($adj->batch_id);

        $this->patchJson($url, ['clawbackAmount' => 450])->assertOk();
        $this->assertEquals(450, (float) $adj->fresh()->amount);
        $this->patchJson("/api/v1/policies/{$this->makePolicy()->id}/cancellation-review", ['markReviewed' => true])->assertStatus(422);
    }

    public function test_clawback_is_deducted_from_the_next_payout_and_carried_forward(): void
    {
        $cancelled = $this->makePolicy(['status' => 'cancelled', 'cancel_date' => '2026-10-01']);
        $this->actingAsAdmin();
        $this->patchJson("/api/v1/policies/{$cancelled->id}/cancellation-review", [
            'clawbackAmount' => 500, 'checks' => ['refund' => 'na', 'clawback' => 'done', 'carrierDeduct' => 'done'], 'markReviewed' => true,
        ])->assertOk();

        $service = app(CommissionPayoutService::class);
        $this->makePolicy(['comm_hub_to_agent_amount' => 300]);
        $detail = $service->agentDetail((int) $this->tenant->id, (int) $this->agent->id);
        $this->assertEquals(500, $detail['totals']['pendingDeduction']);
        $this->assertEquals(0, $detail['totals']['netAmount']);

        $first = $service->createBatch((int) $this->tenant->id, null, null, $this->admin->id, null, (int) $this->agent->id);
        $this->assertEquals(300, (float) $first->adjustments()->sum('amount'));
        $pdf = $service->buildAgentPdfData($first->fresh(), (int) $this->agent->id, null);
        $this->assertEquals(300, $pdf['totals']['deduct']);
        $this->assertEquals(0, $pdf['totals']['payable']);
        $this->assertEquals(200, (float) CommissionPayoutAdjustment::query()->where('status', 'pending')->sum('amount'));

        $this->patchJson("/api/v1/policies/{$cancelled->id}/cancellation-review", ['clawbackAmount' => 250])
            ->assertStatus(422)->assertJsonValidationErrors('clawbackAmount');

        $this->makePolicy(['comm_hub_to_agent_amount' => 1000]);
        $second = $service->createBatch((int) $this->tenant->id, null, null, $this->admin->id, null, (int) $this->agent->id);
        $this->assertEquals(200, (float) $second->adjustments()->sum('amount'));
        $this->assertSame(0, CommissionPayoutAdjustment::query()->where('status', 'pending')->count());

        $service->cancelBatch($second->fresh(), $this->admin->id);
        $this->assertEquals(200, (float) CommissionPayoutAdjustment::query()->where('status', 'pending')->whereNull('batch_id')->sum('amount'));
    }
}
