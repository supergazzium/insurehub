<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'birth_date' => 'date',
        'national_id_expiry' => 'date',
        'registered_at' => 'datetime',
        'last_contact' => 'datetime',
        'monthly_income' => 'decimal:2',
        'annual_income_raw' => 'decimal:2',
        'mailing_same_as_registered' => 'boolean',
        'active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function nationalityRef(): BelongsTo
    {
        return $this->belongsTo(Nationality::class, 'nationality_id');
    }

    public function religionRef(): BelongsTo
    {
        return $this->belongsTo(Religion::class, 'religion_id');
    }

    public function occupationRef(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id');
    }

    public function createdByAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'created_by_agent_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assigned_agent_id');
    }

    public function kycDocs(): HasMany
    {
        return $this->hasMany(CustomerKycDoc::class);
    }

    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(CustomerAssignmentHistory::class);
    }

    public function referralLinks(): HasMany
    {
        return $this->hasMany(CustomerReferralLink::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(Policy::class);
    }

    /**
     * Live policy count — the customers table has denormalized
     * active_policy_count/total_policy_count columns that were never
     * kept in sync (see customer C9901503 with 2 policies but stored 0/0).
     * Reads straight from the policies table to guarantee accuracy.
     *
     * @param 'all'|'active' $scope
     */
    public function livePolicyCount(string $scope = 'all'): int
    {
        $q = $this->policies()->whereNull('deleted_at');
        if ($scope === 'active') {
            $q->where('status', 'active');
        }
        return $q->count();
    }
}
