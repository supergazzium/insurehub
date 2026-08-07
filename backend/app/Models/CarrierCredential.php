<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierCredential extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        // Encrypt at rest — same pattern as agents.id_card. Reversible so
        // the drawer can display / copy the value on demand.
        'password' => 'encrypted',
        'sort_order' => 'integer',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
