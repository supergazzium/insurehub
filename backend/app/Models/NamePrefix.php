<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NamePrefix extends Model
{
    protected $table = 'name_prefixes';    protected $guarded = ['id'];
}
