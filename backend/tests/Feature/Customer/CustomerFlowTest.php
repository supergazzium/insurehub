<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\CustomerKycDoc;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class CustomerFlowTest extends TestCase
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

    private function userWithRole(string $roleKey, bool $withRoleId = true): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $roleKey,
            'email' => $roleKey.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'),
            'role' => $roleKey,
            'role_id' => $withRoleId ? Role::query()->where('key', $roleKey)->value('id') : null,
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

    private function makeCustomer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'customer_code' => 'C'.random_int(100000, 999999),
            'customer_type' => 'individual',
            'title_th' => 'นาย',
            'first_name' => 'ทดสอบ',
            'last_name' => 'ระบบ',
            'active' => true,
        ], $attrs));
    }

    public function test_editing_requires_customers_update_permission(): void
    {
        $c = $this->makeCustomer();
        $ops = $this->userWithRole('operations');

        Sanctum::actingAs($ops);
        $this->patchJson("/api/v1/customers/{$c->id}", ['nickname' => 'โอ๊ต'])->assertForbidden();

        $this->grant('operations', 'customers.update');
        $this->patchJson("/api/v1/customers/{$c->id}", ['nickname' => 'โอ๊ต'])->assertOk()->assertJsonPath('data.nickname', 'โอ๊ต');

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->patchJson("/api/v1/customers/{$c->id}", ['nickname' => 'แอดมิน'])->assertOk();

        Sanctum::actingAs($this->userWithRole('super_admin', withRoleId: false));
        $this->patchJson("/api/v1/customers/{$c->id}", ['nickname' => 'ซุปเปอร์'])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_view_and_list_stay_open_for_roles_without_customer_permissions(): void
    {
        $c = $this->makeCustomer();
        Sanctum::actingAs($this->userWithRole('operations'));

        $this->getJson('/api/v1/customers')->assertOk();
        $this->getJson("/api/v1/customers/{$c->id}")->assertOk();
    }

    public function test_update_response_keeps_kyc_docs_and_assignment_history(): void
    {
        $c = $this->makeCustomer();
        CustomerKycDoc::create(['customer_id' => $c->id, 'type' => 'idCard', 'file_name' => 'id.jpg']);
        Sanctum::actingAs($this->userWithRole('admin'));
        $this->postJson("/api/v1/customers/{$c->id}/assignments", ['toAgentId' => $this->agent->id, 'reason' => 'ย้ายพอร์ต'])
            ->assertOk()->assertJsonCount(1, 'data.assignmentHistory')
            ->assertJsonPath('data.assignmentHistory.0.toAgentCode', $this->agent->agent_code)
            ->assertJsonPath('data.assignmentHistory.0.fromAgentCode', null);

        $this->patchJson("/api/v1/customers/{$c->id}", ['nickname' => 'x'])
            ->assertOk()
            ->assertJsonCount(1, 'data.kycDocs')
            ->assertJsonCount(1, 'data.assignmentHistory');
    }

    public function test_delete_needs_permission_and_is_blocked_when_customer_has_policies(): void
    {
        $withPolicy = $this->customer;
        $this->makePolicy();
        $empty = $this->makeCustomer();

        Sanctum::actingAs($this->userWithRole('operations'));
        $this->deleteJson("/api/v1/customers/{$empty->id}")->assertForbidden();

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->deleteJson("/api/v1/customers/{$withPolicy->id}")->assertStatus(409)->assertJsonPath('code', 'customer_has_policies');
        $this->deleteJson("/api/v1/customers/{$empty->id}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $empty->id]);
    }

    public function test_foreign_customer_single_field_patch_uses_foreign_rules(): void
    {
        $f = $this->makeCustomer(['customer_type' => 'foreign_individual', 'first_name' => 'John', 'last_name' => 'Smith', 'passport' => 'AB123456']);
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->patchJson("/api/v1/customers/{$f->id}", ['phone' => '+44 20 7946 0958'])->assertOk();
        $this->patchJson("/api/v1/customers/{$f->id}", ['firstName' => 'Johnny'])->assertOk()->assertJsonPath('data.firstName', 'Johnny');
    }

    public function test_phones_accept_separators_and_backup_phone_accepts_mobile(): void
    {
        $c = $this->makeCustomer();
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->patchJson("/api/v1/customers/{$c->id}", ['phone' => '081-234-5678', 'telPhone' => '089 999 8888'])
            ->assertOk()
            ->assertJsonPath('data.phone', '0812345678')
            ->assertJsonPath('data.telPhone', '0899998888');
        $this->patchJson("/api/v1/customers/{$c->id}", ['telPhone' => '12345'])->assertStatus(422);

        $ids = collect($this->getJson('/api/v1/customers?q=0899998888')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains((string) $c->id, $ids);
    }

    public function test_list_gender_column_filter_and_sort(): void
    {
        $m = $this->makeCustomer(['gender' => 'M']);
        $f = $this->makeCustomer(['gender' => 'F', 'title_th' => 'นาง']);
        $n = $this->makeCustomer(['gender' => null, 'title_th' => 'คุณ']);
        Sanctum::actingAs($this->userWithRole('admin'));

        $female = collect($this->getJson('/api/v1/customers?gender=F&perPage=50')->json('data'))->pluck('id')->all();
        $this->assertContains((string) $f->id, $female);
        $this->assertNotContains((string) $m->id, $female);
        $none = collect($this->getJson('/api/v1/customers?gender=none&perPage=50')->json('data'))->pluck('id')->all();
        $this->assertContains((string) $n->id, $none);
        $row = collect($this->getJson('/api/v1/customers?perPage=50&sortBy=gender&sortDir=asc')->assertOk()->json('data'))->firstWhere('id', (string) $m->id);
        $this->assertSame('M', $row['gender']);
    }

    public function test_backfill_command_guesses_from_title_and_keeps_explicit_values(): void
    {
        $guess = $this->makeCustomer(['gender' => null, 'title_th' => 'นางสาว']);
        $legacy = $this->makeCustomer(['gender' => 'male', 'title_th' => 'นาย']);
        $explicit = $this->makeCustomer(['gender' => 'F', 'title_th' => 'นาย']);
        $corporate = $this->makeCustomer(['gender' => 'other', 'customer_type' => 'corporate', 'title_th' => 'บริษัท']);

        $this->artisan('customers:backfill-gender')->assertSuccessful();
        $this->assertNull($guess->fresh()->gender);

        $this->artisan('customers:backfill-gender', ['--apply' => true])->assertSuccessful();
        $this->assertSame('F', $guess->fresh()->gender);
        $this->assertSame('M', $legacy->fresh()->gender);
        $this->assertSame('F', $explicit->fresh()->gender);
        $this->assertNull($corporate->fresh()->gender);
    }

    public function test_reassigning_caretaker_records_history_and_only_affects_new_work(): void
    {
        $other = \App\Models\Agent::create([
            'tenant_id' => $this->tenant->id, 'agent_code' => 'AG002', 'agent_type' => 'AG',
            'first_name' => 'สมหญิง', 'last_name' => 'ดูแลดี', 'kind' => 'individual', 'vat_type' => '1',
            'level' => 'l1', 'active' => true, 'approval_status' => 'approved',
        ]);
        $policy = $this->makePolicy();
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->postJson("/api/v1/customers/{$this->customer->id}/assignments", ['toAgentId' => $other->id, 'reason' => 'ผู้ดูแลเดิมลาออก'])
            ->assertOk()->assertJsonPath('data.assignedAgentId', (string) $other->id);

        $this->assertSame((string) $this->agent->id, (string) $policy->fresh()->writing_agent_id);
        $this->getJson("/api/v1/policies/{$policy->id}/renewal-draft")->assertOk()
            ->assertJsonPath('data.writingAgentId', (string) $other->id)
            ->assertJsonPath('meta.agentFromCaretaker', true);
    }

    public function test_assignment_requires_update_permission(): void
    {
        Sanctum::actingAs($this->userWithRole('operations'));
        $this->postJson("/api/v1/customers/{$this->customer->id}/assignments", ['toAgentId' => $this->agent->id])->assertForbidden();
    }

    public function test_policy_counts_breakdown(): void
    {
        $this->makePolicy(['status' => 'active']);
        $this->makePolicy(['status' => 'draft']);
        $this->makePolicy(['status' => 'issued']);
        $this->makePolicy(['status' => 'cancelled']);
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->getJson("/api/v1/customers/{$this->customer->id}")->assertOk()
            ->assertJsonPath('data.policyCounts.inForce', 1)
            ->assertJsonPath('data.policyCounts.inProgress', 2)
            ->assertJsonPath('data.policyCounts.ended', 1)
            ->assertJsonPath('data.policyCounts.total', 4);
    }

    public function test_sales_summary_and_tier_follow_tenant_thresholds(): void
    {
        $this->makePolicy(['status' => 'active', 'annual_premium' => 30000]);
        $this->makePolicy(['status' => 'expired', 'annual_premium' => 25000, 'effective_date' => now()->subYears(2)->toDateString()]);
        $this->makePolicy(['status' => 'draft', 'annual_premium' => 900000]);
        $this->makePolicy(['status' => 'cancelled', 'annual_premium' => 900000]);
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->getJson("/api/v1/customers/{$this->customer->id}")->assertOk()
            ->assertJsonPath('data.salesSummary.totalPremium', 55000)
            ->assertJsonPath('data.salesSummary.policyCount', 2)
            ->assertJsonPath('data.salesSummary.tier.key', 'gold');

        // Custom thresholds: make platinum the special tier at 50k so our 55k
        // customer lands there, and disable gold (null min).
        $this->patchJson('/api/v1/tenant', ['customerTiers' => [
            ['key' => 'platinum', 'label' => 'ลูกค้าพิเศษ', 'min' => 50000],
            ['key' => 'gold', 'min' => null],
            ['key' => 'silver', 'min' => 10000],
        ]])->assertOk();

        $this->getJson("/api/v1/customers/{$this->customer->id}")->assertJsonPath('data.salesSummary.tier.label', 'ลูกค้าพิเศษ');
        $platinum = collect($this->getJson('/api/v1/customers?tier=platinum&perPage=50')->assertOk()->json('data'));
        $this->assertSame((string) $this->customer->id, $platinum->first()['id']);
        $this->assertEquals(55000, $platinum->first()['totalPremium']);
        $this->assertSame([], $this->getJson('/api/v1/customers?tier=gold&perPage=50')->json('data'));
    }

    public function test_updates_are_audited_with_pii_masked(): void
    {
        $c = $this->makeCustomer(['phone' => '0811111111']);
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->patchJson("/api/v1/customers/{$c->id}", ['phone' => '0822222222', 'nickname' => 'บี'])->assertOk();

        $entry = AuditEntry::query()->where('target', 'customer:'.$c->id)->where('action', 'customer.updated')->firstOrFail();
        $this->assertSame('••••••2222', $entry->metadata['changes']['phone']['to']);
        $this->assertSame('••••••1111', $entry->metadata['changes']['phone']['from']);
        $this->assertSame('บี', $entry->metadata['changes']['nickname']['to']);

        $this->getJson("/api/v1/customers/{$c->id}/audit")->assertOk()
            ->assertJsonPath('data.0.action', 'customer.updated')
            ->assertJsonPath('data.0.changes.nickname.to', 'บี');
    }

    public function test_audit_feed_shows_agent_code_for_caretaker_change(): void
    {
        $c = $this->makeCustomer();
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->postJson("/api/v1/customers/{$c->id}/assignments", ['toAgentId' => $this->agent->id, 'reason' => 'โอนพอร์ต'])->assertOk();

        $change = $this->getJson("/api/v1/customers/{$c->id}/audit")->assertOk()->json('data.0.changes.assigned_agent_id');
        $this->assertNull($change['from']);
        $this->assertStringStartsWith($this->agent->agent_code, (string) $change['to']);
    }
}
