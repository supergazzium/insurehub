<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PolicyObserver;
use App\Support\Freelook;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([PolicyObserver::class])]
class Policy extends Model
{
    use SoftDeletes;

    public const DELIVERY_METHODS = ['post', 'kerry', 'flash', 'pickup', 'e_policy', 'other'];

    public const FULFILMENT_COLUMNS = [
        'received_date', 'received_note', 'mailing_note',
        'delivery_method', 'courier', 'tracking_no', 'shipped_date', 'delivered_date', 'delivered_to',
    ];

    public const APPROVAL_COLUMNS = ['approved_date', 'approval_ref', 'approval_note'];

    public const DEAD_STATUSES = ['cancelled', 'rejected'];

    public const RENEWABLE_STATUSES = ['active', 'expired'];

    public const SALES_CHANNELS = ['face_to_face', 'telephone', 'online'];

    public const CANCEL_CHECK_VALUES = ['pending', 'done', 'na'];

    public bool $yearsResolved = false;

    public ?array $pendingYearsAudit = null;

    protected $table = 'policies';

    protected static function booted(): void
    {
        static::saving(function (Policy $policy): void {
            if ($policy->isDirty('shipped_date') && ! $policy->isDirty('mailing_date')) {
                $policy->mailing_date = $policy->shipped_date;
            } elseif ($policy->isDirty('mailing_date') && ! $policy->isDirty('shipped_date')) {
                $policy->shipped_date = $policy->mailing_date;
            }
            if ($policy->isDirty('status') && $policy->status === 'approved' && $policy->approved_date === null) {
                $policy->approved_date = now()->toDateString();
            }
            if ($policy->isDirty(['delivered_date', 'sales_channel', 'product_id'])) {
                Freelook::apply($policy);
            }
            // Keep expiry_date in step with the derived coverage end so the
            // daily state-machine cron (active→expired on expiry_date) stays
            // correct for life plans without a dedicated branch. Only writes
            // when a coverage-driver changed AND a basis actually yields a
            // date — never clobbers an operator-set expiry on a renewable /
            // unknown-basis policy.
            if ($policy->isDirty(['coverage_basis', 'coverage_years', 'coverage_to_age',
                'insured_person_birth_date', 'effective_date', 'product_id'])) {
                $end = $policy->coverage_end_date;
                if ($end !== null) {
                    $policy->expiry_date = $end->toDateString();
                }
            }
        });
    }

    protected $guarded = ['id'];

