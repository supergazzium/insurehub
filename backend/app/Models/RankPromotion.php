<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankPromotion extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'qualifying_rolling_3_month_volume' => 'decimal:2',
        'promoted_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function fromRank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'from_rank_id');
    }

    public function toRank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'to_rank_id');
    }
}
