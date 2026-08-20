<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberVolumeAccumulation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'personal_sales_volume' => 'decimal:2',
        'team_sales_volume' => 'decimal:2',
        'rolling_3_month_volume' => 'decimal:2',
        'recomputed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
