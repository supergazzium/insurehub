<?php

declare(strict_types=1);

namespace Tests\Feature\Policy;

use App\Models\Policy;
use App\Models\PolicyInstallment;
use App\Models\PolicyPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

class CollectionsTest extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        $this->actingAsAdmin();
    }

    private function collections(string $q = ''): array
    {
        return $this->getJson('/api/v1/collections?'.http_build_query(['q' => $q]))->assertOk()->json('data');
    }

    private function pay(Policy $p, float $amount, array $extra = [], array $row = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/policies/{$p->id}/payments", array_merge([
            'payments' => [array_merge(['paymentDate' => '2026-10-01', 'amount' => $amount, 'method' => 'bankTransfer'], $row)],
        ], $extra));
    }

    public function test_unpaid_web_policy_is_listed_even_though_its_gross_premium_field_is_filled(): void
    {
        $p = $this->makePolicy(['policy_no' => 'WEB-UNPAID', 'main_premium' => 11152.42, 'total_premium_paid' => 12000]);
        $due = round((float) PolicyInstallment::query()->where('policy_id', $p->id)->sum('amount_due'), 2);

        $rows = $this->collections('WEB-UNPAID');

        $this->assertCount(1, $rows);
        $this->assertSame((string) $p->id, $rows[0]['policyId']);
        $this->assertSame($due, (float) $rows[0]['totalDue']);
        $this->assertSame(0.0, (float) $rows[0]['paid']);
        $this->assertSame($due, (float) $rows[0]['outstanding']);
        $this->assertFalse($rows[0]['legacySettled']);
    }

    public function test_policy_without_installments_uses_the_larger_of_net_and_gross_premium(): void
    {
        $p = $this->makePolicy(['policy_no' => 'NO-INST', 'main_premium' => 11152.42, 'total_premium_paid' => 12000]);
        PolicyInstallment::query()->where('policy_id', $p->id)->delete();

        $rows = $this->collections('NO-INST');

        $this->assertSame(12000.0, (float) $rows[0]['totalDue']);
    }

    public function test_legacy_policy_without_payments_counts_as_settled_but_partial_legacy_payments_still_show(): void
    {
        $this->makePolicy(['policy_no' => 'LEG-SETTLED', 'imported_from_legacy' => true]);
        $partial = $this->makePolicy(['policy_no' => 'LEG-PARTIAL', 'imported_from_legacy' => true]);
        PolicyPayment::create(['policy_id' => $partial->id, 'payment_date' => '2026-09-01', 'amount' => 5000, 'method' => 'bankTransfer']);

        $this->assertSame([], $this->collections('LEG-SETTLED'));
        $rows = $this->collections('LEG-PARTIAL');
        $this->assertSame(7000.0, (float) $rows[0]['outstanding']);

        $show = $this->getJson('/api/v1/collections/'.$this->makePolicy(['imported_from_legacy' => true])->id)->assertOk()->json('data');
        $this->assertTrue($show['legacySettled']);
        $this->assertSame(0.0, (float) $show['outstanding']);
    }

    public function test_many_settled_policies_do_not_push_an_older_unpaid_policy_out_of_the_list(): void
    {
        $older = $this->makePolicy(['policy_no' => 'OLD-UNPAID']);
        $now = now();
        $policies = [];
        for ($i = 0; $i < 520; $i++) {
            $policies[] = [
                'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'product_id' => $this->productA->id,
                'carrier_id' => $this->carrierA->id, 'writing_agent_id' => $this->agent->id, 'policy_no' => "BULK{$i}",
                'status' => 'active', 'main_premium' => 1000, 'total_premium_paid' => 1000, 'policy_year' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($policies, 200) as $chunk) {
            DB::table('policies')->insert($chunk);
        }
        $ids = DB::table('policies')->where('policy_no', 'like', 'BULK%')->pluck('id');
        DB::table('policy_payments')->insert($ids->map(fn ($id) => [
            'policy_id' => $id, 'payment_date' => '2026-09-01', 'amount' => 1000, 'method' => 'bankTransfer', 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        $rows = $this->collections();
        $this->assertContains((string) $older->id, array_column($rows, 'policyId'));
    }

    public function test_duplicate_payment_needs_confirmation_but_different_installments_are_not_duplicates(): void
    {
        $p = $this->makePolicy();

        $this->pay($p, 3000)->assertCreated();
        $this->pay($p, 3000)->assertStatus(409)->assertJsonPath('code', 'payment_duplicate');
        $this->assertSame(1, PolicyPayment::query()->where('policy_id', $p->id)->count());
        $this->pay($p, 3000, ['confirmDuplicate' => true])->assertCreated();

        $q = $this->makePolicy();
        PolicyInstallment::query()->where('policy_id', $q->id)->delete();
        $i1 = PolicyInstallment::create(['tenant_id' => $this->tenant->id, 'policy_id' => $q->id, 'installment_no' => 1, 'installment_total' => 2, 'due_date' => '2026-09-01', 'amount_due' => 6000, 'amount_paid' => 0, 'status' => 'pending']);
        $i2 = PolicyInstallment::create(['tenant_id' => $this->tenant->id, 'policy_id' => $q->id, 'installment_no' => 2, 'installment_total' => 2, 'due_date' => '2026-10-01', 'amount_due' => 6000, 'amount_paid' => 0, 'status' => 'pending']);
        $this->pay($q, 6000, [], ['installmentId' => $i1->id])->assertCreated();
        $this->pay($q, 6000, [], ['installmentId' => $i2->id])->assertCreated();
    }

    public function test_overpayment_needs_confirmation_including_legacy_policies_already_settled(): void
    {
        $p = $this->makePolicy();
        $this->pay($p, 13000)->assertStatus(409)->assertJsonPath('code', 'payment_overpay')->assertJsonPath('over', 1000);
        $this->pay($p, 13000, ['confirmOverpay' => true])->assertCreated();

        $legacy = $this->makePolicy(['imported_from_legacy' => true]);
        $this->pay($legacy, 500)->assertStatus(409)->assertJsonPath('code', 'payment_overpay');
        $this->assertSame(0, PolicyPayment::query()->where('policy_id', $legacy->id)->count());
    }

    public function test_audit_command_reports_without_writing_and_backfill_skips_web_policies(): void
    {
        $web = $this->makePolicy(['policy_no' => 'WEB-NOPAY']);
        $legacy = $this->makePolicy(['policy_no' => 'LEG-NOPAY', 'imported_from_legacy' => true]);
        $dup = $this->makePolicy(['policy_no' => 'DUP']);
        DB::table('policy_payments')->insert([
            ['policy_id' => $dup->id, 'payment_date' => '2026-09-01', 'amount' => 500, 'method' => 'bankTransfer', 'created_at' => now(), 'updated_at' => now()],
            ['policy_id' => $dup->id, 'payment_date' => '2026-09-01', 'amount' => 500, 'method' => 'bankTransfer', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $before = DB::table('policy_payments')->count();

        $this->assertSame(0, Artisan::call('collections:audit-payments', ['--tenant' => $this->tenant->id]));
        $out = Artisan::output();
        $this->assertSame($before, DB::table('policy_payments')->count());
        $this->assertStringContainsString('duplicate_groups', $out);
        $this->assertStringContainsString('WEB-NOPAY', $out);

        Artisan::call('payments:backfill-legacy', ['--tenant' => $this->tenant->id]);
        $this->assertSame(0, PolicyPayment::query()->where('policy_id', $web->id)->count());
        $this->assertSame(1, PolicyPayment::query()->where('policy_id', $legacy->id)->count());
    }
}
