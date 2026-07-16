<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotorActTariff extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'premium' => 'decimal:2',
        'active' => 'boolean',
    ];
}
