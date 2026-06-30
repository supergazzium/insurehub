<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAssignmentHistory extends Model
{
    protected $table = 'customer_assignment_history';

    protected $guarded = ['id'];

    protected $casts = ['occurred_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fromAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'from_agent_id');
    }

    public function toAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'to_agent_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }
}
