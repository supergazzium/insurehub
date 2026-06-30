<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotorVehicle extends Model
{
    protected $table = 'motor_vehicles';

    protected $guarded = ['id'];

    protected $casts = [
        'effect_date' => 'date',
    ];

    public function marketGroup(): BelongsTo
    {
        return $this->belongsTo(MotorMarketGroup::class, 'motor_market_group_id');
    }
}
