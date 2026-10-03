<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One งวด of a policy's payment schedule — see App\Services\Policy\InstallmentSchedule. */
class PolicyInstallment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';

    protected $guarded = ['id'];

    protected $casts = [
        'installment_no' => 'integer',
        'installment_total' => 'integer',
        'due_date' => 'date',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'paid_date' => 'date',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PolicyPayment::class);
    }
}
