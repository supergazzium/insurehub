<?php

declare(strict_types=1);

namespace App\Services\Policy;

use App\Models\Policy;
use App\Models\PolicyDocument;
use App\Models\PolicyEvent;
use App\Support\PolicyPeriodLabel;
use App\Support\PolicyYears;
use App\Support\PremiumMode;
use Illuminate\Support\Carbon;

final class RenewalBlueprint
{
    public const CLEAR = [
        'quote_no', 'application_no', 'job_no', 'policy_no', 'notion_no',
        'quote_date', 'app_date', 'create_date', 'issue_date', 'next_premium_due',
        'period_paid_end', 'policy_end', 'lapse_date', 'freelook_end_date',
        'received_date', 'received_note', 'approved_date', 'approval_ref', 'approval_note',
        'mailing_date', 'mailing_note', 'shipped_date', 'delivery_method', 'courier',
        'tracking_no', 'delivered_date', 'delivered_to',
        'cancel_date', 'cancel_status', 'refund_premium', 'refund_vat', 'refund_total_premium',
        'refund_discount', 'net_refund_amount', 'refund_rebate_amt', 'refund_rebate_ov',
        'first_due_inst', 'first_due_inst_date', 'next_due_inst', 'last_due_inst_date',
        'premium_check', 'com_rec_check', 'import_notes', 'status_note', 'internal_note',
        'legacy_policy_status_id',
        'commission_snapshot', 'comm_carrier_to_hub_rate', 'comm_carrier_to_hub_amount',
        'comm_hub_to_agent_rate', 'comm_hub_to_agent_amount',
    ];

    public const MUST_CONFIRM = [
        'grossPremiumInput', 'netPremium', 'dutyStamp', 'vat', 'totalPremiumPaid',
        'coverage', 'discountAmount', 'effectiveDate', 'expiryDate', 'risk.license_no',
    ];

    public static function build(Policy $source): array
    {
        $source->loadMissing(['product.productType', 'riders', 'beneficiaries', 'customer']);

        $draft = $source->replicate(['years_overridden']);
        foreach (self::CLEAR as $col) {
            if (array_key_exists($col, $draft->getAttributes())) {
                $draft->{$col} = null;
            }
        }

        $caretaker = $source->customer?->assigned_agent_id;
        $agentFromCaretaker = $caretaker !== null && (string) $caretaker !== (string) $source->writing_agent_id;
        if ($caretaker !== null) {
            $draft->writing_agent_id = $caretaker;
        }

        $draft->status = 'draft';
        $draft->new_or_renew = 'renew';
        $draft->ref_app_to_id = $source->id;
        $draft->years_overridden = false;
        $draft->endorsement_count = 0;
        [$effective, $expiry] = self::nextTerm($source);
        $draft->effective_date = $effective;
        $draft->expiry_date = $expiry;
        $years = PolicyYears::suggest($draft);
        $draft->policy_year = $years['policyYear'];
        $draft->customer_year = $years['customerYear'];

        $quote = self::latestQuote($source);
        if ($quote !== null) {
            $draft->annual_premium = $quote['annualPremium'];
            $draft->main_premium = $quote['annualPremium'];
            $draft->net_premium = $quote['netPremium'];
            $draft->duty_stamp = $quote['dutyStamp'];
            $draft->vat = $quote['vat'];
            $draft->total_premium_paid = null;
            $draft->net_customer_paid = null;
            if ($quote['coverage'] !== null) {
                $draft->coverage = $quote['coverage'];
            }
            if ($quote['premiumMode'] !== null && $quote['premiumMode'] !== $draft->premium_mode) {
                $draft->premium_mode = $quote['premiumMode'];
                $draft->installment_term = PremiumMode::installmentsFor($quote['premiumMode']) > 1
                    ? (string) PremiumMode::installmentsFor($quote['premiumMode'])
                    : null;
            }
        }

        $draft->setRelation('product', $source->product);
        $draft->setRelation('riders', $source->riders->map(fn ($r) => $r->replicate()));
        $draft->setRelation('beneficiaries', $source->beneficiaries->map(fn ($b) => $b->replicate()));
        foreach (['events', 'payments', 'documents', 'rebate'] as $rel) {
            $draft->setRelation($rel, collect());
        }
        $draft->setRelation('legacyStatus', null);

        return [
            'policy' => $draft,
            'meta' => [
                'mustConfirm' => self::MUST_CONFIRM,
                'cleared' => self::CLEAR,
                'quoteUsed' => $quote !== null,
                'quote' => $quote,
                'needsPremiumFormula' => $quote !== null && $quote['netPremium'] === null,
                'agentFromCaretaker' => $agentFromCaretaker,
                'suggestedYears' => $years,
                'source' => RenewalService::summary($source) + [
                    'customerYear' => $source->customer_year !== null ? (int) $source->customer_year : null,
                    'productId' => (string) $source->product_id,
                    'periodLabel' => PolicyPeriodLabel::forPolicy($source),
                ],
            ],
        ];
    }

    public static function nextTerm(Policy $source): array
    {
        $oldStart = $source->effective_date ? Carbon::parse($source->effective_date) : null;
        $oldEnd = $source->expiry_date ? Carbon::parse($source->expiry_date) : null;
        $start = $oldEnd?->copy() ?? Carbon::today();
        $months = ($oldStart !== null && $oldEnd !== null && $oldEnd->gt($oldStart))
            ? max(1, (int) round($oldStart->floatDiffInMonths($oldEnd)))
            : 12;

        return [$start->toDateString(), $start->copy()->addMonthsNoOverflow($months)->toDateString()];
    }

    public static function latestQuote(Policy $source): ?array
    {
        $events = PolicyEvent::query()
            ->where('policy_id', $source->id)
            ->where('type', 'renewalQuotePrepared')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get(['payload', 'occurred_at']);

        foreach ($events as $e) {
            $q = $e->payload['quote'] ?? null;
            $docId = $e->payload['documentId'] ?? null;
            if (! is_array($q) || $docId === null || (float) ($q['annualPremium'] ?? 0) <= 0) {
                continue;
            }
            if (! PolicyDocument::query()->where('policy_id', $source->id)->whereKey($docId)->exists()) {
                continue;
            }
            $coverage = (float) ($q['coverage'] ?? 0);
            $mode = $q['premiumMode'] ?? null;

            return [
                'annualPremium' => round((float) $q['annualPremium'], 2),
                'coverage' => $coverage > 0 ? round($coverage, 2) : null,
                'premiumMode' => in_array($mode, ['monthly', 'quarterly', 'semiannual', 'annual', 'single'], true) ? $mode : null,
                'netPremium' => isset($q['netPremium']) && $q['netPremium'] !== null ? round((float) $q['netPremium'], 2) : null,
                'dutyStamp' => isset($q['dutyStamp']) && $q['dutyStamp'] !== null ? round((float) $q['dutyStamp'], 2) : null,
                'vat' => isset($q['vat']) && $q['vat'] !== null ? round((float) $q['vat'], 2) : null,
                'discount' => isset($q['discount']) && $q['discount'] !== null ? round((float) $q['discount'], 2) : null,
                'noticeNo' => $q['noticeNo'] ?? null,
                'documentId' => (string) $docId,
                'preparedAt' => $e->occurred_at?->toIso8601String(),
            ];
        }

        return null;
    }
}
