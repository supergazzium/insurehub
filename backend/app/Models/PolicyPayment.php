<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyPayment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payment_date' => 'date',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
