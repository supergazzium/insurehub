<?php

declare(strict_types=1);

namespace App\Services\Commission;

/**
 * Value object returned by BaseRateResolver — the standard commission rate
 * for a (policy, payment) at accrual time.
 *
 * `rate` is the decimal 0..1 fraction the MGM engine multiplies premium by.
 * `source` identifies where the rate came from for the ledger snapshot
 * (e.g. "carrier_product_type_rates:42" or "product_life_rates:dim=7,year=2").
 */
final class BaseRate
{
    public function __construct(
        public readonly float $rate,
        public readonly string $source,
    ) {}
}
