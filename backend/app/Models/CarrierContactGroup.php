<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarrierContactGroup extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'emails' => 'array',
        'insurance_types' => 'array',
        'is_default' => 'boolean',
        'active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
