<?php

declare(strict_types=1);

namespace Tests\Unit\Commission;

use App\Models\Policy;
use App\Models\ProductCommissionBand;
use App\Models\ProductCommissionRate;
use App\Services\Commission\CommissionSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * C-20: the frozen-commission snapshot must read back the same rate rows and
 * bands that were captured, so the resolvers behave identically to the live
 * tables — but immutably. These tests exercise the reader with a hand-built
 * snapshot array (no DB needed); the create-time capture + resolver-preference
 * path is proven end-to-end against the live DB.
 */
class CommissionSnapshotTest extends TestCase
{
    private function policyWith(?array $snapshot): Policy
    {
        // The model casts commission_snapshot as 'array'. Reading it back
        // returns the array as-is when set to an array in memory, so the
        // reader (which calls $policy->commission_snapshot) sees the same
        // structure it would after a JSON round-trip from the DB.
        return (new Policy())->forceFill(['commission_snapshot' => $snapshot]);
    }

    public function test_returns_null_when_no_snapshot(): void
    {
        $this->assertNull(CommissionSnapshot::fromPolicy($this->policyWith(null)));
    }

    public function test_returns_null_on_unknown_version(): void
    {
        $this->assertNull(CommissionSnapshot::fromPolicy($this->policyWith([
            'v' => 999, 'rates' => [], 'bands' => [],
        ])));
    }

    public function test_rate_row_reads_frozen_flat_rate_for_direction(): void
    {
        $snap = CommissionSnapshot::fromPolicy($this->policyWith([
            'v' => CommissionSnapshot::VERSION,
            'captured_at' => '2026-08-23T00:00:00+00:00',
            'product_id' => 895,
            'rates' => [
                ['direction' => 'hub_to_agent', 'scheme' => 'flat', 'flat_rate' => '0.10000', 'effective_from' => null],
                ['direction' => 'carrier_to_hub', 'scheme' => 'flat', 'flat_rate' => '0.18000', 'effective_from' => null],
            ],
            'bands' => [],
        ]));

        $this->assertNotNull($snap);
        $row = $snap->rateRow(ProductCommissionRate::DIRECTION_HUB_TO_AGENT);
        $this->assertInstanceOf(ProductCommissionRate::class, $row);
        $this->assertNull($row->id, 'snapshot rows are unsaved');
        $this->assertSame('flat', $row->scheme);
        $this->assertSame(0.10, (float) $row->flat_rate);

        // both directions preserved
        $carrier = $snap->rateRow(ProductCommissionRate::DIRECTION_CARRIER_TO_HUB);
        $this->assertSame(0.18, (float) $carrier->flat_rate);
    }

    public function test_rate_row_newest_effective_from_wins(): void
    {
        $snap = CommissionSnapshot::fromPolicy($this->policyWith([
            'v' => CommissionSnapshot::VERSION,
            'rates' => [
                ['direction' => 'hub_to_agent', 'scheme' => 'flat', 'flat_rate' => '0.08000', 'effective_from' => '2025-01-01'],
                ['direction' => 'hub_to_agent', 'scheme' => 'flat', 'flat_rate' => '0.12000', 'effective_from' => '2026-01-01'],
            ],
            'bands' => [],
        ]));

        $row = $snap->rateRow(ProductCommissionRate::DIRECTION_HUB_TO_AGENT);
        $this->assertSame(0.12, (float) $row->flat_rate, 'newest effective_from must win');
    }

    public function test_bands_read_back_ordered_and_matchable(): void
    {
        $snap = CommissionSnapshot::fromPolicy($this->policyWith([
            'v' => CommissionSnapshot::VERSION,
            'rates' => [],
            'bands' => [
                ['direction' => 'hub_to_agent', 'band_seq' => 2, 'sum_assured_min' => 1000000, 'sum_assured_max' => null, 'entry_age_min' => null, 'entry_age_max' => null, 'yr_1' => '0.30000'],
                ['direction' => 'hub_to_agent', 'band_seq' => 1, 'sum_assured_min' => null, 'sum_assured_max' => 999999.99, 'entry_age_min' => null, 'entry_age_max' => null, 'yr_1' => '0.20000'],
                ['direction' => 'carrier_to_hub', 'band_seq' => 1, 'sum_assured_min' => null, 'sum_assured_max' => null, 'entry_age_min' => null, 'entry_age_max' => null, 'yr_1' => '0.40000'],
            ],
        ]));

        $bands = $snap->bands(ProductCommissionBand::DIRECTION_HUB_TO_AGENT);
        $this->assertCount(2, $bands, 'only hub_to_agent bands');
        $this->assertSame(1, (int) $bands[0]->band_seq, 'ordered by band_seq');
        $this->assertSame(2, (int) $bands[1]->band_seq);

        // matching logic still works on the rehydrated models
        $this->assertTrue($bands[0]->matches(500000.0, null));   // under 1M → seq 1
        $this->assertFalse($bands[0]->matches(2000000.0, null)); // over cap → no
        $this->assertTrue($bands[1]->matches(2000000.0, null));  // ≥1M → seq 2
    }
}
