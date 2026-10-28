<?php

declare(strict_types=1);

namespace Tests\Unit\Customer;

use App\Support\CustomerTier;
use PHPUnit\Framework\TestCase;

class CustomerTierTest extends TestCase
{
    public function test_defaults_classify_by_cumulative_premium(): void
    {
        // 5-tier Excel spec: silver 0–15k, gold 15,001–100k, platinum 100,001–300k,
        // diamond 300,001–700k, black_diamond ≥700,001.
        $tiers = CustomerTier::normalize(CustomerTier::DEFAULTS);

        $this->assertSame('black_diamond', CustomerTier::classify(700001, $tiers)['key']);
        $this->assertSame('diamond', CustomerTier::classify(700000, $tiers)['key']);
        $this->assertSame('diamond', CustomerTier::classify(300001, $tiers)['key']);
        $this->assertSame('platinum', CustomerTier::classify(300000, $tiers)['key']);
        $this->assertSame('platinum', CustomerTier::classify(100001, $tiers)['key']);
        $this->assertSame('gold', CustomerTier::classify(100000, $tiers)['key']);
        $this->assertSame('gold', CustomerTier::classify(15001, $tiers)['key']);
        $this->assertSame('silver', CustomerTier::classify(15000, $tiers)['key']);
        // Silver is the floor (min 0) — everyone with any premium is at least silver.
        $this->assertSame('silver', CustomerTier::classify(0, $tiers)['key']);
    }

    public function test_custom_thresholds_labels_and_disabled_tier(): void
    {
        // normalize() MERGES overrides onto DEFAULTS: here we move platinum to
        // 500k, disable gold (null min → dropped), and lower silver to 10k. The
        // untouched black_diamond/diamond keep their default mins.
        $tiers = CustomerTier::normalize([
            ['key' => 'platinum', 'label' => 'ลูกค้าคนสำคัญ', 'min' => 500000],
            ['key' => 'gold', 'label' => '', 'min' => null], // disabled (null min)
            ['key' => 'silver', 'min' => 10000],
        ]);

        // gold disabled → dropped; the rest remain, ordered highest-min first.
        $this->assertSame(['black_diamond', 'platinum', 'diamond', 'silver'], array_column($tiers, 'key'));
        $this->assertSame('ลูกค้าคนสำคัญ', CustomerTier::classify(600000, $tiers)['label']);  // platinum @500k
        $this->assertSame('silver', CustomerTier::classify(200000, $tiers)['key']);           // below platinum, above silver-10k... but diamond@300k not reached
        $this->assertSame('I_Silver', CustomerTier::label('silver', $tiers));
    }

    public function test_case_sql_orders_highest_first(): void
    {
        [$sql, $bindings] = CustomerTier::caseSql('x', CustomerTier::normalize(CustomerTier::DEFAULTS));

        $this->assertSame(
            'CASE WHEN x >= ? THEN ? WHEN x >= ? THEN ? WHEN x >= ? THEN ? WHEN x >= ? THEN ? WHEN x >= ? THEN ? ELSE NULL END',
            $sql,
        );
        $this->assertSame(
            [700001.0, 'black_diamond', 300001.0, 'diamond', 100001.0, 'platinum', 15001.0, 'gold', 0.0, 'silver'],
            $bindings,
        );
    }
}
