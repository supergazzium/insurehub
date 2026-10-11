<?php

declare(strict_types=1);

namespace Tests\Unit\Policy;

use App\Models\Policy;
use App\Models\User;
use App\Support\PolicyYears;
use Tests\TestCase;

/** Task 1.4 — stored ปีของกรมธรรม์ / ปีของลูกค้า. */
class PolicyYearsTest extends TestCase
{
    public function test_ordinal_year_counts_completed_years_from_one(): void
    {
        $this->assertSame(1, PolicyYears::ordinalYear(null, '2027-01-01'));
        $this->assertSame(1, PolicyYears::ordinalYear('2026-10-01', '2026-10-01'));
        $this->assertSame(1, PolicyYears::ordinalYear('2026-10-01', '2027-09-30'));
        $this->assertSame(2, PolicyYears::ordinalYear('2026-10-01', '2027-10-01'));
        $this->assertSame(4, PolicyYears::ordinalYear('2026-10-01', '2029-12-31'));
        // A start after the policy date never goes below year 1.
        $this->assertSame(1, PolicyYears::ordinalYear('2028-01-01', '2027-01-01'));
    }

    public function test_only_admins_change_years_past_quotation(): void
    {
        $active = (new Policy())->forceFill(['status' => 'active', 'policy_year' => 2, 'customer_year' => 3]);
        $ops = (new User())->forceFill(['role' => 'operations']);
        $admin = (new User())->forceFill(['role' => 'admin']);

        $this->assertNotNull(PolicyYears::overrideError($ops, $active, ['customer_year' => 4]));
        $this->assertNotNull(PolicyYears::overrideError($ops, $active, ['policy_year' => '1']));
        $this->assertNull(PolicyYears::overrideError($admin, $active, ['customer_year' => 4]));
        // Re-sending the stored value (wizard autosave) is not a change.
        $this->assertNull(PolicyYears::overrideError($ops, $active, ['policy_year' => '2', 'customer_year' => 3]));
        // Null never overwrites, so it isn't a change either.
        $this->assertNull(PolicyYears::overrideError($ops, $active, ['customer_year' => null]));
    }

    public function test_anyone_edits_years_while_draft_or_quotation(): void
    {
        $ops = (new User())->forceFill(['role' => 'operations']);
        foreach (['draft', 'quotation'] as $status) {
            $p = (new Policy())->forceFill(['status' => $status, 'policy_year' => 1, 'customer_year' => 1]);
            $this->assertNull(PolicyYears::overrideError($ops, $p, ['policy_year' => 5]), $status);
        }
        // New policy (no route model yet).
        $this->assertNull(PolicyYears::overrideError($ops, null, ['policy_year' => 5]));
    }
}
