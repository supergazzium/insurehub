<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionReceivable extends Model
{
    protected $guarded = ['id'];

    public const TYPE_MAIN = 'MAIN';
    public const TYPE_OV = 'OV';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_MATCHED = 'Matched';
    public const STATUS_MISMATCH = 'Mismatch';
    public const STATUS_RECEIVED = 'Received';
    public const STATUS_NO_COMMISSION = 'No Commission';

    /** Amount equality tolerance (spec §10 "Matched per Precision"). */
    public const TOLERANCE = 0.01;

    protected $casts = [
        'received_date' => 'date',
        'expected_amount' => 'decimal:2',
        'statement_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'insurer_id');
    }

    public function receiptBatch(): BelongsTo
    {
        return $this->belongsTo(CommissionReceiptBatch::class, 'receipt_batch_id');
    }

    /** difference = (statement or received) - expected. */
    public function difference(): ?float
    {
        $actual = $this->received_amount ?? $this->statement_amount;
        if ($actual === null) {
            return null;
        }

        return round((float) $actual - (float) $this->expected_amount, 2);
    }
}
