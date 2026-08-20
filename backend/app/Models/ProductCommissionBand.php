<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCommissionBand extends Model
{
    public const DIRECTION_CARRIER_TO_HUB = 'carrier_to_hub';
    public const DIRECTION_HUB_TO_AGENT = 'hub_to_agent';

    protected $guarded = ['id'];

    protected $casts = [
        'sum_assured_min' => 'decimal:2',
        'sum_assured_max' => 'decimal:2',
        'entry_age_min' => 'integer',
        'entry_age_max' => 'integer',
        'yr_1' => 'decimal:5',
        'yr_2' => 'decimal:5',
        'yr_3' => 'decimal:5',
        'yr_4' => 'decimal:5',
        'yr_5' => 'decimal:5',
        'yr_6_up' => 'decimal:5',
        'effective_from' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * True if this band covers the given sum-assured and entry-age. Nullable
     * min = -∞ (band covers 0), nullable max = +∞ (band covers any large
     * value). Same for age.
     */
    public function matches(float $sumAssured, ?int $entryAge): bool
    {
        if ($this->sum_assured_min !== null && $sumAssured < (float) $this->sum_assured_min) {
            return false;
        }
        if ($this->sum_assured_max !== null && $sumAssured > (float) $this->sum_assured_max) {
            return false;
        }
        if ($this->entry_age_min !== null || $this->entry_age_max !== null) {
            if ($entryAge === null) {
                // Band has an age constraint but the caller couldn't compute one.
                return false;
            }
            if ($this->entry_age_min !== null && $entryAge < (int) $this->entry_age_min) {
                return false;
            }
            if ($this->entry_age_max !== null && $entryAge > (int) $this->entry_age_max) {
                return false;
            }
        }

        return true;
    }

    /** Map a policy_year (1-based) to the column carrying its rate. */
    public static function yearColumn(int $policyYear): string
    {
        return match (true) {
            $policyYear <= 1 => 'yr_1',
            $policyYear === 2 => 'yr_2',
            $policyYear === 3 => 'yr_3',
            $policyYear === 4 => 'yr_4',
            $policyYear === 5 => 'yr_5',
            default => 'yr_6_up',
        };
    }
}
