<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RenewalNotice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'annual_premium' => 'float',
        'net_premium' => 'float',
        'duty_stamp' => 'float',
        'vat' => 'float',
        'discount' => 'float',
        'coverage' => 'float',
        'due_date' => 'date',
        'valid_until' => 'date',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PolicyDocument::class, 'document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function toApi(): array
    {
        return [
            'id' => (string) $this->id,
            'policyId' => (string) $this->policy_id,
            'noticeNo' => $this->notice_no,
            'annualPremium' => (float) $this->annual_premium,
            'netPremium' => $this->net_premium,
            'dutyStamp' => $this->duty_stamp,
            'vat' => $this->vat,
            'discount' => $this->discount,
            'coverage' => $this->coverage,
            'premiumMode' => $this->premium_mode,
            'dueDate' => $this->due_date?->toDateString(),
            'validUntil' => $this->valid_until?->toDateString(),
            'note' => $this->note,
            'carrierDocumentId' => $this->carrier_document_id !== null ? (string) $this->carrier_document_id : null,
            'documentId' => $this->document_id !== null ? (string) $this->document_id : null,
            'createdByName' => $this->relationLoaded('createdBy') ? $this->createdBy?->name : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
