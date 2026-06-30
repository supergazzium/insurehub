<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractScheduleRow extends Model
{
    protected $guarded = ['id'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
