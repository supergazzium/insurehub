<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\AgentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([AgentObserver::class])]
class Agent extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'birth_date' => 'date',
        'joined_at' => 'date',
        'license_expiry' => 'date',
        'license_life_expiry' => 'date',
        'license_non_life_expiry' => 'date',
        'company_register_date' => 'date',
        'doc_status' => 'boolean',
        'active' => 'boolean',
        'has_license' => 'boolean',
        'commission_pct' => 'decimal:4',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        // Sensitive PII — encrypted at rest via APP_KEY. Decrypts transparently
        // on model access; ciphertext stored in the column (see migration
        // 2027_01_01_001000_add_approval_and_pii_to_agents).
        'id_card' => 'encrypted',
        'bank_account_no' => 'encrypted',
        'bank_account_name' => 'encrypted',
        'license_number' => 'encrypted',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_agent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_agent_id');
    }

    public function recruitmentLinks(): HasMany
    {
        return $this->hasMany(RecruitmentLink::class);
    }

    public function customersAssigned(): HasMany
    {
        return $this->hasMany(Customer::class, 'assigned_agent_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(Policy::class, 'writing_agent_id');
    }
}
