<?php

declare(strict_types=1);

namespace Tests\Feature\Policy;

use App\Models\AuditEntry;
use App\Models\PendingChange;
use App\Models\Policy;
use App\Models\PolicyInstallment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class PolicyVerificationTest extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacPermissionSeeder::class);
        Cache::flush();
        $this->buildFixtures();
        $this->tenant->update(['policy_verify_required' => true]);
    }

    private function userWithRole(string $roleKey): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => $roleKey.'-'.uniqid(), 'email' => $roleKey.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'), 'role' => $roleKey,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'locale' => 'th', 'active' => true,
        ]);
    }

    private function asMaker(): void
    {
        Sanctum::actingAs($this->staff);
    }

    private function asChecker(): void
    {
        Sanctum::actingAs($this->admin);
    }

    public function test_verify_off_saves_immediately(): void
    {
        $this->tenant->update(['policy_verify_required' => false]);
        $policy = $this->makePolicy();
        $this->asMaker();
        $this->patchJson("/api/v1/policies/{$policy->id}", ['annualPremium' => 15000])->assertOk();
        $this->assertEquals(15000, (float) $policy->fresh()->annual_premium);
        $this->assertSame(0, PendingChange::query()->count());
    }

    public function test_key_field_edits_wait_for_a_different_checker(): void
    {
        $policy = $this->makePolicy();
        $this->asMaker();
        $res = $this->patchJson("/api/v1/policies/{$policy->id}", ['annualPremium' => 15000, 'coverage' => 600000, 'notes' => 'ลูกค้าขอเพิ่มทุน'])
            ->assertStatus(202)
            ->assertJsonPath('meta.pendingChange.action', 'update')
            ->assertJsonPath('data.verification.pending.action', 'update');
        $changeId = $res->json('meta.pendingChange.id');

        $fresh = $policy->fresh();
        $this->assertEquals(12000, (float) $fresh->annual_premium);
        $this->assertEquals(500000, (float) $fresh->coverage);
        $this->assertSame('ลูกค้าขอเพิ่มทุน', $fresh->notes);

        $this->postJson("/api/v1/verifications/{$changeId}/approve")->assertForbidden();
        $this->grantVerifyTo('sales_support');
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/verifications/{$changeId}/approve")->assertForbidden();

        Sanctum::actingAs($this->userWithRole('operations'));
        $this->postJson("/api/v1/verifications/{$changeId}/approve")->assertForbidden();

        $this->asChecker();
        $detail = $this->getJson("/api/v1/verifications/{$changeId}")->assertOk()->json('data');
        $this->assertSame(['coverage', 'annual_premium'], array_column($detail['diff'], 'field'));
        $this->assertSame('12,000.00', $detail['diff'][1]['before']);
        $this->assertSame('15,000.00', $detail['diff'][1]['after']);

        $this->postJson("/api/v1/verifications/{$changeId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertEquals(15000, (float) $policy->fresh()->annual_premium);
        $this->assertEquals(600000, (float) $policy->fresh()->coverage);
        $this->assertTrue(AuditEntry::query()->where('action', 'policy.change_requested')->exists());
        $this->assertTrue(AuditEntry::query()->where('action', 'policy.change_approved')->where('user_id', $this->admin->id)->exists());
    }

    public function test_reject_needs_a_reason_keeps_data_and_maker_can_resubmit(): void
    {
        $policy = $this->makePolicy();
        $this->asMaker();
        $id = $this->patchJson("/api/v1/policies/{$policy->id}", ['mainPremium' => 9999])->assertStatus(202)->json('meta.pendingChange.id');

        $this->asChecker();
        $this->postJson("/api/v1/verifications/{$id}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/verifications/{$id}/reject", ['reason' => 'เบี้ยไม่ตรงใบเสนอ'])->assertOk();
        $this->assertEquals(12000, (float) $policy->fresh()->main_premium);

        $this->asMaker();
        $this->getJson("/api/v1/policies/{$policy->id}")->assertOk()
            ->assertJsonPath('data.verification.pending', null)
            ->assertJsonPath('data.verification.lastRejected.reason', 'เบี้ยไม่ตรงใบเสนอ');
        $this->getJson('/api/v1/verifications?scope=mine')->assertOk()->assertJsonPath('data.0.status', 'rejected');
        $this->patchJson("/api/v1/policies/{$policy->id}", ['mainPremium' => 12500])->assertStatus(202);
        $this->assertSame(1, PendingChange::query()->where('status', 'pending')->count());
    }

    public function test_new_policy_stays_draft_until_verified(): void
    {
        $draft = $this->makePolicy(['status' => 'draft']);
        $this->asMaker();
        $res = $this->patchJson("/api/v1/policies/{$draft->id}", ['status' => 'active', 'remark' => 'x'])->assertStatus(202)
            ->assertJsonPath('meta.pendingChange.action', 'create');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->patchJson("/api/v1/policies/{$draft->id}/draft", ['coverage' => 1])->assertStatus(409)->assertJsonPath('code', 'pending_verification');
        $this->assertSame(0, PolicyInstallment::query()->where('policy_id', $draft->id)->count());

        $this->asChecker();
        $this->postJson('/api/v1/verifications/'.$res->json('meta.pendingChange.id').'/approve')->assertOk();
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertGreaterThan(0, PolicyInstallment::query()->where('policy_id', $draft->id)->count());
    }

    public function test_only_settings_admins_can_turn_verify_off(): void
    {
        $this->asMaker();
        $this->patchJson('/api/v1/tenant', ['policyVerifyRequired' => false])->assertForbidden();
        $this->assertTrue((bool) $this->tenant->fresh()->policy_verify_required);

        $this->asChecker();
        $this->patchJson('/api/v1/tenant', ['policyVerifyRequired' => false, 'freelookDaysStandard' => 20])
            ->assertOk()->assertJsonPath('data.policyVerifyRequired', false)->assertJsonPath('data.freelookDaysStandard', 20);
    }

    public function test_status_change_through_draft_autosave_is_verified(): void
    {
        $draft = $this->makePolicy(['status' => 'draft']);
        $this->asMaker();
        $this->patchJson("/api/v1/policies/{$draft->id}/draft", ['coverage' => 700000])->assertOk();
        $this->assertEquals(700000, (float) $draft->fresh()->coverage);

        $this->patchJson("/api/v1/policies/{$draft->id}/draft", ['status' => 'active', 'coverage' => 800000])->assertStatus(202)
            ->assertJsonPath('meta.pendingChange.action', 'create');
        $fresh = $draft->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertEquals(800000, (float) $fresh->coverage);
    }

    public function test_submit_to_carrier_is_verified_and_replays_the_event(): void
    {
        $draft = $this->makePolicy(['status' => 'draft']);
        $this->asMaker();
        $id = $this->postJson("/api/v1/policies/{$draft->id}/promote-to-submitted")->assertStatus(202)->json('meta.pendingChange.id');
        $this->assertSame('draft', $draft->fresh()->status);

        $this->asChecker();
        $this->postJson("/api/v1/verifications/{$id}/approve")->assertOk();
        $fresh = $draft->fresh();
        $this->assertSame('submitted', $fresh->status);
        $event = $fresh->events()->where('type', 'submittedFromDraft')->firstOrFail();
        $this->assertSame((int) $this->staff->id, (int) $event->by_user_id);
        $this->assertSame((int) $this->admin->id, (int) $event->payload['verifiedBy']);
    }

    public function test_status_events_and_deletes_go_through_verification(): void
    {
        $policy = $this->makePolicy();
        $this->asMaker();
        $cancel = $this->postJson("/api/v1/policies/{$policy->id}/events", ['type' => 'cancelled', 'payload' => ['cancelDate' => '2026-10-01']])
            ->assertStatus(202)->json('meta.pendingChange.id');
        $this->assertSame('active', $policy->fresh()->status);
        $this->deleteJson("/api/v1/policies/{$policy->id}", ['reason' => 'ซ้ำ'])->assertStatus(409);

        $this->asChecker();
        $this->postJson("/api/v1/verifications/{$cancel}/approve")->assertOk();
        $this->assertSame('cancelled', $policy->fresh()->status);
        $this->assertSame('2026-10-01', $policy->fresh()->cancel_date->toDateString());

        $other = $this->makePolicy();
        $this->asMaker();
        $this->deleteJson("/api/v1/policies/{$other->id}")->assertStatus(422)->assertJsonValidationErrors('reason');
        $del = $this->deleteJson("/api/v1/policies/{$other->id}", ['reason' => 'คีย์ซ้ำกับ POL1'])->assertStatus(202)
            ->assertJsonPath('meta.pendingChange.action', 'delete')->json('meta.pendingChange.id');
        $this->assertNotNull(Policy::query()->find($other->id));

        $this->asChecker();
        $this->postJson("/api/v1/verifications/{$del}/approve")->assertOk();
        $this->assertNull(Policy::query()->find($other->id));
        $this->assertNotNull(Policy::withTrashed()->find($other->id)?->deleted_at);

        $draft = $this->makePolicy(['status' => 'draft']);
        $this->asMaker();
        $this->deleteJson("/api/v1/policies/{$draft->id}")->assertOk();
    }

    public function test_operational_fields_save_immediately_and_maker_can_withdraw(): void
    {
        $policy = $this->makePolicy();
        $this->asMaker();
        $this->patchJson("/api/v1/policies/{$policy->id}", ['shippedDate' => '2026-10-02', 'deliveredDate' => '2026-10-04', 'notes' => 'ส่งแล้ว'])->assertOk();
        $this->assertSame('2026-10-04', $policy->fresh()->delivered_date->toDateString());
        $this->assertSame(0, PendingChange::query()->count());

        $id = $this->patchJson("/api/v1/policies/{$policy->id}", ['policyNo' => 'NEW-123'])->assertStatus(202)->json('meta.pendingChange.id');
        Sanctum::actingAs($this->userWithRole('operations'));
        $this->postJson("/api/v1/verifications/{$id}/cancel")->assertForbidden();
        $this->asMaker();
        $this->postJson("/api/v1/verifications/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNotSame('NEW-123', $policy->fresh()->policy_no);
        $this->assertTrue(AuditEntry::query()->where('action', 'policy.change_cancelled')->exists());

        $this->getJson('/api/v1/follow-ups?category=verify')->assertOk()->assertJsonPath('meta.counts.verify', 0);
        $this->patchJson("/api/v1/policies/{$policy->id}", ['policyNo' => 'NEW-456'])->assertStatus(202);
        $this->getJson('/api/v1/follow-ups?category=verify')->assertOk()
            ->assertJsonPath('meta.counts.verify', 1)
            ->assertJsonPath('data.0.makerName', $this->staff->name);
    }

    private function grantVerifyTo(string $roleKey): void
    {
        Role::query()->where('key', $roleKey)->firstOrFail()->permissions()
            ->syncWithoutDetaching([\App\Models\Permission::query()->where('key', 'policies.verify')->value('id')]);
        $this->staff->update(['role' => $roleKey, 'role_id' => Role::query()->where('key', $roleKey)->value('id')]);
        Cache::flush();
    }
}
