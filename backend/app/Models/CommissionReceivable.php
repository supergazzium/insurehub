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
    public const TYPE_ADJ = 'ADJ';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_MATCHED = 'Matched';
    public const STATUS_MISMATCH = 'Mismatch';
    public const STATUS_RECEIVED = 'Received';
    public const STATUS_NO_COMMISSION = 'No Commission';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_MATCHED, self::STATUS_MISMATCH];

    public const KIND_ENDORSEMENT = 'endorsement';
    public const KIND_CANCELLATION = 'cancellation';
    public const KIND_OTHER = 'other';

    public const KINDS = [self::KIND_ENDORSEMENT, self::KIND_CANCELLATION, self::KIND_OTHER];

    public const KIND_LABELS = [
        self::KIND_ENDORSEMENT => 'สลักหลัง',
        self::KIND_CANCELLATION => 'ยกเลิก / บริษัทหักคืน',
        self::KIND_OTHER => 'อื่นๆ',
    ];

    public const REASON_RATE = 'rate';
    public const REASON_ENDORSEMENT = 'endorsement';
    public const REASON_CANCELLATION = 'cancellation';
    public const REASON_NO_POLICY_COMMISSION = 'no_policy_commission';
    public const REASON_OTHER = 'other';

    public const REASONS = [
        self::REASON_RATE, self::REASON_ENDORSEMENT, self::REASON_CANCELLATION,
        self::REASON_NO_POLICY_COMMISSION, self::REASON_OTHER,
    ];

    public const REASON_LABELS = [
        self::REASON_RATE => 'อัตราค่าคอมผิด',
        self::REASON_ENDORSEMENT => 'เบี้ยสลักหลัง',
        self::REASON_CANCELLATION => 'กรมธรรม์ยกเลิก',
        self::REASON_NO_POLICY_COMMISSION => 'ยังไม่มียอดค่าคอมในกรมธรรม์',
        self::REASON_OTHER => 'อื่นๆ',
    ];

    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_LEGACY = 'legacy';

    public const TOLERANCE = 0.01;

    protected $casts = [
        'received_date' => 'date',
        'expected_amount' => 'decimal:2',
        'statement_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'line_no' => 'integer',
        'version' => 'integer',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isAdjustment(): bool
    {
        return $this->commission_type === self::TYPE_ADJ;
    }

    public function policyEvent(): BelongsTo
    {
        return $this->belongsTo(PolicyEvent::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

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

    public function difference(): ?float
    {
        $actual = $this->received_amount ?? $this->statement_amount;
        if ($actual === null) {
            return null;
        }

        return round((float) $actual - (float) $this->expected_amount, 2);
    }
}
