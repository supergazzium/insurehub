<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerKycDoc extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'verified' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploadedByAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'uploaded_by_agent_id');
    }
}