    protected $casts = [
        'quote_date' => 'date',
        'app_date' => 'date',
        'create_date' => 'date',
        'effective_date' => 'date',
        'insured_person_birth_date' => 'date',
        'expiry_date' => 'date',
        'issue_date' => 'date',
        'received_date' => 'date',
        'next_premium_due' => 'date',
        'cancel_date' => 'date',
        'lapse_date' => 'date',
        'period_paid_end' => 'date',
        'policy_end' => 'date',
        'first_due_inst_date' => 'date',
        'last_due_inst_date' => 'date',
        'payment_date' => 'date',
        'mailing_date' => 'date',
        'shipped_date' => 'date',
        'approved_date' => 'date',
        'delivered_date' => 'date',
        'freelook_active' => 'boolean',
        'freelook_end_date' => 'date',
        'freelook_doc_sent_at' => 'date',
        'freelook_doc_signed_at' => 'date',
        'freelook_doc_returned_at' => 'date',
        'cancellation_reviewed_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'commission_clawback_amount' => 'decimal:2',
        'vehicle_on_non_motor' => 'boolean',
        // Added 2027-02-15 (C-4). Persistent home for risk-* fields that
        // previously lived as ~30 top-level columns. The writer shim in
        // PolicyRequest::toModel() dual-writes; PolicyResource reads via
        // App\Support\PolicyRiskShim which prefers this over the columns.
        'risk_data' => 'array',
        // C-20: frozen product commission basis, captured at create time.
        'commission_snapshot' => 'array',
        'comm_override' => 'array',
        'years_overridden' => 'boolean',
        'imported_from_legacy' => 'boolean',
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

    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'ref_app_to_id')->orderByDesc('id');
    }

    public function liveRenewals(): HasMany
    {
        return $this->renewals()->whereNotIn('status', self::DEAD_STATUSES);
    }

    public function getPeriodLabelAttribute(): string
    {
        return \App\Support\PolicyPeriodLabel::forPolicy($this);
    }

    // ── #16: ระยะชำระเบี้ย / ระยะคุ้มครอง (e.g. 15/90) ──────────────────────
    //
    // Two independent axes. The premium-paying term says how many years the
    // customer pays (and therefore how long we chase premium); coverage-to-age
    // says until what age the policy protects (and therefore when coverage
    // ends). Each has a per-policy value that falls back to the product default.

    /** Effective premium-paying term in years: policy override → product default → null. */
    public function getEffectivePremiumTermAttribute(): ?int
    {
        $term = $this->premium_paying_term ?? $this->product?->pay_years;
        return ($term !== null && (int) $term > 0) ? (int) $term : null;
    }

    /** Effective coverage-until age: policy override → product default → null. */
    public function getEffectiveCoverageToAgeAttribute(): ?int
    {
        $age = $this->coverage_to_age ?? $this->product?->coverage_to_age;
        return ($age !== null && (int) $age > 0) ? (int) $age : null;
    }

    /** Effective coverage length in years (for basis='years'): override → product → null. */
    public function getEffectiveCoverageYearsAttribute(): ?int
    {
        $years = $this->coverage_years ?? $this->product?->coverage_years;
        return ($years !== null && (int) $years > 0) ? (int) $years : null;
    }

    /**
     * How this policy's coverage end date is computed:
     *   'age'       → วันเกิด + coverage_to_age ปี (คุ้มครองถึงอายุ)
     *   'years'     → effective_date + coverage_years ปี (คุ้มครอง N ปี)
     *   'renewable' → ปีต่อปี; no derived end (keeps expiry_date as-is)
     *
     * Precedence: policy override → product default → inferred from whichever
     * length column is populated (a to-age plan pins coverage_to_age, a
     * fixed-term plan pins coverage_years). Null when nothing is known.
     */
    public function getEffectiveCoverageBasisAttribute(): ?string
    {
        $basis = $this->coverage_basis ?? $this->product?->coverage_basis;
        if (in_array($basis, ['age', 'years', 'renewable'], true)) {
            return $basis;
        }
        if ($this->effective_coverage_to_age !== null) {
            return 'age';
        }
        if ($this->effective_coverage_years !== null) {
            return 'years';
        }
        return null;
    }

    /**
     * Coverage-end date, derived from the basis:
     *   - 'age'   → insured person's birthday + coverage-to-age years
     *   - 'years' → effective_date + coverage-years
     * Null when the basis is unknown/renewable or the required input (birthdate
     * for 'age', effective_date for 'years') is missing — we never guess a
     * protection end date.
     */
    public function getCoverageEndDateAttribute(): ?\Illuminate\Support\Carbon
    {
        return match ($this->effective_coverage_basis) {
            'age' => ($age = $this->effective_coverage_to_age) !== null
                && ($birth = $this->insured_person_birth_date) !== null
                    ? $birth->copy()->addYears($age)
                    : null,
            'years' => ($years = $this->effective_coverage_years) !== null
                && ($eff = $this->effective_date) !== null
                    ? $eff->copy()->addYears($years)
                    : null,
            default => null,
        };
    }

    /**
     * Is a premium still owed by term? True while the current policy year is
     * within the paying term (so reminders should keep firing); false once the
     * term is complete ("จ่ายครบแล้ว"); null when the term is unknown.
     */
    public function getPremiumStillDueByTermAttribute(): ?bool
    {
        $term = $this->effective_premium_term;
        if ($term === null) {
            return null;
        }
        return (int) ($this->policy_year ?? 1) <= $term;
    }

    /** Display label "15/90" (premiumTerm/coverageAge); null if neither side is known. */
    public function getTermLabelAttribute(): ?string
    {
        $term = $this->effective_premium_term;
        // Coverage half depends on the plan: "/90" for a to-age plan, "/15ปี"
        // for a fixed-term plan, "/ต่อปี" for renewable.
        $coverage = match ($this->effective_coverage_basis) {
            'age' => $this->effective_coverage_to_age !== null ? (string) $this->effective_coverage_to_age : null,
            'years' => $this->effective_coverage_years !== null ? $this->effective_coverage_years . 'ปี' : null,
            'renewable' => 'ต่อปี',
            default => null,
        };
        if ($term === null && $coverage === null) {
            return null;
        }
        return ($term ?? '–') . '/' . ($coverage ?? '–');
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

    /** ตารางงวดชำระ, งวด 1 first. */
    public function installments(): HasMany
    {
        return $this->hasMany(PolicyInstallment::class)->orderBy('installment_no');
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
