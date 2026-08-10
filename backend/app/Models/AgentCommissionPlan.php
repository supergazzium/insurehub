<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCommissionPlan extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'ag_rate' => 'decimal:4',
        'inh_rate' => 'decimal:4',
        'override_rate' => 'decimal:4',
        'valid_start' => 'date',
        'valid_end' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
