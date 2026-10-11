<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionPayoutBatch extends Model
{
    protected $guarded = ['id'];

    // Batch lifecycle (spec §8.1).
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_GENERATED = 'GENERATED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_PAID = 'PAID';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_REVERSED = 'REVERSED';

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'payment_date' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommissionPayoutBatchItem::class, 'batch_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CommissionPayoutAdjustment::class, 'batch_id');
    }

    /** Per-agent roll-up + payment-tracking rows (one per agent in the batch). */
    public function agentRows(): HasMany
    {
        return $this->hasMany(CommissionPayoutBatchAgent::class, 'batch_id');
    }

    /** The batch this one was created to correct/re-pay (reversal → re-issue). */
    public function reissuedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reissued_from_batch_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_GENERATED, self::STATUS_APPROVED], true);
    }
}
