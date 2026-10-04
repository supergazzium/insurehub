<?php

declare(strict_types=1);

namespace Tests\Feature\Policy;

use App\Models\AuditEntry;
use App\Models\Policy;
use App\Models\PolicyDocument;
use App\Models\PolicyEvent;
use App\Models\ProductCommissionRate;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class RenewalFlowTest extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    public function test_renewal_draft_copies_clears_and_recomputes(): void
    {
        $source = $this->makePolicy(['policy_year' => 2, 'customer_year' => 3, 'status' => 'expired',
            'effective_date' => '2025-09-23', 'expiry_date' => '2026-09-23', 'approval_ref' => 'OLD-REF', 'comm_hub_to_agent_rate' => 0.1]);
        $this->actingAsStaff();

        $res = $this->getJson("/api/v1/policies/{$source->id}/renewal-draft")->assertOk();

        $res->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.newOrRenew', 'renew')
            ->assertJsonPath('data.refAppToId', (string) $source->id)
            ->assertJsonPath('data.policyYear', 3)
            ->assertJsonPath('data.customerYear', 4)
            ->assertJsonPath('data.applicationNo', null)
            ->assertJsonPath('data.policyNo', null)
            ->assertJsonPath('data.approvalRef', '')
            ->assertJsonPath('data.effectiveDate', '2026-09-23')
            ->assertJsonPath('data.expiryDate', '2027-09-23')
            ->assertJsonPath('data.coverage', 500000)
            ->assertJsonPath('data.mainPremium', 12000)
            ->assertJsonPath('data.commission.hubToAgent.rate', null)
            ->assertJsonPath('data.commissionSnapshot.frozen', false)
            ->assertJsonPath('data.renewedFrom.id', (string) $source->id)
            ->assertJsonPath('data.periodLabel', '3# 1/1')
            ->assertJsonPath('meta.quoteUsed', false)
            ->assertJsonPath('meta.source.policyYear', 2);
        $this->assertContains('netPremium', $res->json('meta.mustConfirm'));
        $this->assertSame(0, Policy::query()->where('ref_app_to_id', $source->id)->count(), 'GET must not create anything');
    }

    public function test_renewal_draft_prefers_the_renewal_quotation_figures(): void
    {
        $source = $this->makePolicy();
        $doc = PolicyDocument::create(['policy_id' => $source->id, 'type' => 'renewal_quote_insurehub', 'file_name' => 'q.pdf', 'file_path' => 'x/q.pdf', 'uploaded_at' => now()]);
        PolicyEvent::create(['policy_id' => $source->id, 'type' => 'renewalQuotePrepared', 'occurred_at' => now(),
            'payload' => ['documentId' => (string) $doc->id, 'quote' => ['annualPremium' => 13500, 'coverage' => 450000, 'premiumMode' => 'annual']]]);
        $this->actingAsStaff();

        $this->getJson("/api/v1/policies/{$source->id}/renewal-draft")->assertOk()
            ->assertJsonPath('meta.quoteUsed', true)
            ->assertJsonPath('data.mainPremium', 13500)
            ->assertJsonPath('data.coverage', 450000)
            ->assertJsonPath('data.premium.net', null);

        $doc->delete();
        $this->getJson("/api/v1/policies/{$source->id}/renewal-draft")->assertOk()
            ->assertJsonPath('meta.quoteUsed', false)
            ->assertJsonPath('data.mainPremium', 12000);
    }

    public function test_renewal_draft_is_tenant_scoped_and_status_checked(): void
    {
        $other = Tenant::create(['slug' => 'other-'.uniqid(), 'name' => 'Other', 'active' => true]);
        $foreign = $this->makePolicy(['tenant_id' => $other->id]);
        $draft = $this->makePolicy(['status' => 'draft']);
        $this->actingAsStaff();

        $this->getJson("/api/v1/policies/{$foreign->id}/renewal-draft")->assertNotFound();
        $this->getJson("/api/v1/policies/{$draft->id}/renewal-draft")->assertStatus(422)
            ->assertJsonPath('code', 'source_not_renewable');
    }

    public function test_a_policy_can_only_be_renewed_once(): void
    {
        $source = $this->makePolicy();
        $this->actingAsStaff();

        $first = $this->postJson('/api/v1/policies/draft', $this->draftPayload($source))->assertCreated();

        $this->postJson('/api/v1/policies/draft', $this->draftPayload($source))->assertStatus(422)
            ->assertJsonPath('code', 'already_renewed')
            ->assertJsonPath('existingRenewal.id', $first->json('data.id'))
            ->assertJsonValidationErrors('refAppToId');
        $this->postJson('/api/v1/policies', $this->draftPayload($source))->assertStatus(422)
            ->assertJsonPath('code', 'already_renewed');
        $this->getJson("/api/v1/policies/{$source->id}/renewal-draft")->assertStatus(422)
            ->assertJsonPath('existingRenewal.id', $first->json('data.id'));
        $this->assertSame(1, Policy::query()->where('ref_app_to_id', $source->id)->count());
    }

    public function test_cancelled_rejected_or_deleted_renewal_releases_the_source(): void
    {
        $source = $this->makePolicy();
        $this->actingAsStaff();

        foreach (['cancelled', 'rejected', 'deleted'] as $how) {
            $id = $this->postJson('/api/v1/policies/draft', $this->draftPayload($source))->assertCreated()->json('data.id');
            $renewal = Policy::findOrFail($id);
            $how === 'deleted' ? $renewal->delete() : $renewal->update(['status' => $how]);
        }

        $this->postJson('/api/v1/policies/draft', $this->draftPayload($source))->assertCreated();
        $this->assertSame(4, PolicyEvent::query()->where('policy_id', $source->id)->where('type', 'renewalStarted')->count());
    }

    public function test_reviving_a_dead_renewal_while_another_is_live_is_rejected(): void
    {
        $source = $this->makePolicy();
        $dead = $this->makePolicy(['ref_app_to_id' => $source->id, 'status' => 'cancelled', 'new_or_renew' => 'renew']);
        $this->makePolicy(['ref_app_to_id' => $source->id, 'status' => 'draft', 'new_or_renew' => 'renew']);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/policies/{$dead->id}", ['status' => 'draft'])->assertStatus(422)
            ->assertJsonPath('code', 'already_renewed');
        $this->assertSame('cancelled', $dead->fresh()->status);
    }

    public function test_resaving_a_renewal_draft_keeps_its_link(): void
    {
        $source = $this->makePolicy();
        $this->actingAsStaff();
        $id = $this->postJson('/api/v1/policies/draft', $this->draftPayload($source))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/policies/{$id}/draft", $this->draftPayload($source, ['notes' => 'แก้ไข']))->assertOk()
            ->assertJsonPath('data.refAppToId', (string) $source->id);
        $this->patchJson("/api/v1/policies/{$id}/draft", ['notes' => 'อีกครั้ง'])->assertOk();
        $this->getJson("/api/v1/policies/{$id}")->assertOk()
            ->assertJsonPath('data.refAppToId', (string) $source->id)
            ->assertJsonPath('data.renewedFrom.id', (string) $source->id);
        $this->getJson("/api/v1/policies/{$source->id}")->assertOk()
            ->assertJsonPath('data.renewedTo.id', (string) $id)
            ->assertJsonPath('data.renewedTo.live', true);
    }

    public function test_years_are_computed_on_the_server_for_non_admins(): void
    {
        $source = $this->makePolicy(['policy_year' => 2, 'customer_year' => 5]);
        $this->actingAsStaff();

        $id = $this->postJson('/api/v1/policies/draft', $this->draftPayload($source, [
            'policyYear' => 9, 'customerYear' => 9, 'yearsOverride' => true,
        ]))->assertCreated()->assertJsonPath('data.policyYear', 3)->assertJsonPath('data.customerYear', 6)->json('data.id');

        $this->patchJson("/api/v1/policies/{$id}/section/dates", ['policyYear' => 7])->assertOk()
            ->assertJsonPath('data.policyYear', 3);
        $this->assertFalse((bool) Policy::findOrFail($id)->years_overridden);
    }

    public function test_admin_override_is_kept_and_audited(): void
    {
        $source = $this->makePolicy(['policy_year' => 2, 'customer_year' => 5]);
        $this->actingAsAdmin();
        $id = $this->postJson('/api/v1/policies/draft', $this->draftPayload($source, [
            'policyYear' => 5, 'customerYear' => 8, 'yearsOverride' => true,
        ]))->assertCreated()->assertJsonPath('data.policyYear', 5)->assertJsonPath('data.yearsOverridden', true)->json('data.id');

        $audit = AuditEntry::query()->where('action', 'policy.years_overridden')->where('target', 'policy:'.$id)->first();
        $this->assertNotNull($audit);
        $this->assertSame(3, $audit->metadata['suggested']['policy_year']);
        $this->assertSame(5, $audit->metadata['chosen']['policy_year']);

        $this->actingAsStaff();
        $this->patchJson("/api/v1/policies/{$id}/draft", $this->draftPayload($source, ['notes' => 'staff edit']))->assertOk()
            ->assertJsonPath('data.policyYear', 5)
            ->assertJsonPath('data.customerYear', 8);

        $this->patchJson("/api/v1/policies/{$id}/draft", $this->draftPayload($source, [
            'productId' => (string) $this->productB->id, 'carrierId' => (string) $this->carrierB->id,
        ]))->assertOk()
            ->assertJsonPath('data.policyYear', 1)
            ->assertJsonPath('data.customerYear', 6)
            ->assertJsonPath('data.yearsOverridden', false);
    }

    public function test_changing_carrier_counts_as_a_product_change(): void
    {
        $source = $this->makePolicy(['policy_year' => 4, 'customer_year' => 4]);
        $this->actingAsStaff();

        $this->postJson('/api/v1/policies/draft', $this->draftPayload($source, [
            'productId' => (string) $this->productB->id, 'carrierId' => (string) $this->carrierB->id,
        ]))->assertCreated()
            ->assertJsonPath('data.policyYear', 1)
            ->assertJsonPath('data.customerYear', 5);
    }

    public function test_new_customer_policy_starts_at_year_one(): void
    {
        $this->actingAsStaff();
        $payload = $this->draftPayload($this->makePolicy(['status' => 'expired']), ['policyYear' => 4, 'customerYear' => 4]);
        unset($payload['refAppToId']);
        $payload['newOrRenew'] = 'new';
        $payload['customerId'] = (string) \App\Models\Customer::create([
            'tenant_id' => $this->tenant->id, 'customer_code' => 'C-NEW', 'customer_type' => 'individual',
            'first_name' => 'ใหม่', 'last_name' => 'ลูกค้า', 'active' => true,
        ])->id;

        $this->postJson('/api/v1/policies/draft', $payload)->assertCreated()
            ->assertJsonPath('data.policyYear', 1)
            ->assertJsonPath('data.customerYear', 1);
    }

    public function test_commission_snapshot_uses_the_renewal_year(): void
    {
        $life = $this->makeProduct('LIFE', $this->carrierA, ['type' => 'life']);
        ProductCommissionRate::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $life->id, 'direction' => ProductCommissionRate::DIRECTION_HUB_TO_AGENT,
            'scheme' => ProductCommissionRate::SCHEME_LIFE_YEARS,
            'yr_1' => 0.30, 'yr_2' => 0.10, 'yr_3' => 0.05, 'yr_4' => 0.03, 'yr_5' => 0.02,
        ]);
        $source = $this->makePolicy(['product_id' => $life->id, 'policy_year' => 2, 'comm_hub_to_agent_rate' => 0.10]);
        $this->actingAsStaff();

        $id = $this->postJson('/api/v1/policies/draft', $this->draftPayload($source, ['productId' => (string) $life->id]))
            ->assertCreated()->assertJsonPath('data.policyYear', 3)->json('data.id');

        $this->assertEqualsWithDelta(0.05, (float) Policy::findOrFail($id)->comm_hub_to_agent_rate, 0.00001);
    }

    public function test_renewable_filter_only_lists_policies_that_can_still_be_renewed(): void
    {
        $open = $this->makePolicy(['status' => 'expired']);
        $taken = $this->makePolicy();
        $this->makePolicy(['ref_app_to_id' => $taken->id, 'status' => 'draft']);
        $released = $this->makePolicy();
        $this->makePolicy(['ref_app_to_id' => $released->id, 'status' => 'cancelled']);
        $notYet = $this->makePolicy(['status' => 'submitted']);
        $this->actingAsStaff();

        $ids = collect($this->getJson('/api/v1/policies?renewable=1&perPage=50')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains((string) $open->id, $ids);
        $this->assertContains((string) $released->id, $ids);
        $this->assertNotContains((string) $taken->id, $ids);
        $this->assertNotContains((string) $notYet->id, $ids);
    }

    public function test_search_matches_full_name_plate_and_agent_code(): void
    {
        $p = $this->makePolicy(['motor_license_no' => 'ฆค 2002 นนทบุรี']);
        $this->actingAsStaff();

        foreach (['สมชาย ทดสอบ', '2002', 'AG001', '1100000000001'] as $q) {
            $ids = collect($this->getJson('/api/v1/policies?renewable=1&q='.urlencode($q))->assertOk()->json('data'))->pluck('id')->all();
            $this->assertContains((string) $p->id, $ids, $q);
        }
    }

    public function test_expiring_stage_reverts_when_the_renewal_is_cancelled(): void
    {
        $source = $this->makePolicy();
        $this->actingAsStaff();
        $stage = fn () => collect($this->getJson('/api/v1/reports/expiring-soon?days=60')->assertOk()->json('data'))
            ->firstWhere('policyId', (string) $source->id)['renewalStage'];

        $this->assertSame('not_started', $stage());
        $id = $this->postJson('/api/v1/policies/draft', $this->draftPayload($source))->assertCreated()->json('data.id');
        $this->assertSame('renewed', $stage());
        Policy::findOrFail($id)->update(['status' => 'cancelled']);
        $this->assertSame('not_started', $stage());
    }

    public function test_submitting_a_renewal_requires_confirming_the_new_terms(): void
    {
        $source = $this->makePolicy();
        $this->actingAsStaff();
        $id = $this->postJson('/api/v1/policies/draft', $this->draftPayload($source))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/policies/{$id}/promote-to-submitted")->assertStatus(422)
            ->assertJsonPath('code', 'renewal_terms_unconfirmed');
        $this->postJson("/api/v1/policies/{$id}/promote-to-submitted", ['renewalTermsConfirmed' => true])->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->assertTrue(PolicyEvent::query()->where('policy_id', $id)->where('type', 'renewalTermsConfirmed')->exists());
    }

    public function test_period_label_follows_installments_and_endorsements(): void
    {
        $p = $this->makePolicy(['premium_mode' => 'monthly', 'installment_term' => '12', 'effective_date' => now()->subMonths(3)->toDateString()]);
        $this->assertSame(12, $p->installments()->count());
        $first = $p->installments()->get()->take(2);
        foreach ($first as $i) {
            $i->update(['amount_paid' => $i->amount_due, 'status' => 'paid']);
        }
        $this->actingAsStaff();

        $this->getJson("/api/v1/policies/{$p->id}")->assertOk()->assertJsonPath('data.periodLabel', '1# 3/12');
        PolicyEvent::create(['policy_id' => $p->id, 'type' => 'endorsement.other', 'endorsement_seq' => 1, 'occurred_at' => now()->subDay(), 'payload' => []]);
        PolicyEvent::create(['policy_id' => $p->id, 'type' => 'endorsement.coverage_change', 'endorsement_seq' => 2, 'occurred_at' => now(), 'payload' => []]);
        $this->getJson("/api/v1/policies/{$p->id}")->assertOk()->assertJsonPath('data.periodLabel', '1# $2 3/12');

        $row = collect($this->getJson('/api/v1/policies?perPage=50')->json('data'))->firstWhere('id', (string) $p->id);
        $this->assertSame('1# $2 3/12', $row['periodLabel']);
        $inst = $this->getJson("/api/v1/policies/{$p->id}/installments")->assertOk()->json('data.0.periodLabel');
        $this->assertSame('1# 1/12', $inst);
    }
}
