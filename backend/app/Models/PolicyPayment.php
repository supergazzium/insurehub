<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PolicyPaymentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([PolicyPaymentObserver::class])]
class PolicyPayment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function paymentInscompTo(): BelongsTo
    {
        return $this->belongsTo(PaymentInscompTo::class, 'payment_inscomp_to_id');
    }

    public function paymentInscompStatus(): BelongsTo
    {
        return $this->belongsTo(PaymentInscompStatus::class, 'payment_inscomp_status_id');
    }
}
