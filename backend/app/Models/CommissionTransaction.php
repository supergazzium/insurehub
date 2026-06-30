<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CommissionTransaction extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'base_premium' => 'decimal:2',
        'diff_pct' => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function policyEvent(): BelongsTo
    {
        return $this->belongsTo(PolicyEvent::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_txn_id');
    }

    public function runs(): BelongsToMany
    {
        return $this->belongsToMany(CommissionRun::class, 'commission_run_transactions');
    }
}
