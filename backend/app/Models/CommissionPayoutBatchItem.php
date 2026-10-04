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

    public const LINE_BASE = 'base';

    public const LINE_SELF_MGMT = 'self_mgmt';

    public const LINE_REFERRAL = 'referral';

    public const LINE_OVERRIDE = 'override';

    public const LINE_TYPES = [self::LINE_BASE, self::LINE_SELF_MGMT, self::LINE_REFERRAL, self::LINE_OVERRIDE];

    public const LINE_LABELS = [
        self::LINE_BASE => 'ค่าคอมพื้นฐาน',
        self::LINE_SELF_MGMT => 'ค่าบริหาร (ยอดขายตัวเอง)',
        self::LINE_REFERRAL => 'ค่าแนะนำ',
        self::LINE_OVERRIDE => 'ค่าบริหารสายงาน',
    ];

    protected $casts = [
        'snapshot_base_premium' => 'decimal:2',
        'snapshot_agent_commission' => 'decimal:2',
        'snapshot_rider_commission' => 'decimal:2',
        'paid_ratio' => 'decimal:4',
        'entitlement_amount' => 'decimal:2',
        'condition_override' => 'boolean',
    ];

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(CommissionLedger::class, 'ledger_id');
    }

    public function sourceAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'source_agent_id');
    }

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

    public function payeeName(): string
    {
        if ($this->payee_name !== null && $this->payee_name !== '') {
            return $this->payee_name;
        }
        $agent = $this->agent;
        if ($agent === null) {
            return '';
        }

        return $agent->kind === 'corporate' && filled($agent->juristic_name)
            ? (string) $agent->juristic_name
            : trim(($agent->first_name ?? '').' '.($agent->last_name ?? ''));
    }

    /** Frozen total for this item = main + rider agent commission. */
    public function total(): float
    {
        return (float) $this->snapshot_agent_commission + (float) $this->snapshot_rider_commission;
    }
}
