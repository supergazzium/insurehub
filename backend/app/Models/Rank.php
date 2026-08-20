<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rank extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'level' => 'integer',
        'monthly_avg_target' => 'decimal:2',
        'three_month_accum_target' => 'decimal:2',
        'license_required' => 'boolean',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
