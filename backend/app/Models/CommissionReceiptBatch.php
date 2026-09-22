<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionReceiptBatch extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'statement_date' => 'date',
        'received_file_date' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'insurer_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CommissionReceiptFile::class, 'batch_id');
    }
}
