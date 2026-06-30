<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Carrier;
use App\Models\Customer;
use App\Models\Policy;
use App\Models\PolicyBeneficiary;
use App\Models\PolicyEvent;
use App\Models\PolicyRider;
use App\Models\Product;
use App\Models\Tenant;
use Database\Seeders\Concerns\SeedsFromCsv;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Best-effort import from Access `Application.xlsx` (710 rows).
 *
 * The legacy table is wide and dirty: Thai status strings, mixed-format dates
 * (some are sentinel `9000-01-01`), agent codes like `AG200012` / `IN210239`
 * but occasionally garbage (`0.18`, `Internal`). We resolve FKs by code; rows
 * whose customer / product / carrier / writing-agent can't be resolved are
 * skipped (counted + reported, not crashed).
 *
 * What we DON'T import yet (open questions in RESEARCH_DB_MAPPING.md):
 *  - WHT amounts beyond column copy
 *  - Most of the rebate / commission columns (the commission ledger is built
 *    by the client-side calculator, not by importing legacy amounts)
 *  - Document paths from App_Doc_Control (separate file, separate decision)
 */
class PolicySeeder extends Seeder
{
    use SeedsFromCsv;

    /** Access Thai Policy_Status → frontend PolicyStatus union. */
    private const STATUS_MAP = [
        'อนุมัติแล้ว' => 'active',
        'รอพิจารณา' => 'submitted',
        'รอตรวจรถ' => 'submitted',
        'Cancel' => 'cancelled',
        'Reject' => 'cancelled',
        'True' => 'active',
    ];

