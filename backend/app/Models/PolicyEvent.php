<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
        'endorsement_seq' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
