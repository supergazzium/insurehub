<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PolicyPaymentInstallmentObserver;
use App\Observers\PolicyPaymentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([PolicyPaymentObserver::class, PolicyPaymentInstallmentObserver::class])]
class PolicyPayment extends Model
{
    public const PAYEE_INSUREHUB = 'insurehub';

    public const PAYEE_CARRIER = 'carrier';

    public const MODE_FULL = 'full';

    public const MODE_LESS_COMMISSION = 'less_commission';

    public const MODE_WITH_DISCOUNT = 'with_discount';

    public const MODE_SPLIT = 'split';

    public const MODE_INSTALLMENT = 'installment';

    public const ADJ_COMMISSION_DEDUCTED = 'commission_deducted';

    public const ADJ_DISCOUNT = 'discount';

    protected $guarded = ['id'];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'duty_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('notVoided', function (Builder $query): void {
            $query->whereNull($query->getModel()->getTable().'.voided_at');
        });
    }

    public static function creditSql(string $alias = ''): string
    {
        $p = $alias === '' ? '' : $alias.'.';

        return "({$p}amount + {$p}adjustment_amount)";
    }

    public static function volumeSql(string $alias = ''): string
    {
        $p = $alias === '' ? '' : $alias.'.';

        return "({$p}amount + CASE WHEN {$p}adjustment_kind = '".self::ADJ_COMMISSION_DEDUCTED."' THEN {$p}adjustment_amount ELSE 0 END)";
    }

    public function creditAmount(): float
    {
        return round((float) $this->amount + (float) $this->adjustment_amount, 2);
    }

    public function volumeAmount(): float
    {
        $extra = $this->adjustment_kind === self::ADJ_COMMISSION_DEDUCTED ? (float) $this->adjustment_amount : 0.0;

        return round((float) $this->amount + $extra, 2);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(PolicyInstallment::class, 'policy_installment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function proof(): BelongsTo
    {
        return $this->belongsTo(PolicyDocument::class, 'proof_document_id');
    }

    public function paymentInscompTo(): BelongsTo
    {
        return $this->belongsTo(PaymentInscompTo::class, 'payment_inscomp_to_id');
    }

    public function paymentInscompStatus(): BelongsTo
    {
        return $this->belongsTo(PaymentInscompStatus::class, 'payment_inscomp_status_id');
    }
}
