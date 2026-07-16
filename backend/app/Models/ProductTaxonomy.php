<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductTaxonomy extends Model
{
    protected $table = 'product_taxonomy';

    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
