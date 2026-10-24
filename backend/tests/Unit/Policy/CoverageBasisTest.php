<?php

declare(strict_types=1);

namespace Tests\Unit\Policy;

use App\Models\Policy;
use App\Models\Product;
use Tests\TestCase;

/**
 * Unit 2 — ฐานการคำนวณระยะคุ้มครอง. Coverage-end derivation is a pure model
 * getter, so these build in-memory policies (no DB).
 */
class CoverageBasisTest extends TestCase
{
    private function policy(array $attrs, ?array $product = null): Policy
    {
        $p = (new Policy())->forceFill($attrs + [
            'effective_date' => '2026-01-01',
            'insured_person_birth_date' => '1990-06-15',
        ]);
        if ($product !== null) {
            $p->setRelation('product', (new Product())->forceFill($product));
        }

        return $p;
    }

    public function test_age_plan_counts_from_birthday(): void
    {
        $p = $this->policy([
            'coverage_basis' => 'age',
            'coverage_to_age' => 90,
            'premium_paying_term' => 15,
        ]);

        $this->assertSame('age', $p->effective_coverage_basis);
        $this->assertSame('2080-06-15', $p->coverage_end_date?->toDateString()); // 1990-06-15 + 90y
        $this->assertSame('15/90', $p->term_label);
    }

    public function test_years_plan_counts_from_effective_date(): void
    {
        $p = $this->policy([
            'coverage_basis' => 'years',
            'coverage_years' => 15,
            'premium_paying_term' => 15,
        ]);

        $this->assertSame('years', $p->effective_coverage_basis);
        $this->assertSame('2041-01-01', $p->coverage_end_date?->toDateString()); // 2026-01-01 + 15y
        $this->assertSame('15/15ปี', $p->term_label);
    }

    public function test_renewable_plan_has_no_derived_end(): void
    {
        $p = $this->policy(['coverage_basis' => 'renewable']);

        $this->assertSame('renewable', $p->effective_coverage_basis);
        $this->assertNull($p->coverage_end_date);
    }

    public function test_basis_is_inferred_when_unset_from_whichever_length_is_pinned(): void
    {
        $toAge = $this->policy(['coverage_to_age' => 85]);
        $this->assertSame('age', $toAge->effective_coverage_basis);
        $this->assertSame('2075-06-15', $toAge->coverage_end_date?->toDateString());

        $fixed = $this->policy(['coverage_years' => 10]);
        $this->assertSame('years', $fixed->effective_coverage_basis);
        $this->assertSame('2036-01-01', $fixed->coverage_end_date?->toDateString());
    }

    public function test_policy_overrides_product_default(): void
    {
        $p = $this->policy(
            ['coverage_basis' => 'years', 'coverage_years' => 20],
            ['coverage_basis' => 'age', 'coverage_to_age' => 99],
        );

        $this->assertSame('years', $p->effective_coverage_basis);
        $this->assertSame('2046-01-01', $p->coverage_end_date?->toDateString());
    }

    public function test_product_default_used_when_policy_unset(): void
    {
        $p = $this->policy(
            [],
            ['coverage_basis' => 'years', 'coverage_years' => 12],
        );

        $this->assertSame('years', $p->effective_coverage_basis);
        $this->assertSame('2038-01-01', $p->coverage_end_date?->toDateString());
    }

    public function test_age_plan_without_birthdate_yields_null_not_a_guess(): void
    {
        $p = $this->policy(['coverage_basis' => 'age', 'coverage_to_age' => 90, 'insured_person_birth_date' => null]);
        $this->assertNull($p->coverage_end_date);
    }

    public function test_years_plan_without_effective_date_yields_null(): void
    {
        $p = $this->policy(['coverage_basis' => 'years', 'coverage_years' => 15, 'effective_date' => null]);
        $this->assertNull($p->coverage_end_date);
    }
}
