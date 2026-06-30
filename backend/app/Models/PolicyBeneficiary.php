<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyBeneficiary extends Model
{
    protected $guarded = ['id'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
