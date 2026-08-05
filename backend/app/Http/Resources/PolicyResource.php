<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Policy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Policy
 */
class PolicyResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'quoteNo' => $this->quote_no,
            'applicationNo' => $this->application_no,
            'policyNo' => $this->policy_no,
            'customerId' => (string) $this->customer_id,
            'productId' => (string) $this->product_id,
            'carrierId' => (string) $this->carrier_id,
            'writingAgentId' => (string) $this->writing_agent_id,
            'coverage' => (float) $this->coverage,
            'annualPremium' => (float) $this->annual_premium,
            'premiumMode' => $this->premium_mode,
            'quoteDate' => $this->quote_date?->toDateString() ?? '',
            'appDate' => $this->app_date?->toDateString(),
            'createDate' => $this->create_date?->toDateString(),
            'effectiveDate' => $this->effective_date?->toDateString(),
            'expiryDate' => $this->expiry_date?->toDateString(),
            'issueDate' => $this->issue_date?->toDateString(),
            'nextPremiumDue' => $this->next_premium_due?->toDateString(),
            'cancelDate' => $this->cancel_date?->toDateString(),
            'lapseDate' => $this->lapse_date?->toDateString(),
            'periodPaidEnd' => $this->period_paid_end?->toDateString(),
            'policyEnd' => $this->policy_end?->toDateString(),
            'policyYear' => (int) $this->policy_year,
            'actYear' => (int) $this->act_year,
            'newOrRenew' => $this->new_or_renew,
            'freelookActive' => (bool) $this->freelook_active,
            'status' => $this->status,
            // Original Thai label from insurehub_legacy.lu_policy_status.
            'statusLabel' => $this->legacyStatus?->name_th ?? $this->status,
            'statusGroup' => $this->legacyStatus?->group_name_th,
            'statusNote' => $this->status_note ?? '',
            'notes' => $this->notes ?? '',
            'internalNote' => $this->internal_note ?? '',
            'notionNo' => $this->notion_no ?? '',
            // Premium breakdown — populated by the legacy importer.
            'premium' => [
                'main' => $this->main_premium !== null ? (float) $this->main_premium : null,
                'net' => $this->net_premium !== null ? (float) $this->net_premium : null,
                'dutyStamp' => $this->duty_stamp !== null ? (float) $this->duty_stamp : null,
                'vat' => $this->vat !== null ? (float) $this->vat : null,
                'totalPaid' => $this->total_premium_paid !== null ? (float) $this->total_premium_paid : null,
                'netCustomerPaid' => $this->net_customer_paid !== null ? (float) $this->net_customer_paid : null,
                'check' => $this->premium_check ?? '',
            ],
            // Main-product commission split — riders live on `riders`.
            'mainCommission' => [
                'rateInh' => $this->main_com_rate_inh !== null ? (float) $this->main_com_rate_inh : null,
                'amtInh' => $this->main_com_amt_inh !== null ? (float) $this->main_com_amt_inh : null,
                'rateAg' => $this->main_com_rate_ag !== null ? (float) $this->main_com_rate_ag : null,
                'amtAg' => $this->main_com_amt_ag !== null ? (float) $this->main_com_amt_ag : null,
            ],
            'comRecCheck' => $this->com_rec_check ?? '',
            // Installment / payment terms.
            'installment' => [
                'term' => $this->installment_term ?? '',
                'firstDueAmount' => $this->first_due_inst !== null ? (float) $this->first_due_inst : null,
                'firstDueDate' => $this->first_due_inst_date?->toDateString(),
                'nextDueAmount' => $this->next_due_inst !== null ? (float) $this->next_due_inst : null,
                'lastDueDate' => $this->last_due_inst_date?->toDateString(),
                'typeOfPaid' => $this->type_of_paid ?? '',
                'typeOfPaidNote' => $this->type_of_paid_note ?? '',
                'financeCompany' => $this->finance_company ?? '',
                'frontEndFee' => $this->front_end_fee !== null ? (float) $this->front_end_fee : null,
                'discountAmount' => $this->discount_amount !== null ? (float) $this->discount_amount : null,
                'creditCardFee' => $this->credit_card_fee !== null ? (float) $this->credit_card_fee : null,
                'subsidyFromAgent' => $this->subsidise_from_agent !== null ? (float) $this->subsidise_from_agent : null,
                'subsidyToFinance' => $this->subsidise_to_finance !== null ? (float) $this->subsidise_to_finance : null,
            ],
            // Withholding-tax block.
            'wht' => [
                'status' => $this->wht_status ?? '',
                'amount' => $this->wht_amt !== null ? (float) $this->wht_amt : null,
            ],
            // Cancellation / refund block (populated from insurehub_legacy.refunds).
            'cancellation' => $this->cancel_status !== null ? [
                'status' => $this->cancel_status ?? '',
                'refundPremium' => $this->refund_premium !== null ? (float) $this->refund_premium : null,
                'refundVat' => $this->refund_vat !== null ? (float) $this->refund_vat : null,
                'refundTotalPremium' => $this->refund_total_premium !== null ? (float) $this->refund_total_premium : null,
                'refundDiscount' => $this->refund_discount !== null ? (float) $this->refund_discount : null,
                'netRefundAmount' => $this->net_refund_amount !== null ? (float) $this->net_refund_amount : null,
                'refundRebateAmt' => $this->refund_rebate_amt !== null ? (float) $this->refund_rebate_amt : null,
                'refundRebateOv' => $this->refund_rebate_ov !== null ? (float) $this->refund_rebate_ov : null,
            ] : null,
            // Mailing block.
            'mailing' => [
                'address' => $this->mailing_add_by_policy ?? '',
                'date' => $this->mailing_date?->toDateString(),
                'note' => $this->mailing_note ?? '',
            ],
            // Data-quality flags applied by the importer.
            'dataQuality' => [
                'vehicleOnNonMotor' => (bool) $this->vehicle_on_non_motor,
                'premiumCheck' => $this->premium_check ?? '',
                'importNotes' => $this->import_notes ?? '',
            ],
            'riders' => $this->whenLoaded(
                'riders',
                fn () => $this->riders->map(fn ($r) => [
                    'id' => (string) $r->id,
                    'slot' => $r->slot !== null ? (int) $r->slot : null,
                    'productId' => $r->product_id !== null ? (string) $r->product_id : null,
                    'name' => $r->name,
                    'premium' => (float) $r->premium,
                    'commission' => [
                        'rateInh' => $r->com_rate_inh !== null ? (float) $r->com_rate_inh : null,
                        'amtInh' => $r->com_amt_inh !== null ? (float) $r->com_amt_inh : null,
                        'rateAg' => $r->com_rate_ag !== null ? (float) $r->com_rate_ag : null,
                        'amtAg' => $r->com_amt_ag !== null ? (float) $r->com_amt_ag : null,
                    ],
                    'notes' => $r->notes ?? '',
                ]),
                fn () => [],
            ),
            'beneficiaries' => $this->whenLoaded(
                'beneficiaries',
                fn () => $this->beneficiaries->map(fn ($b) => [
                    'id' => (string) $b->id,
                    'name' => $b->name,
                    'relation' => $b->relation ?? '',
                    'share' => (float) $b->share,
                ]),
                fn () => [],
            ),
            'motor' => $this->motor_vehicle_brand !== null ? [
                'vehicleBrand' => $this->motor_vehicle_brand ?? '',
                'vehicleModel' => $this->motor_vehicle_model ?? '',
                'licenseNo' => $this->motor_license_no ?? '',
                'engineNo' => $this->motor_engine_no ?? '',
                'chassisNo' => $this->motor_chassis_no ?? '',
                'registerYear' => $this->motor_register_year ?? '',
                'noPassenger' => (int) ($this->motor_no_passenger ?? 0),
                'typeDriver' => $this->motor_type_driver ?? '',
                'typeVehicle' => $this->motor_type_vehicle ?? '',
                'notes' => $this->motor_notes ?? '',
            ] : null,
            'property' => $this->property_insured_name !== null ? [
                'insuredName' => $this->property_insured_name ?? '',
                'insuredAddress' => $this->property_insured_address ?? '',
                'buildingCoverage' => (float) ($this->property_building_cov ?? 0),
                'furnitureCoverage' => (float) ($this->property_furniture_cov ?? 0),
                'stockCoverage' => (float) ($this->property_stock_cov ?? 0),
                'otherCoverage' => (float) ($this->property_other_cov ?? 0),
                'otherDetail' => $this->property_other_detail ?? '',
                'notes' => $this->property_notes ?? '',
            ] : null,
            // Phase B-2 — travel / life / health context. Emitted flat so
            // the wizard/edit views can bind directly without checking a
            // sub-object shape. Null-safe casts keep motor rows clean.
            'tripDestination' => $this->trip_destination,
            'tripStart' => $this->trip_start,
            'tripEnd' => $this->trip_end,
            'travelerCount' => $this->traveler_count !== null ? (int) $this->traveler_count : null,
            'travelerPassport' => $this->traveler_passport,
            'insuredPersonName' => $this->insured_person_name,
            'insuredPersonIdCard' => $this->insured_person_id_card,
            'insuredPersonBirthDate' => $this->insured_person_birth_date,
            'sumAssured' => $this->sum_assured !== null ? (float) $this->sum_assured : null,
            'premiumPayingTerm' => $this->premium_paying_term !== null ? (int) $this->premium_paying_term : null,
            'healthDeclaration' => $this->health_declaration,
            'healthBeneficiaryName' => $this->health_beneficiary_name,
            'healthBeneficiaryRelation' => $this->health_beneficiary_relation,
            'events' => PolicyEventResource::collection($this->whenLoaded('events')),
            'payments' => PolicyPaymentResource::collection($this->whenLoaded('payments')),
            'documents' => PolicyDocumentResource::collection($this->whenLoaded('documents')),
            'rebate' => $this->whenLoaded(
                'rebate',
                fn () => PolicyRebateResource::collection($this->rebate)->resolve()[0] ?? null,
                fn () => null,
            ),
        ];
    }
}
