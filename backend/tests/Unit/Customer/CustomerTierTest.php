<?php

declare(strict_types=1);

namespace Tests\Unit\Customer;

use App\Support\CustomerTier;
use PHPUnit\Framework\TestCase;

class CustomerTierTest extends TestCase
{
    public function test_defaults_classify_by_cumulative_premium(): void
    {
        $tiers = CustomerTier::normalize(CustomerTier::DEFAULTS);

        $this->assertSame('vip', CustomerTier::classify(100000, $tiers)['key']);
        $this->assertSame('gold', CustomerTier::classify(99999.99, $tiers)['key']);
        $this->assertSame('silver', CustomerTier::classify(20000, $tiers)['key']);
        $this->assertNull(CustomerTier::classify(19999, $tiers));
    }

    public function test_custom_thresholds_labels_and_disabled_tier(): void
    {
        $tiers = CustomerTier::normalize([
            ['key' => 'vip', 'label' => 'ลูกค้าคนสำคัญ', 'min' => 500000],
            ['key' => 'gold', 'label' => '', 'min' => null],
            ['key' => 'silver', 'min' => 10000],
        ]);

        $this->assertSame(['vip', 'silver'], array_column($tiers, 'key'));
        $this->assertSame('ลูกค้าคนสำคัญ', CustomerTier::classify(600000, $tiers)['label']);
        $this->assertSame('silver', CustomerTier::classify(200000, $tiers)['key']);
        $this->assertSame('Silver', CustomerTier::label('silver', $tiers));
    }

    public function test_case_sql_orders_highest_first(): void
    {
        [$sql, $bindings] = CustomerTier::caseSql('x', CustomerTier::normalize(CustomerTier::DEFAULTS));

        $this->assertSame('CASE WHEN x >= ? THEN ? WHEN x >= ? THEN ? WHEN x >= ? THEN ? ELSE NULL END', $sql);
        $this->assertSame([100000.0, 'vip', 50000.0, 'gold', 20000.0, 'silver'], $bindings);
    }
}
