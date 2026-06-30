<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaidStatus extends Model
{
    protected $table = 'paid_statuses';    protected $guarded = ['id'];
}
