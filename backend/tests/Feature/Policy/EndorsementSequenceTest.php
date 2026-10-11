<?php

declare(strict_types=1);

namespace Tests\Feature\Policy;

use App\Models\Permission;
use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class EndorsementSequenceTest extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

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
            'tenant_id' => $this->tenant->id,
            'name' => 'ผู้ใช้ '.$roleKey,
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

    private function general(Policy $p, string $type = 'endorsement.other', string $reason = 'แก้ที่อยู่')
    {
        return $this->postJson("/api/v1/policies/{$p->id}/endorsements", [
            'type' => $type, 'reason' => $reason, 'effectiveDate' => now()->toDateString(), 'detail' => 'รายละเอียด',
        ]);
    }

    private function premium(Policy $p, float $newPremium, string $reason = 'เพิ่มทุน')
    {
        return $this->postJson("/api/v1/policies/{$p->id}/endorsements/premium", [
            'reason' => $reason, 'effectiveDate' => now()->toDateString(),
            'newAnnualPremium' => $newPremium, 'additionalPremium' => 500,
        ]);
    }

    public function test_every_endorsement_type_gets_the_next_number_and_drives_the_label(): void
    {
        $p = $this->makePolicy();
        $this->actingAsAdmin();

        $this->general($p, 'endorsement.coverage_change')->assertCreated()->assertJsonPath('data.seq', 1);
        $this->premium($p, 13000)->assertCreated()->assertJsonPath('data.seq', 2);
        $this->general($p, 'endorsement.date_change')->assertCreated()->assertJsonPath('data.seq', 3)
            ->assertJsonPath('data.byUserName', $this->admin->name);

        $this->assertSame(3, (int) $p->fresh()->endorsement_count);
        $this->getJson("/api/v1/policies/{$p->id}")->assertOk()->assertJsonPath('data.periodLabel', '1# $3 1/1');
        $row = collect($this->getJson('/api/v1/policies?perPage=50')->json('data'))->firstWhere('id', (string) $p->id);
        $this->assertSame('1# $3 1/1', $row['periodLabel']);
    }

    public function test_voiding_keeps_the_number_and_the_next_endorsement_never_reuses_it(): void
    {
        $p = $this->makePolicy();
        $this->actingAsAdmin();
        $this->general($p)->assertCreated();
        $this->general($p)->assertCreated();
        $third = $this->general($p)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/policies/{$p->id}/endorsements/{$third}/void", ['reason' => 'บันทึกซ้ำ'])->assertOk()
            ->assertJsonPath('data.seq', 3)
            ->assertJsonPath('data.voidReason', 'บันทึกซ้ำ')
            ->assertJsonPath('data.voidedByName', $this->admin->name);
        $this->getJson("/api/v1/policies/{$p->id}")->assertOk()->assertJsonPath('data.periodLabel', '1# $2 1/1');

        $this->general($p)->assertCreated()->assertJsonPath('data.seq', 4);
        $this->getJson("/api/v1/policies/{$p->id}")->assertOk()->assertJsonPath('data.periodLabel', '1# $4 1/1');

        $list = $this->getJson("/api/v1/policies/{$p->id}/endorsements")->assertOk()->assertJsonPath('meta.nextSeq', 5);
        $this->assertSame([1, 2, 3, 4], array_column($list->json('data'), 'seq'));
        $this->assertNotNull($list->json('data.2.voidedAt'));
        $this->assertSame(4, PolicyEvent::query()->where('policy_id', $p->id)->where('type', 'like', 'endorsement.%')->count());
    }

    public function test_voiding_a_premium_endorsement_restores_the_previous_premium(): void
    {
        $p = $this->makePolicy(['annual_premium' => 12000, 'main_premium' => 12100, 'coverage' => 500000]);
        $this->actingAsAdmin();
        $first = $this->premium($p, 13000)->assertCreated()->json('data.id');
        $second = $this->premium($p, 15000)->assertCreated()->json('data.id');
        $this->assertEquals(15000, (float) $p->fresh()->annual_premium);

        $this->postJson("/api/v1/policies/{$p->id}/endorsements/{$second}/void", ['reason' => 'ลูกค้ายกเลิกการเพิ่มทุน'])->assertOk();
        $this->assertEquals(13000, (float) $p->fresh()->annual_premium);
        $this->getJson("/api/v1/policies/{$p->id}")->assertOk()->assertJsonPath('data.endorsementPremiumTotal', 500);

        $this->postJson("/api/v1/policies/{$p->id}/endorsements/{$first}/void", ['reason' => 'ผิดกรมธรรม์'])->assertOk();
        $fresh = $p->fresh();
        $this->assertEquals(12000, (float) $fresh->annual_premium);
        $this->assertEquals(12100, (float) $fresh->main_premium);
        $this->getJson("/api/v1/policies/{$p->id}")->assertOk()
            ->assertJsonPath('data.periodLabel', '1# 1/1')
            ->assertJsonPath('data.endorsementPremiumTotal', 0);
    }

    public function test_edit_keeps_the_number_and_voided_rows_are_frozen(): void
    {
        $p = $this->makePolicy();
        $this->actingAsAdmin();
        $id = $this->general($p)->assertCreated()->json('data.id');
        $prem = $this->premium($p, 14000)->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/policies/{$p->id}/endorsements/{$id}", ['reason' => 'แก้ชื่อผู้ขับขี่', 'detail' => 'เปลี่ยนเป็นนางสาว ข.'])->assertOk()
            ->assertJsonPath('data.seq', 1)
            ->assertJsonPath('data.payload.detail', 'เปลี่ยนเป็นนางสาว ข.');
        $this->patchJson("/api/v1/policies/{$p->id}/endorsements/{$prem}", ['reason' => 'x'])->assertStatus(422);
        $this->patchJson("/api/v1/policies/{$p->id}/endorsements/premium/{$prem}", [
            'reason' => 'ปรับเบี้ย', 'effectiveDate' => now()->toDateString(), 'newAnnualPremium' => 14500, 'additionalPremium' => 400,
        ])->assertOk()->assertJsonPath('data.seq', 2);
        $this->assertEquals(14500, (float) $p->fresh()->annual_premium);

        $this->postJson("/api/v1/policies/{$p->id}/endorsements/{$id}/void", ['reason' => 'ซ้ำ'])->assertOk();
        $this->postJson("/api/v1/policies/{$p->id}/endorsements/{$id}/void", ['reason' => 'อีกครั้ง'])->assertStatus(422)
            ->assertJsonPath('code', 'endorsement_voided');
        $this->patchJson("/api/v1/policies/{$p->id}/endorsements/{$id}", ['reason' => 'y'])->assertStatus(422);
        $this->postJson("/api/v1/policies/{$p->id}/endorsements/{$id}/void", [])->assertStatus(422);
    }

    public function test_edit_and_void_need_policies_update_but_recording_stays_open(): void
    {
        $p = $this->makePolicy();
        $ops = $this->roleUser('operations');
        Sanctum::actingAs($ops);

        $id = $this->general($p)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/policies/{$p->id}/endorsements/{$id}/void", ['reason' => 'ผิด'])->assertForbidden();
        $this->patchJson("/api/v1/policies/{$p->id}/endorsements/{$id}", ['reason' => 'แก้'])->assertForbidden();

        $this->grant('operations', 'policies.update');
        $this->postJson("/api/v1/policies/{$p->id}/endorsements/{$id}/void", ['reason' => 'ผิด'])->assertOk();
    }

    public function test_drafts_cannot_be_endorsed_and_other_tenants_are_hidden(): void
    {
        $draft = $this->makePolicy(['status' => 'draft', 'policy_no' => null]);
        $this->actingAsAdmin();
        $this->general($draft)->assertStatus(422);
        $this->premium($draft, 13000)->assertStatus(422);
        $this->assertSame(0, (int) $draft->fresh()->endorsement_count);

        $p = $this->makePolicy();
        $other = $this->makePolicy();
        $id = $this->general($p)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/policies/{$other->id}/endorsements/{$id}/void", ['reason' => 'x'])->assertNotFound();
    }

    public function test_numbering_restarts_on_renewal(): void
    {
        $source = $this->makePolicy(['status' => 'expired', 'effective_date' => '2025-09-23', 'expiry_date' => '2026-09-23']);
        $this->actingAsAdmin();
        $this->general($source)->assertCreated();
        $this->general($source)->assertCreated();
        $this->assertSame(2, (int) $source->fresh()->endorsement_count);

        $this->getJson("/api/v1/policies/{$source->id}/renewal-draft")->assertOk()
            ->assertJsonPath('data.periodLabel', '2# 1/1');

        $renewal = $this->makePolicy(['ref_app_to_id' => $source->id, 'new_or_renew' => 'renew', 'policy_year' => 2]);
        $this->general($renewal)->assertCreated()->assertJsonPath('data.seq', 1);
        $this->getJson("/api/v1/policies/{$renewal->id}")->assertOk()->assertJsonPath('data.periodLabel', '2# $1 1/1');
    }

    public function test_installment_labels_use_the_number_in_force_at_the_due_date(): void
    {
        $p = $this->makePolicy(['premium_mode' => 'monthly', 'installment_term' => '12', 'effective_date' => now()->subMonths(3)->toDateString()]);
        $this->actingAsAdmin();
        PolicyEvent::create(['policy_id' => $p->id, 'type' => 'endorsement.other', 'endorsement_seq' => 1,
            'occurred_at' => now()->subMonths(2)->subDays(5), 'payload' => []]);
        PolicyEvent::create(['policy_id' => $p->id, 'type' => 'endorsement.other', 'endorsement_seq' => 2,
            'occurred_at' => now()->subDay(), 'payload' => [], 'voided_at' => now()]);
        Policy::query()->whereKey($p->id)->update(['endorsement_count' => 2]);

        $labels = array_column($this->getJson("/api/v1/policies/{$p->id}/installments")->assertOk()->json('data'), 'periodLabel');
        $this->assertSame('1# 1/12', $labels[0]);
        $this->assertSame('1# $1 12/12', $labels[11]);
    }
}
