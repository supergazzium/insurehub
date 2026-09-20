<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One recorded follow-up (การทวงเงิน) on a policy / งวด. */
class PaymentReminder extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'installment_no' => 'integer',
        'amount_due' => 'decimal:2',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
