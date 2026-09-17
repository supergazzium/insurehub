<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A สายงาน team. Self-referential: a team may report up to a parent team,
 * forming the org line. Agents belong to a team via agents.team_id.
 */
class Team extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_team_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_team_id');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'leader_agent_id');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'team_id');
    }
}
