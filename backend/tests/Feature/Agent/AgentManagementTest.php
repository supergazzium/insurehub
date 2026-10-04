<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\Agent;
use App\Models\AgentNote;
use App\Models\AuditEntry;
use App\Models\CommissionPayoutBatch;
use App\Models\EmailVerificationToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commission\CommissionPayoutService;
use Database\Seeders\RankSeeder;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class AgentManagementTest extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankSeeder::class);
        $this->seed(RbacPermissionSeeder::class);
        Cache::flush();
        $this->buildFixtures();
    }

    private function taxId(string $prefix12): string
    {
        $sum = 0;
        foreach (str_split($prefix12) as $i => $d) {
            $sum += (int) $d * (13 - $i);
        }

        return $prefix12.((11 - $sum % 11) % 10);
    }

    private function corporate(array $attrs = []): Agent
    {
        return Agent::create(array_merge([
            'tenant_id' => $this->tenant->id, 'agent_code' => 'CORP1', 'agent_type' => 'IN', 'kind' => 'corporate',
            'juristic_name' => 'บริษัท รุ่งเรืองโบรกเกอร์ จำกัด', 'tax_id' => $this->taxId('010556201234'),
            'email' => 'corp@example.test', 'phone' => '021234567', 'vat_type' => '1',
            'active' => true, 'approval_status' => 'approved',
        ], $attrs));
    }

    private function userWithRole(string $roleKey, ?Tenant $tenant = null, array $attrs = []): User
    {
        return User::create(array_merge([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'name' => $roleKey,
            'email' => $roleKey.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'),
            'role' => $roleKey,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'),
            'locale' => 'th',
            'active' => true,
        ], $attrs));
    }

    private function grant(string $roleKey, string $permission): void
    {
        Role::query()->where('key', $roleKey)->firstOrFail()->permissions()
            ->syncWithoutDetaching([Permission::query()->where('key', $permission)->value('id')]);
        Cache::flush();
    }

    public function test_search_matches_juristic_name_and_tax_id_and_list_hides_phone(): void
    {
        $corp = $this->corporate();
        $this->agent->update(['approval_status' => 'pending']);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/agents?q='.urlencode('รุ่งเรือง'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $corp->id)
            ->assertJsonPath('data.0.kind', 'corporate')
            ->assertJsonPath('data.0.juristicName', 'บริษัท รุ่งเรืองโบรกเกอร์ จำกัด');
        $this->getJson('/api/v1/agents?q='.$corp->tax_id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/agents?q=021234567')->assertOk()->assertJsonCount(1, 'data');

        $rows = collect($this->getJson('/api/v1/agents')->assertOk()->json('data'));
        $this->assertArrayNotHasKey('phone', $rows->first());
        $this->assertSame('pending', $rows->firstWhere('id', (string) $this->agent->id)['approvalStatus']);
    }

    public function test_license_type_filter_checks_the_chosen_license_only(): void
    {
        $this->agent->update(['license_life_no' => 'L1', 'license_life_expiry' => now()->addYear()->toDateString()]);
        $this->corporate(['license_non_life_no' => 'N1', 'license_non_life_expiry' => now()->subDay()->toDateString()]);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/agents?licenseType=life&licenseStatus=valid')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.agentCode', 'AG001');
        $this->getJson('/api/v1/agents?licenseType=non_life&licenseStatus=valid')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/agents?licenseType=non_life&licenseStatus=expired')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.agentCode', 'CORP1');
    }

    public function test_corporate_and_vat_rules_require_a_valid_tax_id(): void
    {
        $this->actingAsAdmin();
        $url = "/api/v1/agents/{$this->agent->id}";

        $this->patchJson($url, ['kind' => 'corporate'])->assertStatus(422)->assertJsonValidationErrors(['juristicName', 'taxId']);
        $this->patchJson($url, ['hasVat' => true])->assertStatus(422)->assertJsonValidationErrors(['taxId', 'vatMode']);
        $this->patchJson($url, ['hasVat' => true, 'vatMode' => 'exclude', 'taxId' => '1234567890123'])
            ->assertStatus(422)->assertJsonValidationErrors('taxId');
        $this->patchJson($url, ['hasVat' => true, 'vatMode' => 'exclude', 'taxId' => '12345'])
            ->assertStatus(422)->assertJsonValidationErrors('taxId');

        $tax = $this->taxId('310010012345');
        $this->patchJson($url, ['hasVat' => true, 'vatMode' => 'include', 'taxId' => $tax])->assertOk()
            ->assertJsonPath('data.hasVat', true)->assertJsonPath('data.vatMode', 'include');
        $fresh = $this->agent->fresh();
        $this->assertSame('3', $fresh->vat_type);
        $this->assertSame($tax, $fresh->tax_id);

        $this->patchJson($url, ['hasVat' => false])->assertOk();
        $fresh = $this->agent->fresh();
        $this->assertSame('1', $fresh->vat_type);
        $this->assertNull($fresh->vat_mode);

        $legacy = $this->corporate(['agent_code' => 'OLD1', 'tax_id' => '101401486930']);
        $this->patchJson("/api/v1/agents/{$legacy->id}", ['phone' => '0899999999', 'taxId' => '101401486930', 'kind' => 'corporate'])->assertOk();
        $this->assertSame('0899999999', $legacy->fresh()->phone);
    }

    public function test_vat_and_kind_changes_are_audited_with_before_after_and_actor(): void
    {
        $this->actingAsAdmin();
        $tax = $this->taxId('010556209876');
        $this->patchJson("/api/v1/agents/{$this->agent->id}", [
            'kind' => 'corporate', 'juristicName' => 'บริษัท วิไลขายดี จำกัด', 'taxId' => $tax,
            'hasVat' => true, 'vatMode' => 'exclude',
        ])->assertOk();

        $audit = AuditEntry::query()->where('action', 'agent.updated')->where('target', 'agent:'.$this->agent->id)->latest('id')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('individual', $audit->metadata['old']['kind']);
        $this->assertSame('corporate', $audit->metadata['new']['kind']);
        $this->assertSame('exclude', $audit->metadata['new']['vat_mode']);
        $this->assertSame('2', $audit->metadata['new']['vat_type']);
        $this->assertSame('บริษัท วิไลขายดี จำกัด', $audit->metadata['new']['juristic_name']);
        $this->assertSame(substr($tax, -4), substr($audit->metadata['new']['tax_id'], -4));
        $this->assertStringNotContainsString(substr($tax, 0, 6), $audit->metadata['new']['tax_id']);
    }

    public function test_payout_batches_keep_their_vat_and_payee_snapshot_after_agent_changes(): void
    {
        $policy = $this->makePolicy(['comm_hub_to_agent_amount' => 1000]);
        $service = app(CommissionPayoutService::class);
        $old = $service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $item = $old->items()->firstOrFail();
        $this->assertSame('1', $item->vat_type);
        $this->assertSame('วิไล ขายดี', $item->payee_name);
        $this->assertSame('individual', $item->payee_kind);

        $this->actingAsAdmin();
        $this->patchJson("/api/v1/agents/{$this->agent->id}", [
            'kind' => 'corporate', 'juristicName' => 'บริษัท วิไลขายดี จำกัด', 'taxId' => $this->taxId('010556209876'),
            'hasVat' => true, 'vatMode' => 'exclude', 'firstName' => 'วิไลวรรณ',
        ])->assertOk();

        $this->getJson("/api/v1/commission-payout-batches/{$old->id}")->assertOk()
            ->assertJsonPath('data.agents.0.agentName', 'วิไล ขายดี')
            ->assertJsonPath('data.agents.0.vatType', '1');
        $pdf = $service->buildAgentPdfData($old->fresh(), (int) $this->agent->id, null);
        $this->assertSame('1', $pdf['vatType']);
        $this->assertSame('วิไล ขายดี', $pdf['agent']['name']);
        $this->assertEquals(0, $pdf['totals']['vat']);
        $this->assertSame('วิไล ขายดี', $service->exportRows($old->fresh())[0]['agent_name']);

        $this->makePolicy(['comm_hub_to_agent_amount' => 2000]);
        $new = $service->createBatch((int) $this->tenant->id, null, null, $this->admin->id);
        $newItem = $new->items()->firstOrFail();
        $this->assertSame('2', $newItem->vat_type);
        $this->assertSame('บริษัท วิไลขายดี จำกัด', $newItem->payee_name);
        $this->assertSame('corporate', $newItem->payee_kind);
        $newPdf = $service->buildAgentPdfData($new->fresh(), (int) $this->agent->id, null);
        $this->assertEquals(140, $newPdf['totals']['vat']);
        $this->assertSame(CommissionPayoutBatch::STATUS_DRAFT, $old->fresh()->status);
    }

    public function test_deactivated_agent_cannot_log_in_or_be_chosen_but_is_still_payable(): void
    {
        $agentUser = User::create([
            'tenant_id' => $this->tenant->id, 'agent_id' => $this->agent->id, 'name' => 'Agent User',
            'email' => 'agent-'.uniqid().'@example.test', 'password' => Hash::make('secret-pass'),
            'role' => 'agent', 'role_id' => Role::query()->where('key', 'agent')->value('id'), 'locale' => 'th', 'active' => true,
        ]);
        $agentUser->createToken('portal');
        $existing = $this->makePolicy(['comm_hub_to_agent_amount' => 800]);

        $this->postJson('/api/v1/auth/login', ['email' => $agentUser->email, 'password' => 'secret-pass'])->assertOk();

        $this->actingAsAdmin();
        $this->patchJson("/api/v1/agents/{$this->agent->id}", ['active' => false])->assertOk();
        $this->assertSame(0, $agentUser->tokens()->count());
        $this->assertTrue(AuditEntry::query()->where('action', 'agent.updated')->where('target', 'agent:'.$this->agent->id)->exists());

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $agentUser->email, 'password' => 'secret-pass'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->actingAsAdmin();
        $this->postJson('/api/v1/policies', $this->draftPayload($existing, [
            'refAppToId' => null, 'newOrRenew' => 'new', 'applicationNo' => 'APPX1',
        ]))->assertStatus(422)->assertJsonValidationErrors('writingAgentId');
        $this->patchJson("/api/v1/policies/{$existing->id}", ['writingAgentId' => (string) $this->agent->id, 'remark' => 'แก้หมายเหตุ'])
            ->assertJsonMissingValidationErrors('writingAgentId');

        $preview = app(CommissionPayoutService::class)->preview((int) $this->tenant->id);
        $this->assertSame((string) $this->agent->id, (string) $preview['agents'][0]['agentId']);
    }

    public function test_new_agents_are_in_and_existing_ag_agents_keep_their_type(): void
    {
        $this->actingAsAdmin();
        $created = $this->postJson('/api/v1/agents', ['agentCode' => 'NEW01', 'agentType' => 'AG', 'firstName' => 'ใหม่'])
            ->assertCreated()->json('data.id');
        $this->assertSame('IN', Agent::query()->findOrFail($created)->agent_type);
        $this->patchJson("/api/v1/agents/{$created}", ['agentType' => 'AG'])->assertStatus(422)->assertJsonValidationErrors('agentType');

        $this->assertSame('AG', $this->agent->agent_type);
        $this->patchJson("/api/v1/agents/{$this->agent->id}", ['agentType' => 'AG', 'phone' => '0811111111'])->assertOk();
        $this->assertSame('AG', $this->agent->fresh()->agent_type);
    }

    public function test_online_signup_creates_in_agents_and_corporates_keep_their_tax_id(): void
    {
        foreach (['personal' => '1101700203450', 'corporate' => '0105556110718'] as $type => $id) {
            $email = $type.'-'.uniqid().'@example.test';
            $token = Str::random(64);
            EmailVerificationToken::create(['token' => $token, 'email' => $email, 'expires_at' => now()->addMinutes(10)]);
            $this->postJson('/api/v1/auth/register', [
                'signupType' => $type, 'firstName' => 'สมหญิง', 'lastName' => 'สมัครใหม่', 'email' => $email,
                'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'idCard' => $id,
                'birthDate' => '1990-01-01', 'phone' => '0812345678', 'termsAccepted' => true,
                'juristicName' => $type === 'corporate' ? 'บริษัท สมัครใหม่ จำกัด' : null, 'emailOtpToken' => $token,
            ])->assertCreated();

            $agent = Agent::query()->where('email', $email)->firstOrFail();
            $this->assertSame('IN', $agent->agent_type);
            $this->assertSame($type === 'corporate' ? 'corporate' : 'individual', $agent->kind);
            $this->assertSame($type === 'corporate' ? $id : null, $agent->tax_id);
        }
    }

    public function test_documents_need_the_update_permission_and_replace_old_files(): void
    {
        Storage::fake('local');
        $url = "/api/v1/agents/{$this->agent->id}/documents";

        Sanctum::actingAs($this->userWithRole('sales_support'));
        $this->post("{$url}/id", ['file' => UploadedFile::fake()->image('id.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->getJson("/api/v1/agents/{$this->agent->id}")->assertOk()->assertJsonPath('data.documents.id', false);

        $this->grant('sales_support', 'agents.update');
        Sanctum::actingAs($this->userWithRole('sales_support'));
        $this->post("{$url}/id", ['file' => UploadedFile::fake()->image('id.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.documents.id', true);
        $first = $this->agent->fresh()->id_card_photo_path;
        Storage::disk('local')->assertExists($first);

        $this->post("{$url}/license_life", ['file' => UploadedFile::fake()->create('life.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.documents.licenseLife', true);
        $this->post("{$url}/id", ['file' => UploadedFile::fake()->image('id2.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($first);
        $this->get("{$url}/id")->assertOk();
        $this->getJson("{$url}/license_life?as=base64")->assertOk()
            ->assertJsonPath('data.mime', 'application/pdf')
            ->assertJson(fn ($json) => $json->has('data.content')->etc());
        $this->post("{$url}/bank", ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post("{$url}/passport", ['file' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertNotFound();

        $this->deleteJson("{$url}/license_life")->assertOk()->assertJsonPath('data.documents.licenseLife', false);
        $this->assertTrue(AuditEntry::query()->where('action', 'agent.updated')->get()
            ->contains(fn ($a) => in_array('license_life_doc_path', $a->metadata['fields'] ?? [], true)));

        $other = Tenant::create(['slug' => 'other-'.uniqid(), 'name' => 'Other', 'active' => true]);
        Sanctum::actingAs($this->userWithRole('admin', $other));
        $this->get("{$url}/id", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_notes_show_who_wrote_them(): void
    {
        $this->actingAsAdmin();
        $this->postJson("/api/v1/agents/{$this->agent->id}/notes", ['note' => 'โทรคุยแล้ว สนใจสมัคร', 'kind' => 'call'])->assertCreated();
        $this->getJson("/api/v1/agents/{$this->agent->id}/notes")->assertOk()
            ->assertJsonPath('data.0.createdByName', $this->admin->name)
            ->assertJsonPath('data.0.kind', 'call');
        $this->assertSame(1, AgentNote::query()->count());
    }
}
