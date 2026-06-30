<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralBonusConfig extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'flat_amount' => 'decimal:2',
        'pct_value' => 'decimal:4',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
