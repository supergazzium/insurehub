<?php

declare(strict_types=1);

namespace Tests\Feature\Policy;

use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\RenewalNotice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class RenewalPipelineTest extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    private const MOTOR = 'การประกันรถโดยความสมัครใจ';

    private const FIRE = 'การประกันอัคคีภัย';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        $this->productA->update(['category' => self::MOTOR, 'sub_category' => 'ป1.', 'name' => 'รถยนต์ชั้น 1 ซ่อมอู่']);
    }

    private function row(array $query, string $policyId): ?array
    {
        $rows = $this->getJson('/api/v1/reports/expiring-soon?'.http_build_query($query + ['perPage' => 200]))->assertOk()->json('data');

        return collect($rows)->firstWhere('policyId', $policyId);
    }

    private function followup(Policy $p, array $body)
    {
        return $this->postJson("/api/v1/policies/{$p->id}/renewal-followups", $body + ['channel' => 'phone']);
    }

    public function test_category_and_subcategory_filters_follow_the_product_taxonomy(): void
    {
        $fire = $this->makeProduct('FIRE1', $this->carrierA, ['type' => 'motor', 'category' => self::FIRE, 'sub_category' => 'อัคคีภัยพื้นฐาน', 'name' => 'อัคคีภัยบ้าน']);
        $legacyFire = $this->makeProduct('FIRE2', $this->carrierA, ['type' => 'motor', 'category' => self::FIRE, 'sub_category' => 'อัคคีภัย', 'name' => 'อัคคีภัยเก่า']);
        $motor = $this->makePolicy();
        $firePolicy = $this->makePolicy(['product_id' => $fire->id, 'motor_license_no' => null]);
        $legacyPolicy = $this->makePolicy(['product_id' => $legacyFire->id, 'motor_license_no' => null]);
        $this->actingAsStaff();

        $ids = fn (array $q) => collect($this->getJson('/api/v1/reports/expiring-soon?'.http_build_query($q + ['days' => 60, 'perPage' => 200]))->json('data'))->pluck('policyId')->all();

        $this->assertSame([(string) $motor->id], $ids(['productCategory' => self::MOTOR]));
        $this->assertEqualsCanonicalizing([(string) $firePolicy->id, (string) $legacyPolicy->id], $ids(['productCategory' => self::FIRE]));
        $this->assertSame([(string) $firePolicy->id], $ids(['productCategory' => self::FIRE, 'productSubCategory' => 'อัคคีภัยพื้นฐาน']));
        $this->assertSame([(string) $legacyPolicy->id], $ids(['productCategory' => self::FIRE, 'productSubCategory' => '__none']));

        $row = $this->row(['days' => 60], (string) $motor->id);
        $this->assertSame('รถยนต์ชั้น 1 ซ่อมอู่', $row['productName']);
        $this->assertSame(self::MOTOR, $row['productCategory']);
        $this->assertSame('ป1.', $row['productSubCategory']);
        $this->assertSame($motor->motor_license_no, $row['motorLicenseNo']);
    }

    public function test_pdf_export_accepts_agent_category_and_due_filters(): void
    {
        $this->makePolicy();
        $this->actingAsStaff();

        $res = $this->get('/api/v1/reports/expiring-soon/pdf?'.http_build_query([
            'days' => 60, 'writingAgentId' => $this->agent->id, 'productCategory' => self::MOTOR, 'followupDue' => 1,
        ]));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('content-type'));
    }

    public function test_follow_ups_form_a_thread_and_feed_the_queue(): void
    {
        $p = $this->makePolicy();
        $this->actingAsStaff();

        $this->followup($p, ['result' => 'unreachable', 'note' => 'ไม่รับสาย'])->assertCreated();
        $next = now()->addDays(3)->toDateString();
        $this->followup($p, ['channel' => 'line', 'result' => 'thinking', 'note' => 'ขอเทียบราคา', 'nextFollowupAt' => $next])
            ->assertCreated()
            ->assertJsonPath('data.result', 'thinking')
            ->assertJsonPath('data.byUserName', $this->staff->name);

        $thread = $this->getJson("/api/v1/policies/{$p->id}/renewal-followups")->assertOk()->json('data');
        $this->assertSame(['thinking', 'unreachable'], array_column($thread, 'result'));

        $row = $this->row(['days' => 60], (string) $p->id);
        $this->assertSame('contacted', $row['renewalStage']);
        $this->assertSame('thinking', $row['lastFollowup']['result']);
        $this->assertSame('line', $row['lastFollowup']['channel']);
        $this->assertSame($next, substr((string) $row['lastFollowup']['nextAt'], 0, 10));
        $this->assertSame($this->staff->name, $row['lastFollowup']['byName']);
    }

    public function test_follow_up_due_filter_ignores_the_expiry_window(): void
    {
        $far = $this->makePolicy(['expiry_date' => now()->addDays(200)->toDateString()]);
        $notDue = $this->makePolicy();
        $this->actingAsStaff();
        $this->followup($far, ['result' => 'thinking', 'nextFollowupAt' => now()->toDateString()])->assertCreated();
        $this->followup($notDue, ['result' => 'thinking', 'nextFollowupAt' => now()->addDays(5)->toDateString()])->assertCreated();

        $res = $this->getJson('/api/v1/reports/expiring-soon?days=60&followupDue=1&perPage=200')->assertOk();
        $this->assertSame([(string) $far->id], collect($res->json('data'))->pluck('policyId')->all());
        $this->assertSame(1, $res->json('summary.followupDueCount'));
        $this->assertSame(1, $this->getJson('/api/v1/reports/expiring-soon?days=60')->json('summary.followupDueCount'));
    }

    public function test_follow_up_validation(): void
    {
        $p = $this->makePolicy();
        $this->actingAsStaff();

        $this->followup($p, ['result' => 'not_renewing'])->assertStatus(422)->assertJsonValidationErrors('reasonCode');
        $this->followup($p, ['result' => 'not_renewing', 'reasonCode' => 'other'])->assertStatus(422)->assertJsonValidationErrors('reasonNote');
        $this->followup($p, ['result' => 'thinking', 'nextFollowupAt' => now()->subDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('nextFollowupAt');
        $this->followup($p, ['result' => 'not_renewing', 'reasonCode' => 'price_too_high', 'nextFollowupAt' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('nextFollowupAt');
        $this->followup($p, ['result' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('result');
    }

    public function test_not_renewing_declines_and_reopen_restores_the_stage(): void
    {
        $p = $this->makePolicy();
        $this->actingAsStaff();
        $this->followup($p, ['result' => 'thinking'])->assertCreated();

        $this->followup($p, ['result' => 'not_renewing', 'reasonCode' => 'price_too_high', 'note' => 'แพงกว่าที่อื่น 2,000'])
            ->assertCreated()->assertJsonPath('data.reasonLabel', 'เบี้ยแพงเกินไป');
        $this->assertSame('declined', $this->row(['days' => 60], (string) $p->id)['renewalStage']);
        $declined = PolicyEvent::query()->where('policy_id', $p->id)->where('type', 'renewalDeclined')->latest('id')->firstOrFail();
        $this->assertSame('price_too_high', $declined->payload['reasonCode']);
        $this->assertSame('เบี้ยแพงเกินไป', $declined->payload['reason']);
        $this->assertTrue($this->getJson("/api/v1/policies/{$p->id}/renewal-followups")->json('meta.declined'));

        $this->postJson("/api/v1/policies/{$p->id}/renewal/reopen", ['note' => 'ลูกค้าโทรกลับมา'])->assertOk();
        $this->assertSame('contacted', $this->row(['days' => 60], (string) $p->id)['renewalStage']);
        $this->assertFalse($this->getJson("/api/v1/policies/{$p->id}/renewal-followups")->json('meta.declined'));
        $this->postJson("/api/v1/policies/{$p->id}/renewal/reopen")->assertStatus(422)->assertJsonPath('code', 'renewal_not_declined');

        $this->followup($p, ['result' => 'not_renewing', 'reasonCode' => 'other', 'reasonNote' => 'ย้ายไปต่างประเทศ'])->assertCreated();
        $this->assertSame('declined', $this->row(['days' => 60], (string) $p->id)['renewalStage']);
    }

    public function test_follow_ups_are_tenant_scoped(): void
    {
        $other = Tenant::create(['slug' => 'other-'.uniqid(), 'name' => 'Other', 'active' => true]);
        $outsider = User::create([
            'tenant_id' => $other->id, 'name' => 'Outsider', 'email' => 'o-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'), 'role' => 'admin', 'locale' => 'th', 'active' => true,
        ]);
        $p = $this->makePolicy();
        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/policies/{$p->id}/renewal-followups")->assertNotFound();
        $this->followup($p, ['result' => 'thinking'])->assertNotFound();
        $this->postJson("/api/v1/policies/{$p->id}/renewal-notices", ['annualPremium' => 100])->assertNotFound();
    }

    public function test_renewal_notices_get_sequential_numbers_and_link_the_generated_pdf(): void
    {
        Storage::fake('local');
        $p = $this->makePolicy();
        $this->actingAsStaff();
        $prefix = 'RN'.(now()->year + 543).'-';

        $this->postJson("/api/v1/policies/{$p->id}/renewal-notices", [
            'annualPremium' => 12500, 'netPremium' => 11000, 'dutyStamp' => 45, 'vat' => 700,
        ])->assertStatus(422)->assertJsonValidationErrors('netPremium');

        $first = $this->postJson("/api/v1/policies/{$p->id}/renewal-notices", [
            'annualPremium' => 12500, 'netPremium' => 11635, 'dutyStamp' => 47, 'vat' => 818, 'discount' => 1000,
            'coverage' => 520000, 'premiumMode' => 'annual',
        ])->assertCreated()->assertJsonPath('data.noticeNo', $prefix.'00001')
            ->assertJsonPath('data.dueDate', $p->expiry_date->toDateString());
        $second = $this->postJson("/api/v1/policies/{$p->id}/renewal-notices", ['annualPremium' => 12600])
            ->assertCreated()->assertJsonPath('data.noticeNo', $prefix.'00002');

        $doc = $this->post("/api/v1/policies/{$p->id}/documents/upload", [
            'type' => 'renewal_quote_insurehub',
            'file' => UploadedFile::fake()->create($prefix.'00001.pdf', 20, 'application/pdf'),
            'quoteAnnualPremium' => 12500,
            'renewalNoticeId' => $first->json('data.id'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $notice = RenewalNotice::findOrFail($first->json('data.id'));
        $this->assertSame((string) $doc, (string) $notice->document_id);
        $event = PolicyEvent::query()->where('policy_id', $p->id)->where('type', 'renewalQuotePrepared')->latest('id')->firstOrFail();
        $this->assertEquals(11635, $event->payload['quote']['netPremium']);
        $this->assertSame($prefix.'00001', $event->payload['quote']['noticeNo']);

        $this->getJson("/api/v1/policies/{$p->id}/renewal-draft")->assertOk()
            ->assertJsonPath('data.mainPremium', 12500)
            ->assertJsonPath('data.coverage', 520000)
            ->assertJsonPath('data.premium.net', 11635)
            ->assertJsonPath('data.premium.vat', 818)
            ->assertJsonPath('meta.needsPremiumFormula', false)
            ->assertJsonPath('meta.quote.noticeNo', $prefix.'00001');

        $list = $this->getJson("/api/v1/policies/{$p->id}/renewal-notices")->assertOk()->json('data');
        $this->assertSame([$second->json('data.id'), $first->json('data.id')], array_column($list, 'id'));
        $this->post("/api/v1/policies/{$p->id}/documents/upload", [
            'type' => 'renewal_quote_insurehub',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            'renewalNoticeId' => 999999,
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_tenant_payment_channels_round_trip(): void
    {
        $this->actingAsAdmin();
        $text = "โอนเข้าบัญชี ธ.กสิกรไทย 123-4-56789-0\nพร้อมเพย์ 0105561234567";
        $this->patchJson('/api/v1/tenant', ['paymentChannels' => $text, 'phone' => '02-555-0100'])->assertOk()
            ->assertJsonPath('data.paymentChannels', $text);
        $this->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.paymentChannels', $text)->assertJsonPath('data.phone', '02-555-0100');
    }

    public function test_product_taxonomy_gaps_report_and_unmatched_filter(): void
    {
        $this->makeProduct('GAP1', $this->carrierA, ['category' => self::FIRE, 'sub_category' => 'อัคคีภัย']);
        $this->makeProduct('GAP2', $this->carrierA, ['category' => self::FIRE, 'sub_category' => 'อัคคีภัย']);
        $this->makeProduct('OK1', $this->carrierA, ['category' => self::FIRE, 'sub_category' => 'อัคคีภัยพื้นฐาน']);
        $this->actingAsStaff();

        $gaps = $this->getJson('/api/v1/products/taxonomy-gaps')->assertOk();
        $fireGap = collect($gaps->json('data'))->first(fn ($g) => $g['category'] === self::FIRE && $g['subCategory'] === 'อัคคีภัย');
        $this->assertSame(2, $fireGap['count']);
        $this->assertSame(5, $gaps->json('meta.total'));

        $codes = collect($this->getJson('/api/v1/products?taxonomy=unmatched&perPage=100')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertContains('GAP1', $codes);
        $this->assertNotContains('OK1', $codes);
        $this->assertNotContains('PA', $codes);

        $this->assertNotNull($this->getJson('/api/v1/product-categories')->json('data.0.id'));
    }

    public function test_policy_detail_exposes_product_and_carrier_names(): void
    {
        $p = $this->makePolicy();
        $this->actingAsStaff();
        $this->getJson("/api/v1/policies/{$p->id}")->assertOk()
            ->assertJsonPath('data.productName', 'รถยนต์ชั้น 1 ซ่อมอู่')
            ->assertJsonPath('data.productCategory', self::MOTOR)
            ->assertJsonPath('data.carrierName', 'Carrier A');
    }
}
