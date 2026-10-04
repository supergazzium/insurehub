<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionPayoutPayee extends Model
{
    protected $guarded = ['id'];

    public const FORM_PND3 = 'pnd3';

    public const FORM_PND53 = 'pnd53';

    public const FORM_LABELS = [self::FORM_PND3 => 'ภ.ง.ด.3', self::FORM_PND53 => 'ภ.ง.ด.53'];

    protected $casts = [
        'payee_tax_id' => 'encrypted',
        'bank_account_no' => 'encrypted',
        'bank_account_name' => 'encrypted',
        'wht_rate' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'self_mgmt_amount' => 'decimal:2',
        'referral_amount' => 'decimal:2',
        'override_amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'wht_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'frozen_at' => 'datetime',
        'cert_date' => 'date',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommissionPayoutBatch::class, 'batch_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function certificateLabel(): ?string
    {
        if ($this->cert_no === null) {
            return null;
        }

        return sprintf('%d/%05d', (int) $this->cert_book, (int) $this->cert_no);
    }
}
