<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-agent roll-up + payment state within one payout batch. Each agent is a
 * separate bank transfer, so `paid` / `payment_reference` live here (per agent),
 * while the payout DATE stays on the batch. See the migration for the full
 * rationale.
 */
class CommissionPayoutBatchAgent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'gross_commission' => 'decimal:2',
        'deduct_amount' => 'decimal:2',
        'wht_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'net_transfer' => 'decimal:2',
        'vat_expected_net' => 'decimal:2',
        'paid' => 'boolean',
        'paid_at' => 'datetime',
        'pdf_generated_at' => 'datetime',
        'reversed' => 'boolean',
        'reversed_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommissionPayoutBatch::class, 'batch_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
