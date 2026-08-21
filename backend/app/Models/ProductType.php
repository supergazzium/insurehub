<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductType extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'sort_order' => 'integer',
        'active' => 'boolean',
        // 'kind' stays a plain string (motor|travel|fire|health|life|misc)
        // because it drives switch statements in PHP and JS — keep it a
        // scalar. `risk_schema` is a JSON blob authored per taxonomy row;
        // cast to array so consumers get a decoded structure.
        'risk_schema' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(CommissionTier::class, 'tier_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
