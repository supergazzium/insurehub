<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyStatusTranslation extends Model
{
    protected $guarded = ['id'];

    public function policyStatus(): BelongsTo
    {
        return $this->belongsTo(PolicyStatusLookup::class, 'policy_status_id');
    }
}
