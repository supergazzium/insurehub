<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentInscompTo extends Model
{
    protected $table = 'payment_inscomp_tos';    protected $guarded = ['id'];
}
