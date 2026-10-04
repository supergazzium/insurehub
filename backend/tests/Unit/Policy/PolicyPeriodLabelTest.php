<?php

declare(strict_types=1);

namespace Tests\Unit\Policy;

use App\Support\PolicyPeriodLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PolicyPeriodLabelTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'annual' => [1, 0, 1, 1, '1# 1/1'],
            'monthly third' => [1, 0, 3, 12, '1# 3/12'],
            'endorsed' => [1, 2, 3, 12, '1# $2 3/12'],
            'year two endorsed annual' => [2, 1, 1, 1, '2# $1 1/1'],
            'no schedule' => [3, null, null, null, '3#'],
            'endorsement only' => [1, 2, null, null, '1# $2'],
            'year floor' => [0, 0, 1, 1, '1# 1/1'],
        ];
    }

    #[DataProvider('cases')]
    public function test_format(int $year, ?int $endorsement, ?int $no, ?int $total, string $expected): void
    {
        $this->assertSame($expected, PolicyPeriodLabel::format($year, $endorsement, $no, $total));
    }
}
