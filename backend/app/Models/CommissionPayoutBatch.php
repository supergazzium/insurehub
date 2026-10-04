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

    public const CALC_V1 = 1;

    public const CALC_V2 = 2;

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'payment_date' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'calc_version' => 'integer',
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

    public function payees(): HasMany
    {
        return $this->hasMany(CommissionPayoutPayee::class, 'batch_id');
    }

    public function isV2(): bool
    {
        return (int) $this->calc_version >= self::CALC_V2;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_GENERATED, self::STATUS_APPROVED], true);
    }
}
