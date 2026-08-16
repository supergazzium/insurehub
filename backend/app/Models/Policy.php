<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Policy extends Model
{
    use SoftDeletes;

    protected $table = 'policies';

    protected $guarded = ['id'];

    protected $casts = [
        'quote_date' => 'date',
        'app_date' => 'date',
        'create_date' => 'date',
        'effective_date' => 'date',
        'insured_person_birth_date' => 'date',
        'expiry_date' => 'date',
        'issue_date' => 'date',
        'next_premium_due' => 'date',
        'cancel_date' => 'date',
        'lapse_date' => 'date',
        'period_paid_end' => 'date',
        'policy_end' => 'date',
        'first_due_inst_date' => 'date',
        'last_due_inst_date' => 'date',
        'payment_date' => 'date',
        'mailing_date' => 'date',
        'freelook_active' => 'boolean',
        'vehicle_on_non_motor' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function writingAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'writing_agent_id');
    }

    public function refAppTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ref_app_to_id');
    }

    public function riders(): HasMany
    {
        return $this->hasMany(PolicyRider::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(PolicyBeneficiary::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PolicyEvent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PolicyPayment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PolicyDocument::class);
    }

    public function rebate(): HasMany
    {
        // Modelled as HasMany even though the current unique constraint enforces one row per
        // policy — this keeps the door open to a multi-entry rebate ledger without a model change.
        return $this->hasMany(PolicyRebate::class);
    }

    public function legacyStatus(): BelongsTo
    {
        return $this->belongsTo(PolicyStatusLookup::class, 'legacy_policy_status_id');
    }
}
