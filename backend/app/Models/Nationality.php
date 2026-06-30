<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Nationality extends Model
{
    protected $table = 'nationalities';    protected $guarded = ['id'];
}
