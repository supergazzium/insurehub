<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentInscompStatus extends Model
{
    protected $table = 'payment_inscomp_statuses';    protected $guarded = ['id'];
}
