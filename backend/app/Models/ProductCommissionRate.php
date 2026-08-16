<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCommissionRate extends Model
{
    public const DIRECTION_CARRIER_TO_HUB = 'carrier_to_hub';
    public const DIRECTION_HUB_TO_AGENT = 'hub_to_agent';

    public const SCHEME_FLAT = 'flat';
    public const SCHEME_LIFE_YEARS = 'life_years';

    /** All years covered by scheme=life_years, in policy_year order. */
    public const LIFE_YEAR_COLUMNS = [
        'yr_1', 'yr_2', 'yr_3', 'yr_4', 'yr_5', 'yr_6_10', 'yr_11_up',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'flat_rate' => 'decimal:5',
        'yr_1' => 'decimal:5',
        'yr_2' => 'decimal:5',
        'yr_3' => 'decimal:5',
        'yr_4' => 'decimal:5',
        'yr_5' => 'decimal:5',
        'yr_6_10' => 'decimal:5',
        'yr_11_up' => 'decimal:5',
        'effective_from' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Map a policy_year (1-based) to the column that carries its rate under
     * scheme=life_years. Years 6..10 share yr_6_10; 11+ share yr_11_up.
     */
    public static function lifeYearColumn(int $policyYear): string
    {
        return match (true) {
            $policyYear <= 1 => 'yr_1',
            $policyYear === 2 => 'yr_2',
            $policyYear === 3 => 'yr_3',
            $policyYear === 4 => 'yr_4',
            $policyYear === 5 => 'yr_5',
            $policyYear <= 10 => 'yr_6_10',
            default => 'yr_11_up',
        };
    }
}
