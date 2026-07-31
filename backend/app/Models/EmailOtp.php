<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailOtp extends Model
{
    protected $table = 'email_otps';

    protected $fillable = [
        'email',
        'code_hash',
        'expires_at',
        'consumed_at',
        'attempts',
        'ip',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];
}
