<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyRebate extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'earn_date' => 'date',
        'ov_date' => 'date',
        'agent_receive_date' => 'date',
        'calculated_amount' => 'decimal:2',
        'calculated_ov' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'actual_ov' => 'decimal:2',
        'calculated_agent_amount' => 'decimal:2',
        'actual_agent_amount' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
