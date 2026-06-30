<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyRider extends Model
{
    protected $guarded = ['id'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
