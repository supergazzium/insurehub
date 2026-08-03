<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Carrier extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function contactGroups(): HasMany
    {
        return $this->hasMany(CarrierContactGroup::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CarrierBankAccount::class)->orderBy('sort_order')->orderBy('id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CarrierContact::class)->orderBy('sort_order')->orderBy('id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
