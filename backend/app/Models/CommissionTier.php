<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionTier extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rankRates(): HasMany
    {
        return $this->hasMany(CommissionTierRankRate::class, 'tier_id');
    }
}
