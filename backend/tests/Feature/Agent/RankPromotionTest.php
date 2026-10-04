<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\MemberVolumeAccumulation;
use App\Models\Permission;
use App\Models\PolicyPayment;
use App\Models\Rank;
use App\Models\RankPromotion;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RankSeeder;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class RankPromotionTest extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    private int $agentSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->seed(RankSeeder::class);
        $this->seed(RbacPermissionSeeder::class);
        Cache::flush();
        $this->buildFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rankId(int $level): int
    {
        return (int) Rank::query()->where('level', $level)->value('id');
    }

    private function makeAgent(int $level = 1, ?Agent $parent = null, array $attrs = []): Agent
    {
        $n = ++$this->agentSeq;

        return Agent::create(array_merge([
            'tenant_id' => $this->tenant->id, 'agent_code' => "RK{$n}", 'agent_type' => 'IN',
            'first_name' => "ตัวแทน{$n}", 'last_name' => 'ทดสอบ', 'kind' => 'individual', 'vat_type' => '1',
            'rank_id' => $this->rankId($level), 'level' => 'l'.$level, 'parent_agent_id' => $parent?->id,
            'active' => true, 'approval_status' => 'approved',
        ], $attrs));
    }

    private function pay(Agent $seller, float $amount, string $date): PolicyPayment
    {
        $policy = $this->makePolicy(['writing_agent_id' => $seller->id]);

        return PolicyPayment::create([
            'policy_id' => $policy->id, 'payment_date' => $date, 'amount' => $amount, 'method' => 'bankTransfer',
        ]);
    }

    private function volume(Agent $agent, string $month = '2026-10'): ?MemberVolumeAccumulation
    {
        return MemberVolumeAccumulation::query()
            ->where('agent_id', $agent->id)->where('period_year_month', $month)->first();
    }

    private function pending(Agent $agent): ?RankPromotion
    {
        return RankPromotion::query()->where('agent_id', $agent->id)->where('status', 'pending')->latest('id')->first();
    }

    private function userWithRole(string $roleKey, ?Tenant $tenant = null): User
    {
        return User::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'name' => $roleKey,
            'email' => $roleKey.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'),
            'role' => $roleKey,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'),
            'locale' => 'th',
            'active' => true,
        ]);
    }

    private function grant(string $roleKey, string $permission): void
    {
        Role::query()->where('key', $roleKey)->firstOrFail()->permissions()
            ->syncWithoutDetaching([Permission::query()->where('key', $permission)->value('id')]);
        Cache::flush();
    }

    private function licensed(): array
    {
        return ['license_life_no' => '6401000001', 'license_life_expiry' => '2027-12-31'];
    }

    public function test_team_volume_counts_only_lower_ranked_downline(): void
    {
        $leader = $this->makeAgent(3);
        $junior = $this->makeAgent(1, $leader);
        $peer = $this->makeAgent(3, $leader);
        $peerJunior = $this->makeAgent(1, $peer);

        $this->pay($leader, 1000, '2026-10-02');
        $this->pay($junior, 2000, '2026-10-03');
        $this->pay($peer, 4000, '2026-10-04');
        $this->pay($peerJunior, 8000, '2026-10-05');

        $this->assertEquals(1000, (float) $this->volume($leader)->personal_sales_volume);
        $this->assertEquals(11000, (float) $this->volume($leader)->team_sales_volume);
        $this->assertEquals(12000, (float) $this->volume($peer)->team_sales_volume);
        $this->assertEquals(2000, (float) $this->volume($junior)->team_sales_volume);
    }

    public function test_rolling_window_is_three_calendar_months_and_backdated_payments_roll_forward(): void
    {
        $this->pay($this->agent, 1000, '2026-07-20');
        $this->pay($this->agent, 2000, '2026-08-10');
        $this->pay($this->agent, 4000, '2026-10-01');

        $this->assertEquals(6000, (float) $this->volume($this->agent)->rolling_3_month_volume);
        $this->assertEquals(3000, (float) $this->volume($this->agent, '2026-09')->rolling_3_month_volume);

        $this->pay($this->agent, 500, '2026-09-30');
        $this->assertEquals(6500, (float) $this->volume($this->agent)->rolling_3_month_volume);
        $this->assertEquals(3500, (float) $this->volume($this->agent, '2026-09')->rolling_3_month_volume);
    }

    public function test_month_buckets_are_correct_on_the_last_day_of_a_long_month(): void
    {
        Carbon::setTestNow('2026-10-31 09:00:00');
        $this->pay($this->agent, 300, '2026-09-15');
        $this->pay($this->agent, 700, '2026-10-31');

        $this->assertEquals(300, (float) $this->volume($this->agent, '2026-09')->personal_sales_volume);
        $this->assertEquals(700, (float) $this->volume($this->agent)->personal_sales_volume);
        $this->assertEquals(1000, (float) $this->volume($this->agent)->rolling_3_month_volume);
    }

    public function test_deleting_or_editing_a_payment_updates_volume(): void
    {
        $big = $this->pay($this->agent, 3000, '2026-10-05');
        $small = $this->pay($this->agent, 1000, '2026-10-06');
        $this->assertEquals(4000, (float) $this->volume($this->agent)->personal_sales_volume);

        $big->delete();
        $this->assertEquals(1000, (float) $this->volume($this->agent)->personal_sales_volume);

        $small->update(['amount' => 400]);
        $this->assertEquals(400, (float) $this->volume($this->agent)->personal_sales_volume);

        $small->update(['payment_date' => '2026-09-20']);
        $this->assertEquals(0, (float) $this->volume($this->agent)->personal_sales_volume);
        $this->assertEquals(400, (float) $this->volume($this->agent, '2026-09')->personal_sales_volume);
        $this->assertEquals(400, (float) $this->volume($this->agent)->rolling_3_month_volume);
    }

    public function test_reaching_target_creates_pending_and_approval_promotes_with_audit(): void
    {
        $leader = $this->makeAgent(3);
        $this->agent->update(['parent_agent_id' => $leader->id]);

        $this->pay($this->agent, 100, '2026-10-02');
        $lv2 = $this->pending($this->agent);
        $this->assertSame(2, (int) $lv2->toRank->level);

        $this->pay($this->agent, 350000, '2026-10-03');
        $lv3 = $this->pending($this->agent);
        $this->assertSame(3, (int) $lv3->toRank->level);
        $this->assertSame('auto', $lv3->trigger);
        $this->assertEquals(350100, (float) $lv3->qualifying_rolling_3_month_volume);
        $this->assertSame('superseded', $lv2->fresh()->status);
        $this->assertSame(1, RankPromotion::query()->where('agent_id', $this->agent->id)->where('status', 'pending')->count());
        $this->assertSame($this->rankId(1), (int) $this->agent->fresh()->rank_id);
        $this->assertEquals(350100, (float) $this->volume($leader)->team_sales_volume);

        $this->actingAsAdmin();
        $this->getJson('/api/v1/rank-promotions')->assertOk()
            ->assertJsonPath('meta.pendingCount', 1)
            ->assertJsonPath('data.0.toLevel', 3)
            ->assertJsonPath('data.0.currentLevel', 1);

        $this->postJson("/api/v1/rank-promotions/{$lv3->id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decidedByName', $this->admin->name);

        $agent = $this->agent->fresh();
        $this->assertSame($this->rankId(3), (int) $agent->rank_id);
        $this->assertSame('l3', $agent->level);
        $this->assertTrue(AuditEntry::query()->where('action', 'agent.rank_approved')->where('target', 'agent:'.$agent->id)->exists());
        $this->assertTrue(AuditEntry::query()->where('action', 'agent.updated')->where('target', 'agent:'.$agent->id)->exists());
        $this->assertEquals(0, (float) $this->volume($leader)->team_sales_volume);

        $this->postJson("/api/v1/rank-promotions/{$lv3->id}/approve")->assertStatus(422)->assertJsonPath('code', 'not_pending');
    }

    public function test_unlicensed_agents_cap_at_lv6_and_license_is_rechecked_on_approval(): void
    {
        $plain = $this->makeAgent(1);
        $expired = $this->makeAgent(1, null, ['license_non_life_no' => 'NL-1', 'license_non_life_expiry' => '2026-10-14']);
        $licensed = $this->makeAgent(1, null, $this->licensed());
        $numberOnly = $this->makeAgent(1, null, ['license_life_no' => '6401000002']);

        foreach ([$plain, $expired, $licensed, $numberOnly] as $a) {
            $this->pay($a, 7_000_000, '2026-10-05');
        }

        $this->assertSame(6, (int) $this->pending($plain)->toRank->level);
        $this->assertSame(6, (int) $this->pending($expired)->toRank->level);
        $this->assertSame(6, (int) $this->pending($numberOnly)->toRank->level);
        $promotion = $this->pending($licensed);
        $this->assertSame(7, (int) $promotion->toRank->level);

        $this->actingAsAdmin();
        $this->getJson('/api/v1/agents/'.$licensed->id.'/level-progress')->assertOk();

        $licensed->update(['license_life_expiry' => '2026-10-01']);
        $this->postJson("/api/v1/rank-promotions/{$promotion->id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'license_required');
        $this->assertSame($this->rankId(1), (int) $licensed->fresh()->rank_id);
        $this->assertSame('pending', $promotion->fresh()->status);
    }

    public function test_reject_requires_a_reason_keeps_rank_and_is_not_reproposed_in_the_same_month(): void
    {
        $this->pay($this->agent, 350000, '2026-10-03');
        $promotion = $this->pending($this->agent);

        $this->actingAsAdmin();
        $this->postJson("/api/v1/rank-promotions/{$promotion->id}/reject")->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/rank-promotions/{$promotion->id}/reject", ['note' => 'ยอดยังไม่ผ่านการตรวจสอบ'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.notes', 'ยอดยังไม่ผ่านการตรวจสอบ');

        $this->assertSame($this->rankId(1), (int) $this->agent->fresh()->rank_id);
        $this->assertTrue(AuditEntry::query()->where('action', 'agent.rank_rejected')->exists());

        $this->pay($this->agent, 1000, '2026-10-04');
        $this->assertNull($this->pending($this->agent));

        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->pay($this->agent, 1000, '2026-11-01');
        $this->assertSame(3, (int) $this->pending($this->agent)->toRank->level);
    }

    public function test_only_users_with_the_rank_approve_permission_can_decide(): void
    {
        $this->pay($this->agent, 350000, '2026-10-03');
        $promotion = $this->pending($this->agent);

        Sanctum::actingAs($this->userWithRole('sales_support'));
        $this->postJson("/api/v1/rank-promotions/{$promotion->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/rank-promotions/{$promotion->id}/reject", ['note' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->userWithRole('agent'));
        $this->postJson("/api/v1/rank-promotions/{$promotion->id}/approve")->assertForbidden();
        $this->assertSame('pending', $promotion->fresh()->status);

        $other = Tenant::create(['slug' => 'other-'.uniqid(), 'name' => 'Other', 'active' => true]);
        Sanctum::actingAs($this->userWithRole('admin', $other));
        $this->postJson("/api/v1/rank-promotions/{$promotion->id}/approve")->assertNotFound();

        $this->grant('sales_support', 'agents.rank_approve');
        Sanctum::actingAs($this->userWithRole('sales_support'));
        $this->postJson("/api/v1/rank-promotions/{$promotion->id}/approve")->assertOk();
        $this->assertSame($this->rankId(3), (int) $this->agent->fresh()->rank_id);
    }

    public function test_manual_level_change_needs_a_request_and_approval(): void
    {
        $requester = $this->userWithRole('sales_support');
        Sanctum::actingAs($requester);
        $url = "/api/v1/agents/{$this->agent->id}/rank-requests";
        $this->postJson($url, ['toLevel' => 4, 'note' => 'ผลงานจากระบบเก่า'])->assertForbidden();

        $this->grant('sales_support', 'agents.update');
        Sanctum::actingAs($requester);
        $this->postJson($url, ['toLevel' => 4])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson($url, ['toLevel' => 1, 'note' => 'x'])->assertStatus(422)->assertJsonValidationErrors('toLevel');
        $this->postJson($url, ['toLevel' => 7, 'note' => 'x'])->assertStatus(422)->assertJsonValidationErrors('toLevel');

        $created = $this->postJson($url, ['toLevel' => 4, 'note' => 'ผลงานจากระบบเก่า'])->assertCreated()
            ->assertJsonPath('data.trigger', 'manual')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requestedByName', $requester->name)
            ->assertJsonPath('data.requestNote', 'ผลงานจากระบบเก่า');
        $this->postJson($url, ['toLevel' => 5, 'note' => 'อีกครั้ง'])->assertStatus(422)->assertJsonValidationErrors('toLevel');
        $this->assertSame($this->rankId(1), (int) $this->agent->fresh()->rank_id);
        $this->assertTrue(AuditEntry::query()->where('action', 'agent.rank_requested')->exists());

        $this->patchJson("/api/v1/agents/{$this->agent->id}", ['level' => 'l5', 'rankId' => $this->rankId(5)])->assertOk();
        $this->assertSame($this->rankId(1), (int) $this->agent->fresh()->rank_id);
        $this->patchJson("/api/v1/agents/{$this->agent->id}/hierarchy", ['level' => 'l5'])->assertStatus(422)->assertJsonValidationErrors('level');

        $this->actingAsAdmin();
        $this->postJson('/api/v1/rank-promotions/'.$created->json('data.id').'/approve')->assertOk();
        $this->assertSame($this->rankId(4), (int) $this->agent->fresh()->rank_id);

        $down = $this->postJson($url, ['toLevel' => 2, 'note' => 'ปรับลดตามมติผู้บริหาร'])->assertCreated();
        $this->postJson('/api/v1/rank-promotions/'.$down->json('data.id').'/approve')->assertOk();
        $agent = $this->agent->fresh();
        $this->assertSame($this->rankId(2), (int) $agent->rank_id);
        $this->assertSame('l2', $agent->level);

        $this->getJson("/api/v1/rank-promotions?status=all&agentId={$agent->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_approving_a_higher_level_supersedes_lower_pending_requests(): void
    {
        $this->actingAsAdmin();
        $url = "/api/v1/agents/{$this->agent->id}/rank-requests";
        $manual = $this->postJson($url, ['toLevel' => 2, 'note' => 'ขอขึ้น'])->assertCreated()->json('data.id');

        $this->pay($this->agent, 700000, '2026-10-03');
        $auto = $this->pending($this->agent);
        $this->assertSame(4, (int) $auto->toRank->level);

        $this->postJson("/api/v1/rank-promotions/{$auto->id}/approve")->assertOk();
        $this->assertSame('superseded', RankPromotion::query()->findOrFail($manual)->status);
        $this->assertSame($this->rankId(4), (int) $this->agent->fresh()->rank_id);
    }

    public function test_rank_targets_are_editable_with_permission_validation_and_audit(): void
    {
        $lv4 = Rank::query()->where('level', 4)->firstOrFail();

        Sanctum::actingAs($this->userWithRole('sales_support'));
        $this->patchJson("/api/v1/ranks/{$lv4->id}", ['threeMonthAccumTarget' => 700000])->assertForbidden();

        $this->actingAsAdmin();
        $this->patchJson("/api/v1/ranks/{$lv4->id}", ['threeMonthAccumTarget' => 200000])
            ->assertStatus(422)->assertJsonValidationErrors('threeMonthAccumTarget');
        $this->patchJson('/api/v1/ranks/'.$this->rankId(1), ['threeMonthAccumTarget' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('threeMonthAccumTarget');
        $this->patchJson('/api/v1/ranks/'.$this->rankId(8), ['licenseRequired' => false])
            ->assertStatus(422)->assertJsonValidationErrors('licenseRequired');

        $updated = $this->patchJson("/api/v1/ranks/{$lv4->id}", ['threeMonthAccumTarget' => 700000, 'nameTh' => 'ผู้จัดการทีม'])->assertOk()
            ->assertJsonPath('data.nameTh', 'ผู้จัดการทีม');
        $this->assertEquals(700000, $updated->json('data.threeMonthAccumTarget'));
        $audit = AuditEntry::query()->where('action', 'rank.updated')->firstOrFail();
        $this->assertEquals(600000, $audit->metadata['before']['threeMonthAccumTarget']);
        $this->assertEquals(700000, $audit->metadata['after']['threeMonthAccumTarget']);

        $ranks = $this->getJson('/api/v1/ranks')->assertOk()->json('data');
        $this->assertCount(10, $ranks);
        $this->assertArrayNotHasKey('monthlyAvgTarget', $ranks[0]);

        $this->pay($this->agent, 650000, '2026-10-03');
        $this->assertSame(3, (int) $this->pending($this->agent)->toRank->level);
    }

    public function test_new_agents_start_at_lv1_and_reconcile_matches_realtime(): void
    {
        $this->assertSame($this->rankId(1), (int) $this->agent->rank_id);
        $fresh = Agent::create([
            'tenant_id' => $this->tenant->id, 'agent_code' => 'NEW1', 'agent_type' => 'IN',
            'first_name' => 'ใหม่', 'last_name' => 'ทดสอบ', 'kind' => 'individual', 'vat_type' => '1',
            'active' => true, 'approval_status' => 'approved',
        ]);
        $this->assertSame($this->rankId(1), (int) $fresh->rank_id);
        $this->assertSame('l1', $fresh->level);

        $leader = $this->makeAgent(3);
        $this->agent->update(['parent_agent_id' => $leader->id]);
        $this->pay($this->agent, 1500, '2026-08-11');
        $this->pay($this->agent, 2500, '2026-10-01');
        $this->pay($leader, 1000, '2026-09-09');

        $snapshot = MemberVolumeAccumulation::query()->orderBy('agent_id')->orderBy('period_year_month')
            ->get(['agent_id', 'period_year_month', 'personal_sales_volume', 'team_sales_volume', 'rolling_3_month_volume'])
            ->filter(fn ($r) => (float) $r->rolling_3_month_volume > 0)
            ->map(fn ($r) => [$r->agent_id, $r->period_year_month, (float) $r->personal_sales_volume, (float) $r->team_sales_volume, (float) $r->rolling_3_month_volume])
            ->values()->all();

        MemberVolumeAccumulation::query()->delete();
        $this->artisan('mgm:reconcile-volumes', ['--tenant' => $this->tenant->id])->assertSuccessful();

        $rebuilt = MemberVolumeAccumulation::query()->orderBy('agent_id')->orderBy('period_year_month')
            ->get(['agent_id', 'period_year_month', 'personal_sales_volume', 'team_sales_volume', 'rolling_3_month_volume'])
            ->filter(fn ($r) => (float) $r->rolling_3_month_volume > 0)
            ->map(fn ($r) => [$r->agent_id, $r->period_year_month, (float) $r->personal_sales_volume, (float) $r->team_sales_volume, (float) $r->rolling_3_month_volume])
            ->values()->all();

        $this->assertSame($snapshot, $rebuilt);
        $this->assertEquals(5000, (float) $this->volume($leader)->rolling_3_month_volume);
    }
}
