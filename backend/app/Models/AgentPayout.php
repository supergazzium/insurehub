<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentPayout extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'gross_amount' => 'decimal:2',
        'wht_rate' => 'decimal:4',
        'wht_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** All commission_transactions.settled_by_payout_id → this row. */
    public function transactions(): HasMany
    {
        return $this->hasMany(CommissionTransaction::class, 'settled_by_payout_id');
    }
}
