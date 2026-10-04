<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingChange extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIONS = ['create', 'update', 'delete'];

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'before' => 'array',
        'fields' => 'array',
        'decided_at' => 'datetime',
    ];

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_user_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_user_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'entity_id')->withTrashed();
    }
}
