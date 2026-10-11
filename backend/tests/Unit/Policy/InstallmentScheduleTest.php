<?php

declare(strict_types=1);

namespace Tests\Unit\Policy;

use App\Models\Policy;
use App\Services\Policy\InstallmentSchedule;
use Tests\TestCase;

/**
 * Task 1.2 — ตารางงวดชำระ. The plan is pure (reads the model only), so these
 * build in-memory policies; the DB-backed sync/reallocate path is exercised
 * end-to-end against the dev database.
 */
class InstallmentScheduleTest extends TestCase
{
    private function policy(array $attrs): Policy
    {
        return (new Policy())->forceFill($attrs + [
            'premium_mode' => 'annual',
            'main_premium' => 0,
            'compulsory_premium' => 0,
            'installment_term' => null,
            'installment_mode' => null,
            'effective_date' => '2026-01-31',
        ]);
    }

    public function test_monthly_policy_gets_twelve_installments_summing_to_the_premium(): void
    {
        $plan = InstallmentSchedule::plan($this->policy(['premium_mode' => 'monthly', 'main_premium' => 10000]));

        $this->assertCount(12, $plan);
        $this->assertSame(range(1, 12), array_column($plan, 'no'));
        $this->assertSame([12], array_values(array_unique(array_column($plan, 'total'))));
        $this->assertEqualsWithDelta(10000.00, array_sum(array_column($plan, 'amount')), 0.001);
        // 10000 / 12 = 833.33… — floored on งวด 1..11, remainder on the last.
        $this->assertSame(833.33, $plan[0]['amount']);
        $this->assertSame(833.37, $plan[11]['amount']);
    }

    public function test_compulsory_is_part_of_the_total(): void
    {
        $plan = InstallmentSchedule::plan($this->policy([
            'premium_mode' => 'quarterly', 'main_premium' => 20000, 'compulsory_premium' => 645.21,
        ]));

        $this->assertCount(4, $plan);
        $this->assertEqualsWithDelta(20645.21, array_sum(array_column($plan, 'amount')), 0.001);
    }

    public function test_due_dates_follow_the_frequency_without_month_overflow(): void
    {
        $quarterly = InstallmentSchedule::plan($this->policy(['premium_mode' => 'quarterly', 'main_premium' => 4000]));
        $this->assertSame(['2026-01-31', '2026-04-30', '2026-07-31', '2026-10-31'], array_column($quarterly, 'dueDate'));

        $monthly = InstallmentSchedule::plan($this->policy(['premium_mode' => 'monthly', 'main_premium' => 1200]));
        $this->assertSame('2026-02-28', $monthly[1]['dueDate']);
    }

    public function test_first_due_date_wins_over_effective_date(): void
    {
        $plan = InstallmentSchedule::plan($this->policy([
            'premium_mode' => 'semiannual', 'main_premium' => 5000, 'first_due_inst_date' => '2026-03-15',
        ]));

        $this->assertSame(['2026-03-15', '2026-09-15'], array_column($plan, 'dueDate'));
    }

    public function test_annual_and_single_are_one_installment(): void
    {
        foreach (['annual', 'single'] as $mode) {
            $plan = InstallmentSchedule::plan($this->policy(['premium_mode' => $mode, 'main_premium' => 9999.99]));
            $this->assertCount(1, $plan, $mode);
            $this->assertSame(9999.99, $plan[0]['amount']);
            $this->assertSame(1, $plan[0]['total']);
        }
    }

    public function test_explicit_installment_term_overrides_the_frequency(): void
    {
        $plan = InstallmentSchedule::plan($this->policy([
            'premium_mode' => 'annual', 'main_premium' => 6000, 'installment_term' => '3',
        ]));

        $this->assertCount(3, $plan);
        $this->assertSame([2000.0, 2000.0, 2000.0], array_column($plan, 'amount'));
    }

    public function test_insurehub_plan_uses_the_installment_engine(): void
    {
        // Mode A, 6 งวด: 25% down on งวด 1, the rest flat — no fee/interest to the customer.
        $plan = InstallmentSchedule::plan($this->policy([
            'premium_mode' => 'annual', 'main_premium' => 12000, 'installment_term' => '6', 'installment_mode' => 'A',
        ]));
        $this->assertSame([3000.0, 1800.0, 1800.0, 1800.0, 1800.0, 1800.0], array_column($plan, 'amount'));
        // InsureHub plans are billed monthly regardless of premium_mode.
        $this->assertSame('2026-02-28', $plan[1]['dueDate']);

        // Mode C, 2 งวด, with พ.ร.บ.: งวด 1 = half + พ.ร.บ. + 2% fee + 1%×2 interest, ceiling-rounded.
        $c = InstallmentSchedule::plan($this->policy([
            'main_premium' => 10000, 'compulsory_premium' => 645.21, 'installment_term' => '2', 'installment_mode' => 'C',
        ]));
        $this->assertSame([(float) ceil(5000 + 645.21 + 200 + 200), 5000.0], array_column($c, 'amount'));
    }

    public function test_no_premium_means_no_schedule(): void
    {
        $this->assertSame([], InstallmentSchedule::plan($this->policy(['premium_mode' => 'monthly'])));
    }

    public function test_even_split_never_loses_a_satang(): void
    {
        foreach ([[100.0, 3], [0.05, 4], [12345.67, 12], [1.0, 1]] as [$total, $n]) {
            $parts = InstallmentSchedule::evenSplit($total, $n);
            $this->assertCount($n, $parts);
            $this->assertSame((int) round($total * 100), (int) round(array_sum($parts) * 100), "{$total}/{$n}");
        }
    }
}
