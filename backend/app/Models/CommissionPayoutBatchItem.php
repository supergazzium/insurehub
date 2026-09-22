<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionPayoutBatchItem extends Model
{
    protected $guarded = ['id'];

    public const ITEM_PENDING = 'pending';
    public const ITEM_PAID = 'paid';
    public const ITEM_EXCLUDED = 'excluded';

    protected $casts = [
        'snapshot_base_premium' => 'decimal:2',
        'snapshot_agent_commission' => 'decimal:2',
        'snapshot_rider_commission' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommissionPayoutBatch::class, 'batch_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** Frozen total for this item = main + rider agent commission. */
    public function total(): float
    {
        return (float) $this->snapshot_agent_commission + (float) $this->snapshot_rider_commission;
    }
}
