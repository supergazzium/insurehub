<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'payout_min_balance' => 'decimal:2',
        'payout_auto_approve' => 'boolean',
        'active' => 'boolean',
        'customer_tiers' => 'array',
        'policy_verify_required' => 'boolean',
        'freelook_days_standard' => 'integer',
        'freelook_days_remote' => 'integer',
        'no_commission_window_days' => 'integer',
        'payout_require_freelook' => 'boolean',
        'payout_require_insurer_received' => 'boolean',
        'payout_require_customer_paid' => 'boolean',
        'wht_rate_individual' => 'decimal:2',
        'wht_rate_corporate' => 'decimal:2',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function carriers(): HasMany
    {
        return $this->hasMany(Carrier::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(Policy::class);
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class);
    }
}
