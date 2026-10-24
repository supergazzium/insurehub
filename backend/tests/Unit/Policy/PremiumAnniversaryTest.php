<?php

declare(strict_types=1);

namespace Tests\Unit\Policy;

use App\Models\Policy;
use App\Models\Product;
use App\Services\Policy\PremiumAnniversary;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit 3 — วันนัดทวงเบี้ยรายปี. Pure service; `asOf` is injected so "today"
 * is deterministic. Anniversaries count from effective_date regardless of
 * the coverage basis.
 */
class PremiumAnniversaryTest extends TestCase
{
    private function policy(array $attrs, ?array $product = null): Policy
    {
        $p = (new Policy())->forceFill($attrs + [
            'effective_date' => '2026-01-01',
            'premium_paying_term' => 15,
        ]);
        if ($product !== null) {
            $p->setRelation('product', (new Product())->forceFill($product));
        }

        return $p;
    }

    public function test_next_due_is_the_first_anniversary_during_year_one(): void
    {
        $p = $this->policy([]);
        $next = PremiumAnniversary::nextDueDate($p, Carbon::parse('2026-06-01'));

        $this->assertSame('2027-01-01', $next?->toDateString());
        $this->assertSame(1, PremiumAnniversary::nextDueYear($p, Carbon::parse('2026-06-01')));
        $this->assertSame(0, PremiumAnniversary::yearsElapsed($p, Carbon::parse('2026-06-01')));
    }

    public function test_next_due_advances_each_year(): void
    {
        $p = $this->policy([]);
        // Just after the 3rd anniversary → next bill is year 4.
        $next = PremiumAnniversary::nextDueDate($p, Carbon::parse('2029-02-01'));

        $this->assertSame('2030-01-01', $next?->toDateString());
        $this->assertSame(4, PremiumAnniversary::nextDueYear($p, Carbon::parse('2029-02-01')));
    }

    public function test_anniversary_today_is_still_due_today_not_skipped(): void
    {
        $p = $this->policy([]);
        $next = PremiumAnniversary::nextDueDate($p, Carbon::parse('2028-01-01'));

        $this->assertSame('2028-01-01', $next?->toDateString());
    }

    public function test_reminders_stop_once_the_term_is_fully_billed(): void
    {
        $p = $this->policy(['premium_paying_term' => 15]);
        // 15-year term: last bill is at the 15th anniversary (2041-01-01).
        $afterLast = Carbon::parse('2041-02-01');

        $this->assertNull(PremiumAnniversary::nextDueDate($p, $afterLast));
        $this->assertTrue(PremiumAnniversary::termComplete($p, $afterLast));
    }

    public function test_last_billable_anniversary_is_within_term(): void
    {
        $p = $this->policy(['premium_paying_term' => 15]);
        // Between the 14th and 15th anniversary → year 15 is still owed.
        $next = PremiumAnniversary::nextDueDate($p, Carbon::parse('2040-06-01'));

        $this->assertSame('2041-01-01', $next?->toDateString());
        $this->assertSame(15, PremiumAnniversary::nextDueYear($p, Carbon::parse('2040-06-01')));
        $this->assertFalse(PremiumAnniversary::termComplete($p, Carbon::parse('2040-06-01')));
    }

    public function test_term_falls_back_to_product_pay_years(): void
    {
        $p = $this->policy(['premium_paying_term' => null], ['pay_years' => 10]);

        $this->assertSame(10, $p->effective_premium_term);
        $this->assertSame('2027-01-01', PremiumAnniversary::nextDueDate($p, Carbon::parse('2026-06-01'))?->toDateString());
        // After the 10th anniversary the shorter term is done.
        $this->assertNull(PremiumAnniversary::nextDueDate($p, Carbon::parse('2036-06-01')));
    }

    public function test_no_term_means_no_reminders(): void
    {
        $p = $this->policy(['premium_paying_term' => null]);
        $p->setRelation('product', (new Product())->forceFill(['pay_years' => 1])); // single-pay / annual-only

        $this->assertNull(PremiumAnniversary::nextDueDate($p, Carbon::parse('2027-06-01')));
    }

    public function test_missing_effective_date_is_safe(): void
    {
        $p = $this->policy(['effective_date' => null]);

        $this->assertNull(PremiumAnniversary::nextDueDate($p, Carbon::parse('2026-06-01')));
        $this->assertNull(PremiumAnniversary::yearsElapsed($p, Carbon::parse('2026-06-01')));
        $this->assertNull(PremiumAnniversary::termComplete($p, Carbon::parse('2026-06-01')));
    }

    public function test_snapshot_shape(): void
    {
        $p = $this->policy([]);
        $snap = PremiumAnniversary::forPolicy($p, Carbon::parse('2026-06-01'));

        $this->assertSame([
            'term' => 15,
            'yearsElapsed' => 0,
            'nextDueDate' => '2027-01-01',
            'nextDueYear' => 1,
            'termComplete' => false,
        ], $snap);
    }
}