    public function run(): void
    {
        $tenantId = Tenant::where('slug', 'insurehub')->value('id');
        if ($tenantId === null) {
            throw new \RuntimeException('TenantSeeder must run before PolicySeeder.');
        }

        // Pre-build code → id lookup maps so we don't hit the DB per row.
        $customers = Customer::where('tenant_id', $tenantId)->pluck('id', 'customer_code')->all();
        $agents = Agent::where('tenant_id', $tenantId)->pluck('id', 'agent_code')->all();
        $carriers = Carrier::where('tenant_id', $tenantId)->pluck('id', 'code')->all();
        $products = Product::where('tenant_id', $tenantId)->pluck('id', 'code')->all();

        $stats = [
            'inserted' => 0,
            'skipped_no_customer' => 0,
            'skipped_no_product' => 0,
            'skipped_no_carrier' => 0,
            'skipped_no_agent' => 0,
            'skipped_duplicate_policy_no' => 0,
            'riders_inserted' => 0,
            'beneficiaries_inserted' => 0,
        ];

        // Track policy_no's we've already seen in this run so duplicates in the
        // legacy CSV don't trip the (tenant_id, policy_no) unique constraint.
        $seenPolicyNos = [];

        DB::transaction(function () use (
            $tenantId,
            $customers,
            $agents,
            $carriers,
            $products,
            &$stats,
            &$seenPolicyNos,
        ): void {
            foreach ($this->streamCsv('application.csv') as $row) {
                $code = $this->nonEmpty($row['Application_code'] ?? null);
                if ($code === null) {
                    continue;
                }

                $customerId = $customers[$row['Client_Code'] ?? ''] ?? null;
                if ($customerId === null) {
                    $stats['skipped_no_customer']++;
                    continue;
                }
                $productId = $products[$row['Product_Code'] ?? ''] ?? null;
                if ($productId === null) {
                    $stats['skipped_no_product']++;
                    continue;
                }
                $carrierId = $carriers[$row['INC_Code'] ?? ''] ?? null;
                if ($carrierId === null) {
                    $stats['skipped_no_carrier']++;
                    continue;
                }
                $agentId = $agents[$row['Insure_Influencer_Code'] ?? ''] ?? null;
                if ($agentId === null) {
                    $stats['skipped_no_agent']++;
                    continue;
                }

                // Drop legacy duplicates on policy_no — keep the first.
                $policyNoRaw = $this->nonEmpty($row['Policy_Number'] ?? null);
                if ($policyNoRaw !== null && isset($seenPolicyNos[$policyNoRaw])) {
                    $stats['skipped_duplicate_policy_no']++;
                    continue;
                }
                if ($policyNoRaw !== null) {
                    $seenPolicyNos[$policyNoRaw] = true;
                }

                $statusRaw = trim((string) ($row['Policy_Status'] ?? ''));
                $status = self::STATUS_MAP[$statusRaw] ?? 'submitted';

                // If both a policy number AND coverage_start exist, the policy was issued.
                $policyNo = $this->nonEmpty($row['Policy_Number'] ?? null);
                $effectiveDate = $this->parseDate($row['Coverage_start'] ?? null);
                $isIssued = $policyNo !== null && $effectiveDate !== null;

                // Build the policy.
                $policy = Policy::updateOrCreate(
                    ['tenant_id' => $tenantId, 'application_no' => $code],
                    [
                        'policy_no' => $policyNo,
                        'notion_no' => $this->nonEmpty($row['Notion_No'] ?? null),
                        'customer_id' => $customerId,
                        'product_id' => $productId,
                        'carrier_id' => $carrierId,
                        'writing_agent_id' => $agentId,
                        'app_date' => $this->parseDate($row['App_date'] ?? null),
                        'effective_date' => $effectiveDate,
                        'expiry_date' => $this->parseDate($row['Coverage_End'] ?? null),
                        'issue_date' => $isIssued ? $effectiveDate : null,
                        'period_paid_end' => $this->parseDate($row['Period_Paid_End'] ?? null),
                        'policy_end' => $this->parseDate($row['Policy_End'] ?? null),
                        'policy_year' => (int) ($this->num($row['Policy_Year'] ?? null) ?: 1),
                        'act_year' => (int) ($this->num($row['Act_Year'] ?? null) ?: 1),
                        'new_or_renew' => ($row['NewOrRenew'] ?? '1') === '2' ? 'renew' : 'new',
                        'coverage' => $this->num($row['Coverage_amt'] ?? null),
                        'annual_premium' => $this->num($row['Premium'] ?? null) ?: $this->num($row['Main_Premium'] ?? null),
                        'main_premium' => $this->num($row['Main_Premium'] ?? null) ?: null,
                        'duty_stamp' => $this->num($row['Duty_stamp'] ?? null) ?: null,
                        'vat' => $this->num($row['Vat'] ?? null) ?: null,
                        'total_premium_paid' => $this->num($row['Total_Premium_Paid'] ?? null) ?: null,
                        'net_customer_paid' => $this->num($row['Net_Cus_paid'] ?? null) ?: null,
                        'premium_mode' => $this->mapPremiumMode($row['Type_of_paid'] ?? ''),
                        'type_of_paid' => $this->nonEmpty($row['Type_of_paid'] ?? null),
                        'finance_company' => $this->nonEmpty($row['Finance_Company'] ?? null),
                        'installment_term' => $this->nonEmpty($row['Installment_Term'] ?? null),
                        'first_due_inst_date' => $this->parseDate($row['FirstDue_instDate'] ?? null),
                        'last_due_inst_date' => $this->parseDate($row['LastDue_instDate'] ?? null),
                        'wht_status' => $this->nonEmpty($row['WHT_Status'] ?? null),
                        'wht_amt' => $this->num($row['WHT_Amt'] ?? null) ?: null,
                        'status' => $status,
                        'freelook_active' => ($row['Freelook_Status'] ?? '') === 'True',

                        // Motor block — only populate if there's a vehicle.
                        'motor_vehicle_brand' => $this->nonEmpty($row['Vehicle_Brand'] ?? null),
                        'motor_vehicle_model' => $this->nonEmpty($row['Vehicle_model'] ?? null),
                        'motor_license_no' => $this->nonEmpty($row['License_no'] ?? null),
                        'motor_engine_no' => $this->nonEmpty($row['Engine_No'] ?? null),
                        'motor_chassis_no' => $this->nonEmpty($row['chassis_No'] ?? null),
                        'motor_register_year' => $this->nonEmpty($row['Register_Year'] ?? null),
                        'motor_no_passenger' => (int) $this->num($row['No_Passenger'] ?? null),
                        'motor_type_driver' => $this->nonEmpty($row['Type_Driver'] ?? null),
                        'motor_type_vehicle' => $this->nonEmpty($row['Type_Vehicle'] ?? null),
                        'motor_notes' => $this->nonEmpty($row['NoteCarAsset'] ?? null),

                        // Property block.
                        'property_insured_name' => $this->nonEmpty($row['Insured_CompName'] ?? null),
                        'property_insured_address' => $this->nonEmpty($row['Insured_Address'] ?? null),
                        'property_building_cov' => $this->num($row['Insured_Aset_Buil_Cov'] ?? null) ?: null,
                        'property_furniture_cov' => $this->num($row['Insured_Aset_Fur_Cov'] ?? null) ?: null,
                        'property_stock_cov' => $this->num($row['Insured_Aset_Stok_Cov'] ?? null) ?: null,
                        'property_other_cov' => $this->num($row['Insured_Aset_Other_Cov'] ?? null) ?: null,
                        'property_other_detail' => $this->nonEmpty($row['Insured_Aset_Other_Detl'] ?? null),
                        'property_notes' => $this->nonEmpty($row['Insured_Aset_Note'] ?? null),
                        'property_phone' => $this->nonEmpty($row['Insured_Mobile_phone'] ?? null),

                        // Mailing.
                        'mailing_add_by_policy' => $this->nonEmpty($row['Mailing_Add_by_Policy'] ?? null),
                        'mailing_date' => $this->parseDate($row['Mailing_Date'] ?? null),
                        'mailing_note' => $this->nonEmpty($row['Mailing_Note'] ?? null),

                        'internal_note' => $this->nonEmpty($row['Internal_Note'] ?? null),
                        'recorded_by_username' => $this->nonEmpty($row['U'] ?? null),
                        'com_rec_check' => $this->nonEmpty($row['ComRec_Check'] ?? null),
                        'status_note' => $this->nonEmpty($row['Policy_Status_Note'] ?? null),
                    ],
                );
                $stats['inserted']++;

                // Riders — 5 fixed slots in Access. Skip blank slots.
                $policy->riders()->delete();
                for ($i = 1; $i <= 5; $i++) {
                    $riderName = $this->nonEmpty($row["Rider{$i}"] ?? null);
                    if ($riderName === null) {
                        continue;
                    }
                    $riderProductId = $products[$riderName] ?? null;
                    PolicyRider::create([
                        'policy_id' => $policy->id,
                        'slot' => $i,
                        'product_id' => $riderProductId,
                        'name' => $riderName,
                        'premium' => $this->num($row["Rider{$i}_Premium"] ?? null) ?: 0,
                        'com_rate_inh' => $this->num($row["Rider{$i}_Com_InH"] ?? null) ?: null,
                        'com_rate_ag' => $this->num($row["Rider{$i}_Com_AG"] ?? null) ?: null,
                        'com_amt_inh' => $this->num($row["Rider{$i}_ComAmt_InH"] ?? null) ?: null,
                        'com_amt_ag' => $this->num($row["Rider{$i}_ComAmt_AG"] ?? null) ?: null,
                    ]);
                    $stats['riders_inserted']++;
                }

                // Beneficiaries — 4 fixed slots.
                $policy->beneficiaries()->delete();
                for ($i = 1; $i <= 4; $i++) {
                    $col = $i === 1 ? 'Beneficiary' : "Beneficiary{$i}";
                    $relCol = $i === 1 ? 'Beneficiary_Relation' : "Beneficiary_Relation{$i}";
                    $name = $this->nonEmpty($row[$col] ?? null);
                    if ($name === null) {
                        continue;
                    }
                    PolicyBeneficiary::create([
                        'policy_id' => $policy->id,
                        'slot' => $i,
                        'name' => $name,
                        'relation' => $this->nonEmpty($row[$relCol] ?? null),
                        'share' => 100.00, // Access has no per-beneficiary share; default to 100/4 implicit
                    ]);
                    $stats['beneficiaries_inserted']++;
                }

                // Synthetic "created" event so the timeline isn't empty.
                $createDate = $this->parseDate($row['Create_date'] ?? null) ?? $this->parseDate($row['App_date'] ?? null);
                if ($createDate !== null) {
                    PolicyEvent::firstOrCreate(
                        ['policy_id' => $policy->id, 'type' => 'created'],
                        [
                            'occurred_at' => $createDate,
                            'payload' => [
                                'imported' => true,
                                'application_code' => $code,
                            ],
                        ],
                    );
                    if ($isIssued) {
                        PolicyEvent::firstOrCreate(
                            ['policy_id' => $policy->id, 'type' => 'issued'],
                            [
                                'occurred_at' => $effectiveDate,
                                'payload' => ['imported' => true, 'policyNo' => $policyNo],
                            ],
                        );
                    }
                }
            }
        });

        foreach ($stats as $k => $v) {
            $this->command?->info("  {$k}: {$v}");
        }
    }

    private function mapPremiumMode(string $raw): string
    {
        $r = mb_strtolower(trim($raw));
        return match (true) {
            str_contains($r, 'รายเดือน'), str_contains($r, 'monthly') => 'monthly',
            str_contains($r, 'รายไตรมาส'), str_contains($r, 'quarter') => 'quarterly',
            str_contains($r, 'ราย6เดือน'), str_contains($r, 'semi') => 'semiannual',
            str_contains($r, 'งวดเดียว'), str_contains($r, 'single') => 'single',
            default => 'annual',
        };
    }
}
