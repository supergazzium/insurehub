<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLifeRate extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'policy_year' => 'integer',
        'rate' => 'decimal:4',
    ];

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(ProductLifeRateDimension::class, 'dimension_id');
    }
}
