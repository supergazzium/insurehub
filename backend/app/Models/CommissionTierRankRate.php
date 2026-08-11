<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionTierRankRate extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'mgmt_fee_rate' => 'decimal:5',
        'referral_fee_rate' => 'decimal:5',
        'valid_start' => 'date',
        'valid_end' => 'date',
    ];

    public function tier(): BelongsTo
    {
        return $this->belongsTo(CommissionTier::class, 'tier_id');
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }
}
