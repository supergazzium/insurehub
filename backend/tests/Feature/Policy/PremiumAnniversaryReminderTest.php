<?php

declare(strict_types=1);

namespace Tests\Feature\Policy;

use App\Models\PaymentReminder;
use App\Models\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Policy\Concerns\BuildsPolicyFixtures;
use Tests\TestCase;

/**
 * Unit 4 — ทวงเบี้ยรายปี cron. Time is frozen so "today" and the anniversary
 * window are deterministic.
 */
class PremiumAnniversaryReminderTest extends TestCase
{
    use BuildsPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        // A life product that pays for 15 years.
        $this->productA->update(['type' => 'life', 'pay_years' => 15, 'coverage_to_age' => 90, 'coverage_basis' => 'age']);
    }

    private function runCron(array $opts = []): void
    {
        Artisan::call('premium:anniversary-reminders', $opts);
    }

    private function systemReminders(Policy $p)
    {
        return PaymentReminder::where('policy_id', $p->id)->where('kind', 'system')->get();
    }

    public function test_raises_a_system_reminder_when_the_anniversary_is_within_the_lead_window(): void
    {
        // Effective 2025-02-01 → first anniversary 2026-02-01. Today 2026-01-20
        // is 12 days out, inside the default 30-day lead window.
        Carbon::setTestNow('2026-01-20');
        $p = $this->makePolicy(['effective_date' => '2025-02-01', 'premium_paying_term' => 15, 'annual_premium' => 24000]);

        $this->runCron();

        $reminders = $this->systemReminders($p);
        $this->assertCount(1, $reminders);
        $this->assertSame('2026-02-01', $reminders[0]->due_date->toDateString());
        $this->assertSame('system', $reminders[0]->channel);
        $this->assertSame(24000.0, (float) $reminders[0]->amount_due);
        $this->assertStringContainsString('ปีที่ 1/15', (string) $reminders[0]->note);
    }

    public function test_does_not_raise_outside_the_lead_window(): void
    {
        // Anniversary 2026-02-01 is 60 days away → outside the 30-day window.
        Carbon::setTestNow('2025-12-03');
        $p = $this->makePolicy(['effective_date' => '2025-02-01', 'premium_paying_term' => 15]);

        $this->runCron();

        $this->assertCount(0, $this->systemReminders($p));
    }

    public function test_is_idempotent_across_repeat_runs(): void
    {
        Carbon::setTestNow('2026-01-20');
        $p = $this->makePolicy(['effective_date' => '2025-02-01', 'premium_paying_term' => 15]);

        $this->runCron();
        $this->runCron();
        $this->runCron();

        $this->assertCount(1, $this->systemReminders($p));
    }

    public function test_stops_after_the_paying_term_is_complete(): void
    {
        // 15-year term, effective 2005-02-01 → last bill 2020-02-01. By 2026 the
        // term is จ่ายครบ; no anniversary is owed.
        Carbon::setTestNow('2026-01-20');
        $p = $this->makePolicy(['effective_date' => '2005-02-01', 'premium_paying_term' => 15]);

        $this->runCron();

        $this->assertCount(0, $this->systemReminders($p));
    }

    public function test_dry_run_writes_nothing(): void
    {
        Carbon::setTestNow('2026-01-20');
        $p = $this->makePolicy(['effective_date' => '2025-02-01', 'premium_paying_term' => 15]);

        $this->runCron(['--dry-run' => true]);

        $this->assertCount(0, $this->systemReminders($p));
    }

    public function test_system_reminders_do_not_pollute_the_manual_dunning_history(): void
    {
        Carbon::setTestNow('2026-01-20');
        $p = $this->makePolicy(['effective_date' => '2025-02-01', 'premium_paying_term' => 15]);

        // A real staff dunning entry.
        PaymentReminder::create([
            'tenant_id' => $this->tenant->id, 'policy_id' => $p->id, 'kind' => 'manual',
            'channel' => 'phone', 'note' => 'โทรแล้ว', 'created_by_user_id' => $this->admin->id,
        ]);
        $this->runCron();

        // The collections "chased N times" view counts manual only.
        $this->actingAsAdmin();
        $row = $this->getJson("/api/v1/collections/{$p->id}")->assertOk()->json('data');
        $this->assertSame(1, (int) $row['reminderCount']);

        // But both kinds exist on the policy.
        $this->assertSame(1, PaymentReminder::where('policy_id', $p->id)->where('kind', 'manual')->count());
        $this->assertSame(1, PaymentReminder::where('policy_id', $p->id)->where('kind', 'system')->count());
    }

    public function test_term_falls_back_to_product_pay_years(): void
    {
        Carbon::setTestNow('2026-01-20');
        // No per-policy term → product pay_years = 15 applies.
        $p = $this->makePolicy(['effective_date' => '2025-02-01', 'premium_paying_term' => null]);

        $this->runCron();

        $this->assertCount(1, $this->systemReminders($p));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
