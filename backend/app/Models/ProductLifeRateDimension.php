<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductLifeRateDimension extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'min_age' => 'integer',
        'max_age' => 'integer',
        'min_sum_assure' => 'decimal:2',
        'max_sum_assure' => 'decimal:2',
        'valid_start' => 'date',
        'valid_end' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ProductLifeRate::class, 'dimension_id');
    }
}
