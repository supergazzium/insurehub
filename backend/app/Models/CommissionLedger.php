<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionLedger extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'base_premium' => 'decimal:2',
        'rate_applied' => 'decimal:5',
        'amount' => 'decimal:2',
        'standard_rate' => 'decimal:5',
        'mgmt_fee_rate' => 'decimal:5',
    ];

    /** MGM payout type values. String constants rather than an enum so the DB
     *  column stays flexible if new payout types are added. */
    public const TYPE_DIRECT_COMMISSION = 'DIRECT_COMMISSION';

    public const TYPE_REFERRAL_FEE = 'REFERRAL_FEE';

    public const TYPE_MANAGEMENT_DIFFERENTIAL = 'MANAGEMENT_DIFFERENTIAL';

    public const TYPE_BREAKOFF_OVERRIDE = 'BREAKOFF_OVERRIDE';

    public const STATUS_UNSETTLED = 'unsettled';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_REVERSED = 'reversed';

    public const PAYER_INSURER = 'INSURER';

    public const PAYER_BROKER = 'BROKER';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'beneficiary_agent_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PolicyPayment::class, 'policy_payment_id');
    }

    public function sourceAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'source_agent_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(CommissionTier::class);
    }

    public function rankAtAccrual(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'rank_id_at_accrual');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_ledger_id');
    }
}
