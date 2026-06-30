<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CommissionRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['run_at' => 'datetime'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function policyEvent(): BelongsTo
    {
        return $this->belongsTo(PolicyEvent::class);
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(CommissionTransaction::class, 'commission_run_transactions');
    }
}
