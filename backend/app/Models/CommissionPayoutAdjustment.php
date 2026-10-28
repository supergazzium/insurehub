<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionPayoutAdjustment extends Model
{
    protected $guarded = ['id'];

    public const TYPE_SPECIAL_DEDUCT = 'special_deduct';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_CORRECTION = 'correction';

    public const TYPES = [
        self::TYPE_SPECIAL_DEDUCT,
        self::TYPE_BONUS,
        self::TYPE_CORRECTION,
    ];

    public const TYPE_CLAWBACK = 'clawback';

    public const TYPE_INSTALLMENT_COST = 'installment_cost';

    public const TYPE_COMMISSION_DEDUCTED = 'commission_deducted';

    /**
     * Every adjustment type that REDUCES an agent's payout (vs TYPE_BONUS which
     * adds). Single source of truth so the payee taxable base, the agent
     * roll-up net, the statement PDF, and the CSV export all deduct the same
     * set — including ค่าคอมที่ตัวแทนหักจากเบี้ยไปแล้ว (commission_deducted) and
     * ค่าธรรมเนียมผ่อนที่ตัวแทนรับภาระ (installment_cost).
     */
    public const DEDUCTION_TYPES = [
        self::TYPE_CLAWBACK,
        self::TYPE_SPECIAL_DEDUCT,
        self::TYPE_CORRECTION,
        self::TYPE_COMMISSION_DEDUCTED,
        self::TYPE_INSTALLMENT_COST,
    ];

    public const LEDGER_TYPES = [
        CommissionLedger::TYPE_INSTALLMENT_AGENT_COST => self::TYPE_INSTALLMENT_COST,
        CommissionLedger::TYPE_COMMISSION_PAID_AT_SOURCE => self::TYPE_COMMISSION_DEDUCTED,
    ];

    public const TYPE_LABELS = [
        self::TYPE_CLAWBACK => 'เรียกคืนค่าคอม',
        self::TYPE_SPECIAL_DEDUCT => 'หักพิเศษ',
        self::TYPE_INSTALLMENT_COST => 'ค่าธรรมเนียม/ดอกเบี้ยผ่อนที่ตัวแทนรับภาระ',
        self::TYPE_COMMISSION_DEDUCTED => 'ค่าคอมที่ตัวแทนหักจากเบี้ยไปแล้ว',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    protected $casts = [
        'amount' => 'decimal:2',
        'applied_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommissionPayoutBatch::class, 'batch_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(CommissionLedger::class, 'ledger_id');
    }
}
