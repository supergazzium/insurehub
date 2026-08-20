<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads `insurehub_legacy` (migrated Access data) and populates the Laravel
 * schema. See MAPPING.md in Access Database/Database/mysql_migration/ for the
 * column-level spec this class implements.
 *
 * Each step is a private method returning ['inserted' => n, 'updated' => n,
 * 'skipped' => n]. The orchestrator wraps each step in a transaction and logs
 * the counts. When --dry-run is passed, the wrapper still calls the step
 * but rolls back the transaction at the end.
 */
class InsureHubImporter
{
    private const LEGACY_CONNECTION = 'insurehub_legacy';

    private const BATCH_SIZE = 1000;

    private Command $command;

    private int $tenantId = 1;

    private bool $dryRun = false;

    /** @var list<string>|null */
    private ?array $only = null;

    private Carbon $now;

    /** @var array<string, array<string, int>> */
    private array $summary = [];

    /**
     * Cache: legacy code → new PK id, per entity, populated as each step runs.
     *
     * @var array<string, array<string, int>>
     */
    private array $idCache = [
        'carriers' => [],
        'products' => [],
        'agents' => [],
        'customers' => [],
        'policies' => [],
        'banks' => [],
        'locations' => [],
        'payment_methods' => [],
        'payment_inscomp_tos' => [],
        'payment_inscomp_statuses' => [],
        'policy_status_translations' => [],
    ];

    /** @var array<string, string> */
    private array $luDriverType = [];

    /** @var array<string, string> */
    private array $luVehicleType = [];

    /** @var array<string, string> */
    private array $luPaymentType = [];

    /** @var array<string, string> */
    private array $luFinanceCompany = [];

    /** @var array<string, string> */
    private array $luRelation = [];

    /** @var array<string, string> */
    private array $luWhtStatus = [];

    /** @var array<string, string> */
    private array $luCustomerType = [];

    /** @var list<string> */
    private array $availableSteps = [
        'tenant',
        'lookups',
        'carriers',
        'products',
        'agents_pass1',
        'agents_pass2',
        'customers',
        'policies_pass1',
        'policies_pass2',
        'policies_motor',
        'policies_property',
        'riders',
        'beneficiaries',
        'payments',
        'refunds',
        'rebates',
        'import_failures',
    ];

    public function __construct()
    {
        $this->now = Carbon::now();
    }

    public function setCommand(Command $command): self
    {
        $this->command = $command;

        return $this;
    }

    public function setTenantId(int $tenantId): self
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    /** @param list<string>|null $only */
    public function setOnly(?array $only): self
    {
        $this->only = $only;

        return $this;
    }

    /** @return array<string, array<string, int>> */
    public function run(): array
    {
        $legacy = DB::connection(self::LEGACY_CONNECTION);
        $primary = DB::connection();

        // Preload lookup label maps once — used across many steps.
        $this->preloadLegacyLookups($legacy);

        foreach ($this->availableSteps as $step) {
            if ($this->only !== null && ! in_array($step, $this->only, true)) {
                continue;
            }
            $this->runStep($step, $legacy, $primary);
        }

        return $this->summary;
    }

    public function truncate(Command $command): void
    {
        // Truncate in reverse-FK order.
        $tables = [
            'applications_import_failures',
            'policy_rebates',
            'policy_payments',
            'policy_documents',
            'policy_events',
            'policy_beneficiaries',
            'policy_riders',
            'commission_run_transactions',
            'commission_runs',
            'commission_transactions',
            'policies',
            'customer_referral_links',
            'customer_assignment_history',
            'customer_kyc_docs',
            'customers',
            'recruitment_links',
            'agents',
            'contract_schedule_rows',
            'contracts',
            'products',
            'carrier_contact_groups',
            'carriers',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            DB::table($table)->truncate();
            $command->line("Truncated {$table}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function runStep(string $step, ConnectionInterface $legacy, ConnectionInterface $primary): void
    {
        $this->command->info(">>> {$step}");
        $primary->beginTransaction();

        try {
            $counts = match ($step) {
                'tenant' => $this->stepTenant($primary),
                'lookups' => $this->stepLookups($legacy, $primary),
                'carriers' => $this->stepCarriers($legacy, $primary),
                'products' => $this->stepProducts($legacy, $primary),
                'agents_pass1' => $this->stepAgentsPass1($legacy, $primary),
                'agents_pass2' => $this->stepAgentsPass2($legacy, $primary),
                'customers' => $this->stepCustomers($legacy, $primary),
                'policies_pass1' => $this->stepPoliciesPass1($legacy, $primary),
                'policies_pass2' => $this->stepPoliciesPass2($legacy, $primary),
                'policies_motor' => $this->stepPoliciesMotor($legacy, $primary),
                'policies_property' => $this->stepPoliciesProperty($legacy, $primary),
                'riders' => $this->stepRiders($legacy, $primary),
                'beneficiaries' => $this->stepBeneficiaries($legacy, $primary),
                'payments' => $this->stepPayments($legacy, $primary),
                'refunds' => $this->stepRefunds($legacy, $primary),
                'rebates' => $this->stepRebates($legacy, $primary),
                'import_failures' => $this->stepImportFailures($legacy, $primary),
                default => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
            };

            $this->summary[$step] = $counts;
            $this->command->line(sprintf(
                '    ins=%d upd=%d skip=%d',
                $counts['inserted'] ?? 0,
                $counts['updated'] ?? 0,
                $counts['skipped'] ?? 0,
            ));

            if ($this->dryRun) {
                $primary->rollBack();
            } else {
                $primary->commit();
            }
        } catch (Throwable $e) {
            $primary->rollBack();
            $this->command->error("Step {$step} failed: {$e->getMessage()}");
            Log::error('insurehub:import step failed', ['step' => $step, 'error' => $e]);
            throw $e;
        }
    }

    // ---------- Lookup preload ----------

    private function preloadLegacyLookups(ConnectionInterface $legacy): void
    {
        $this->luDriverType = $legacy->table('lu_driver_type')->pluck('label', 'id')->all();
        $this->luVehicleType = $legacy->table('lu_vehicle_type')->pluck('label', 'id')->all();
        $this->luPaymentType = $legacy->table('lu_payment_type')->pluck('label', 'id')->all();
        $this->luFinanceCompany = $legacy->table('lu_finance_company')->pluck('label', 'id')->all();
        $this->luRelation = $legacy->table('lu_relation')->pluck('label', 'id')->all();
        $this->luWhtStatus = $legacy->table('lu_wht_status')->pluck('label', 'id')->all();
        $this->luCustomerType = $legacy->table('lu_customer_type')->pluck('label', 'id')->all();
    }

    // ---------- Steps ----------

    /** @return array<string, int> */
    private function stepTenant(ConnectionInterface $primary): array
    {
        $exists = $primary->table('tenants')->where('id', $this->tenantId)->exists();
        if ($exists) {
            return ['inserted' => 0, 'updated' => 0, 'skipped' => 1];
        }
        $primary->table('tenants')->insert([
            'id' => $this->tenantId,
            'slug' => 'insurehub-legacy',
            'name' => 'InsureHub Legacy',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);

        return ['inserted' => 1, 'updated' => 0, 'skipped' => 0];
    }

    /** @return array<string, int> */
    private function stepLookups(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;

        // Banks — from legacy.banks (names).
        foreach ($legacy->table('banks')->orderBy('id')->get() as $row) {
            $name = $this->normCode($row->name);
            if ($name === '') {
                continue;
            }
            $existing = $primary->table('banks')->where('name_th', $name)->first();
            if ($existing) {
                $this->idCache['banks'][$name] = (int) $existing->id;

                continue;
            }
            $id = $primary->table('banks')->insertGetId([
                'name_th' => $name,
                'active' => true,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
            $this->idCache['banks'][$name] = (int) $id;
            $ins++;
        }

        // Locations — 1:1.
        $legacy->table('locations')->orderBy('id')->chunk(self::BATCH_SIZE, function ($chunk) use ($primary, &$ins) {
            $existingCodes = $primary->table('locations')
                ->whereIn('location_code', $chunk->pluck('location_code')->filter()->values())
                ->pluck('id', 'location_code')
                ->all();

            $rows = [];
            foreach ($chunk as $row) {
                $code = (string) $row->location_code;
                if (isset($existingCodes[$code])) {
                    $this->idCache['locations'][$code] = (int) $existingCodes[$code];

                    continue;
                }
                $rows[] = [
                    'location_code' => $code,
                    'province' => (string) $row->province,
                    'amphur' => (string) $row->amphur,
                    'district' => (string) $row->district,
                    'zip' => (string) $row->zip_code,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ];
            }
            if ($rows) {
                $primary->table('locations')->insert($rows);
                $ins += count($rows);
            }
        });
        // Backfill id cache for locations.
        foreach ($primary->table('locations')->select('id', 'location_code')->get() as $r) {
            if ($r->location_code) {
                $this->idCache['locations'][(string) $r->location_code] = (int) $r->id;
            }
        }

        // payment_methods — from legacy.lu_payment_method label list.
        $codeMap = [
            'Method 1' => 'bankTransfer',
            'Method 2' => 'creditCard',
            'Method 3' => 'cash',
        ];
        foreach ($legacy->table('lu_payment_method')->orderBy('id')->get() as $row) {
            $label = $this->normCode($row->label);
            if ($label === '' || $label === 'label') {
                continue;
            }
            $existing = $primary->table('payment_methods')->where('name_th', $label)->first();
            if ($existing) {
                $this->idCache['payment_methods'][$label] = (int) $existing->id;

                continue;
            }
            $id = $primary->table('payment_methods')->insertGetId([
                'name_th' => $label,
                'code' => $codeMap[$label] ?? null,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
            $this->idCache['payment_methods'][$label] = (int) $id;
            $ins++;
        }

        // Preload payment_inscomp_* if seeded elsewhere.
        foreach ($primary->table('payment_inscomp_tos')->get() as $r) {
            $this->idCache['payment_inscomp_tos'][(string) $r->name_th] = (int) $r->id;
        }
        foreach ($primary->table('payment_inscomp_statuses')->get() as $r) {
            $this->idCache['payment_inscomp_statuses'][(string) $r->name_th] = (int) $r->id;
        }

        // policy_status_translations preload for use downstream.
        foreach ($primary->table('policy_status_translations')->get() as $r) {
            $this->idCache['policy_status_translations'][(string) $r->legacy_label] = (int) $r->policy_status_id;
        }

        return ['inserted' => $ins, 'updated' => 0, 'skipped' => 0];
    }

    /** @return array<string, int> */
    private function stepCarriers(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;
        $upd = 0;

        $insureTypeMap = [
            'Life' => 'life',
            'Non-Life' => 'non-life',
            'Tax' => 'tax',
        ];

        $subTypeMap = [
            'F' => 'direct',
            'P' => 'partner',
        ];

        foreach ($legacy->table('insurance_companies')->orderBy('company_code')->get() as $row) {
            $code = $this->normCode($row->company_code);
            if ($code === '') {
                continue;
            }
            $payload = [
                'tenant_id' => $this->tenantId,
                'code' => $code,
                'name' => (string) ($row->name_th ?? $row->name_en ?? $code),
                'name_en' => $row->name_en,
                'nickname_th' => $row->nickname,
                'insure_type' => $insureTypeMap[$row->insure_type] ?? null,
                'sub_type' => $subTypeMap[(string) $row->sub_insurer_type] ?? $row->sub_insurer_type,
                'comp_insure_code' => $row->company_insure_code,
                'oic_insure_com_code' => $row->oic_code,
                'tax_id' => $row->tax_id,
                'active' => ($row->status === 'Active'),
                'updated_at' => $this->now,
            ];

            $existing = $primary->table('carriers')
                ->where('tenant_id', $this->tenantId)
                ->where('code', $code)
                ->first();

            if ($existing) {
                $primary->table('carriers')->where('id', $existing->id)->update($payload);
                $this->idCache['carriers'][$code] = (int) $existing->id;
                $upd++;
            } else {
                $payload['created_at'] = $this->now;
                $id = $primary->table('carriers')->insertGetId($payload);
                $this->idCache['carriers'][$code] = (int) $id;
                $ins++;
            }
        }

        return ['inserted' => $ins, 'updated' => $upd, 'skipped' => 0];
    }

    /** @return array<string, int> */
    private function stepProducts(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;
        $upd = 0;
        $skip = 0;

        foreach ($legacy->table('products')->orderBy('product_code')->cursor() as $row) {
            $code = $this->normCode($row->product_code);
            if ($code === '') {
                $skip++;

                continue;
            }
            $carrierId = $this->idCache['carriers'][$this->normCode($row->company_code)] ?? null;
            if ($carrierId === null) {
                $skip++;

                continue;
            }

            $payload = [
                'tenant_id' => $this->tenantId,
                'carrier_id' => $carrierId,
                'code' => $code,
                'commission_code' => $row->commission_code,
                'name' => (string) $row->name,
                'type' => $this->mapProductType((string) ($row->insure_type ?? ''), (string) ($row->motor_flag ?? ''), (string) ($row->insure_category ?? '')),
                'category' => $row->insure_category,
                'sub_category' => $row->insure_subcategory,
                'sub_category_2' => $row->motor_flag,
                'main_rider' => $row->tier,
                'min_age' => (int) ($row->min_age ?? 0),
                'max_age' => (int) ($row->max_age ?? 99),
                'min_sum_assure' => $row->min_coverage,
                'max_sum_assure' => $row->max_coverage,
                'valid_start' => $row->valid_start,
                'valid_end' => $row->valid_end,
                'active' => true,
                'updated_at' => $this->now,
            ];

            $existing = $primary->table('products')
                ->where('tenant_id', $this->tenantId)
                ->where('code', $code)
                ->first();

            if ($existing) {
                $primary->table('products')->where('id', $existing->id)->update($payload);
                $this->idCache['products'][$code] = (int) $existing->id;
                $upd++;
            } else {
                $payload['created_at'] = $this->now;
                $id = $primary->table('products')->insertGetId($payload);
                $this->idCache['products'][$code] = (int) $id;
                $ins++;
            }
        }

        return ['inserted' => $ins, 'updated' => $upd, 'skipped' => $skip];
    }

    private function mapProductType(string $insureType, string $motorFlag, string $category): string
    {
        if (str_contains(strtolower($motorFlag), 'motor')) {
            return 'motor';
        }
        if (strtolower($insureType) === 'life') {
            return 'life';
        }
        $cat = mb_strtolower($category);
        if (str_contains($cat, 'health') || str_contains($cat, 'สุขภาพ')) {
            return 'health';
        }
        if (str_contains($cat, 'travel') || str_contains($cat, 'เดินทาง')) {
            return 'travel';
        }
        if (str_contains($cat, 'home') || str_contains($cat, 'บ้าน') || str_contains($cat, 'อัคคี')) {
            return 'home';
        }
        if (str_contains($cat, 'group') || str_contains($cat, 'กลุ่ม')) {
            return 'group';
        }

        return 'other';
    }

    /** @return array<string, int> */
    private function stepAgentsPass1(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;
        $upd = 0;

        // Pre-fetch child tables once, index by agent_code.
        $addresses = $legacy->table('agent_addresses')->get()->keyBy('agent_code');
        $contacts = $legacy->table('agent_contacts')->get()->keyBy('agent_code');
        $bankAccts = $legacy->table('agent_bank_accounts')->get()->groupBy('agent_code');
        $licenses = $legacy->table('agent_licenses')->get()->groupBy('agent_code');

        foreach ($legacy->table('agents')->orderBy('agent_code')->get() as $row) {
            $code = $this->normCode($row->agent_code);
            if ($code === '') {
                continue;
            }
            $addr = $addresses->get($code);
            $con = $contacts->get($code);
            $bank = optional($bankAccts->get($code))->first();
            $lifeLicense = optional($licenses->get($code))?->firstWhere('license_type', 'life');
            $nonLifeLicense = optional($licenses->get($code))?->firstWhere('license_type', 'non_life');

            $bankId = null;
            if ($bank && $bank->bank_name_raw) {
                $bankId = $this->idCache['banks'][$this->normCode($bank->bank_name_raw)] ?? null;
            }

            $payload = [
                'tenant_id' => $this->tenantId,
                'agent_code' => $code,
                'agent_type' => in_array($row->agent_type, ['AG', 'IN'], true) ? $row->agent_type : 'AG',
                'first_name' => $row->name_th,
                'last_name' => $row->surname_th,
                'first_name_en' => $row->name_en,
                'last_name_en' => $row->surname_en,
                'gender' => $row->gender,
                'birth_date' => $this->parseDate($row->birth_date),
                'joined_at' => $this->parseDate($row->applied_date),
                'source' => $row->source,
                'head_status' => $row->head_status,
                'head_start_date_raw' => $row->head_start_date,
                'grace_period_end_raw' => $row->grace_period_end,
                'vat_type' => (string) ($row->vat_type ?? ''),
                'tax_id' => $row->tax_id,
                'address' => $addr->address_no ?? null,
                'building_floor' => $addr->building_floor ?? null,
                'moo' => $this->splitMoo($addr->moo ?? null, $addr->moo_ban ?? null)[0],
                'moo_ban' => $this->splitMoo($addr->moo ?? null, $addr->moo_ban ?? null)[1],
                'soi' => $addr->soi ?? null,
                'road' => $addr->road ?? null,
                'sub_district' => $addr->kwang ?? null,
                'district' => $addr->khet ?? null,
                'province' => $addr->province ?? null,
                'postcode' => $this->truncStr($addr->zip_code ?? null, 16),
                'phone' => $con->mobile_phone ?? null,
                'tel_phone' => $con->tel_phone ?? null,
                'line_id' => $con->line_id ?? null,
                'email' => $con->email ?? null,
                'email2' => $con->email2 ?? null,
                'facebook_name' => $con->facebook_name ?? null,
                'bank_id' => $bankId,
                'bank_name_text' => $bank->bank_name_raw ?? null,
                'bank_account_no' => $bank->account_no ?? null,
                'bank_account_name' => $bank->account_name ?? null,
                'license_life_no' => $lifeLicense->license_no ?? null,
                'license_life_expiry' => isset($lifeLicense->expiry_date) ? $this->parseDate($lifeLicense->expiry_date) : null,
                'license_non_life_no' => $nonLifeLicense->license_no ?? null,
                'license_non_life_expiry' => isset($nonLifeLicense->expiry_date) ? $this->parseDate($nonLifeLicense->expiry_date) : null,
                'team_no' => $row->team_code,
                'level' => 'l5',
                'active' => true,
                'updated_at' => $this->now,
            ];

            $existing = $primary->table('agents')
                ->where('tenant_id', $this->tenantId)
                ->where('agent_code', $code)
                ->first();

            if ($existing) {
                $primary->table('agents')->where('id', $existing->id)->update($payload);
                $this->idCache['agents'][strtoupper($code)] = (int) $existing->id;
                $upd++;
            } else {
                $payload['created_at'] = $this->now;
                $id = $primary->table('agents')->insertGetId($payload);
                $this->idCache['agents'][strtoupper($code)] = (int) $id;
                $ins++;
            }
        }

        return ['inserted' => $ins, 'updated' => $upd, 'skipped' => 0];
    }

    /** @return array<string, int> */
    private function stepAgentsPass2(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $upd = 0;
        $skip = 0;

        foreach ($legacy->table('agent_hierarchy')->get() as $row) {
            $agentId = $this->idCache['agents'][$this->normAgent($row->agent_code)] ?? null;
            $uplineId = $this->idCache['agents'][$this->normAgent($row->upline_code)] ?? null;
            if (! $agentId || ! $uplineId) {
                $skip++;

                continue;
            }
            $level = 'l'.max(1, min(5, (int) $row->level));
            $primary->table('agents')->where('id', $agentId)->update([
                'parent_agent_id' => $uplineId,
                'level' => $level,
                'updated_at' => $this->now,
            ]);
            $upd++;
        }

        return ['inserted' => 0, 'updated' => $upd, 'skipped' => $skip];
    }

    /** @return array<string, int> */
    private function stepCustomers(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;
        $upd = 0;

        // Pre-index child tables.
        $addresses = $legacy->table('client_addresses')->get()->groupBy('client_code');
        $contacts = $legacy->table('client_contacts')->get()->keyBy('client_code');

        $customerTypeMap = ['1' => 'individual', '2' => 'corporate', '3' => 'other'];

        $chunk = [];
        $existingCodes = $primary->table('customers')
            ->where('tenant_id', $this->tenantId)
            ->pluck('id', 'customer_code')
            ->all();

        foreach ($legacy->table('clients')->orderBy('client_code')->cursor() as $row) {
            $code = $this->normCode($row->client_code);
            if ($code === '') {
                continue;
            }
            $addrBag = $addresses->get($code);
            $primaryAddr = $addrBag?->firstWhere('address_type', 'permanent') ?? $addrBag?->first();
            $mailingAddr = $addrBag?->firstWhere('address_type', 'mailing');
            $con = $contacts->get($code);

            $income = (float) ($row->annual_income ?? 0);
            [$moo, $mooBan] = $this->splitMoo($primaryAddr->moo ?? null, $primaryAddr->moo_ban ?? null);
            $payload = [
                'tenant_id' => $this->tenantId,
                'customer_code' => $code,
                'legacy_id' => $row->legacy_id,
                'customer_type' => $customerTypeMap[(string) $row->customer_type] ?? 'individual',
                'title_th' => $row->title_th,
                'first_name' => $row->name_th,
                'last_name' => $row->surname_th,
                'title_en' => $row->title_en,
                'first_name_en' => $row->name_en,
                'last_name_en' => $row->surname_en,
                'gender' => $row->gender,
                'id_card' => $row->national_id,
                'national_id_expiry' => $this->parseDate($row->national_id_expiry),
                'passport' => $row->passport,
                'race' => $row->race,
                'nationality' => $row->nationality,
                'religion' => $row->religion,
                'birth_date' => $this->parseDate($row->birth_date),
                'occupation' => $row->occupation,
                'position' => $row->position_held,
                'employer_name' => $row->employer,
                'monthly_income' => $income > 0 ? round($income / 12, 2) : 0,
                'annual_income_raw' => $income > 0 ? $income : null,
                'phone' => $con->mobile_phone ?? null,
                'tel_phone' => $con->tel_phone ?? null,
                'line_id' => $con->line_id ?? null,
                'email' => $con->email1 ?? null,
                'email2' => $con->email2 ?? null,
                'facebook_name' => $con->facebook_name ?? null,
                'contact_name' => $con->contact_person ?? null,
                'contact_person_receive' => $con->contact_person_receive ?? null,
                'contact_person_receive_address' => $con->contact_person_receive_address ?? null,
                'contact_phone' => $con->contact_mobile_phone ?? null,
                'contact_email' => $con->contact_email ?? null,
                'address' => $primaryAddr->address_no ?? null,
                'building_floor' => $primaryAddr->building_floor ?? null,
                'moo' => $moo,
                'moo_ban' => $mooBan,
                'soi' => $primaryAddr->soi ?? null,
                'road' => $primaryAddr->road ?? null,
                'sub_district' => $primaryAddr->kwang ?? null,
                'amphoe' => $primaryAddr->khet ?? null,
                'province' => $primaryAddr->province ?? null,
                'postcode' => $this->truncStr($primaryAddr->zip_code ?? null, 16),
                'mailing_same_as_registered' => $mailingAddr === null,
                'mailing_address' => $mailingAddr->address_no ?? null,
                'mailing_sub_district' => $mailingAddr->kwang ?? null,
                'mailing_district' => $mailingAddr->khet ?? null,
                'mailing_province' => $mailingAddr->province ?? null,
                'mailing_postcode' => $this->truncStr($mailingAddr->zip_code ?? null, 16),
                'active' => true,
                'updated_at' => $this->now,
            ];

            if (isset($existingCodes[$code])) {
                $primary->table('customers')->where('id', $existingCodes[$code])->update($payload);
                $this->idCache['customers'][$code] = (int) $existingCodes[$code];
                $upd++;
            } else {
                $payload['created_at'] = $this->now;
                $chunk[$code] = $payload;
            }

            if (count($chunk) >= self::BATCH_SIZE) {
                $this->flushCustomerChunk($primary, $chunk);
                $ins += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk) {
            $this->flushCustomerChunk($primary, $chunk);
            $ins += count($chunk);
        }

        // Rebuild id cache for downstream steps.
        foreach ($primary->table('customers')
            ->where('tenant_id', $this->tenantId)
            ->select('id', 'customer_code')
            ->cursor() as $r) {
            $this->idCache['customers'][(string) $r->customer_code] = (int) $r->id;
        }

        return ['inserted' => $ins, 'updated' => $upd, 'skipped' => 0];
    }

    /**
     * @param  array<string, array<string, mixed>>  $chunk
     */
    private function flushCustomerChunk(ConnectionInterface $primary, array $chunk): void
    {
        $primary->table('customers')->insert(array_values($chunk));
    }

    /** @return array<string, int> */
    private function stepPoliciesPass1(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;
        $upd = 0;
        $skip = 0;

        // Load policy_status FK map (legacy status_id → policy_status_id).
        $lookupLabel = $legacy->table('lu_policy_status')->pluck('label', 'id')->all();

        $existingApps = $primary->table('policies')
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('application_no')
            ->pluck('id', 'application_no')
            ->all();

        $chunk = [];

        foreach ($legacy->table('applications')->orderBy('application_code')->cursor() as $row) {
            $appNo = $this->normCode($row->application_code);
            if ($appNo === '') {
                $skip++;

                continue;
            }

            $customerId = $this->idCache['customers'][$this->normCode($row->client_code)] ?? null;
            $agentId = $this->idCache['agents'][$this->normAgent($row->agent_code)] ?? null;
            $productId = $this->idCache['products'][$this->normCode($row->product_code)] ?? null;
            if (! $customerId || ! $agentId || ! $productId) {
                $skip++;

                continue;
            }

            $carrierId = null;
            $carrierCode = $this->normCode($row->company_code);
            if ($carrierCode !== '') {
                $carrierId = $this->idCache['carriers'][$carrierCode] ?? null;
            }
            if ($carrierId === null) {
                $skip++;

                continue;
            }

            $policyStatusId = null;
            $frontendStatus = 'quote';
            if ($row->policy_status_id !== null) {
                $label = $lookupLabel[(int) $row->policy_status_id] ?? null;
                if ($label !== null && isset($this->idCache['policy_status_translations'][$label])) {
                    $policyStatusId = $this->idCache['policy_status_translations'][$label];
                    // Derive frontend union value from policy_statuses.code.
                    $statusRow = $primary->table('policy_statuses')->where('id', $policyStatusId)->first();
                    $frontendStatus = $statusRow->code ?? 'quote';
                }
            }

            $premiumCheck = $this->checkPremium($row);

            $payload = [
                'tenant_id' => $this->tenantId,
                'application_no' => $appNo,
                'policy_no' => $row->policy_number ?: null,
                'notion_no' => $row->notion_no,
                'customer_id' => $customerId,
                'product_id' => $productId,
                'carrier_id' => $carrierId,
                'writing_agent_id' => $agentId,
                'ref_app_to_id' => null, // set in pass 2
                'create_date' => $this->parseDate($row->create_date),
                'app_date' => $this->parseDate($row->app_date),
                'effective_date' => $this->parseDate($row->coverage_start),
                'expiry_date' => $this->parseDate($row->coverage_end),
                'period_paid_end' => $this->parseDate($row->period_paid_end),
                'policy_end' => $this->parseDate($row->policy_end),
                'first_due_inst_date' => $this->parseDate($row->first_due_date),
                'last_due_inst_date' => $this->parseDate($row->last_due_date),
                'mailing_date' => $this->parseDate($row->mailing_date),
                'policy_year' => (int) ($row->policy_year ?? 1),
                'act_year' => (int) ($row->act_year ?? 1),
                'new_or_renew' => $row->new_or_renew ? (string) $row->new_or_renew : 'new',
                'coverage' => (float) ($row->coverage_amount ?? 0),
                'annual_premium' => (float) ($row->main_premium ?? 0),
                'main_premium' => $row->main_premium,
                'net_premium' => $row->net_premium,
                'duty_stamp' => $row->duty_stamp,
                'vat' => $row->vat,
                'total_premium_paid' => $row->total_premium_paid,
                'net_customer_paid' => $row->net_customer_paid,
                'premium_mode' => 'annual',
                'type_of_paid' => $this->luPaymentType[(string) ($row->payment_type_id ?? '')] ?? null,
                'type_of_paid_note' => $row->payment_type_note,
                'finance_company' => $this->luFinanceCompany[(string) ($row->finance_company_id ?? '')] ?? null,
                'first_due_inst' => $row->first_due_amount,
                'next_due_inst' => $row->next_due_amount,
                'installment_term' => $row->installment_term,
                'wht_status' => $this->luWhtStatus[(string) ($row->wht_status ?? '')] ?? null,
                'wht_amt' => $row->wht_amount,
                'subsidise_from_agent' => $row->subsidy_from_agent,
                'front_end_fee' => $row->front_end_fee,
                'discount_amount' => $row->discount_amount,
                'subsidise_to_finance' => $row->subsidy_to_finance,
                'credit_card_fee' => $row->credit_card_fee_pct,
                'status' => $frontendStatus,
                'legacy_policy_status_id' => $policyStatusId,
                'status_note' => $row->policy_status_note,
                'freelook_active' => strtolower((string) ($row->freelook_status ?? '')) === 'active',
                'payment_method_id' => $this->resolvePaymentMethodId($row->payment_method_id),
                'mailing_add_by_policy' => $row->mailing_address,
                'mailing_note' => $row->mailing_note,
                'internal_note' => $row->internal_note,
                'recorded_by_username' => $row->created_by_user,
                'com_rec_check' => $row->commission_record_status,
                'premium_check' => $premiumCheck,
                'updated_at' => $this->now,
            ];

            if (isset($existingApps[$appNo])) {
                $primary->table('policies')->where('id', $existingApps[$appNo])->update($payload);
                $this->idCache['policies'][$appNo] = (int) $existingApps[$appNo];
                $upd++;
            } else {
                $payload['created_at'] = $this->now;
                $chunk[$appNo] = $payload;
            }

            if (count($chunk) >= 500) {
                $primary->table('policies')->insert(array_values($chunk));
                $ins += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk) {
            $primary->table('policies')->insert(array_values($chunk));
            $ins += count($chunk);
        }

        // Rebuild id cache for downstream steps.
        foreach ($primary->table('policies')
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('application_no')
            ->select('id', 'application_no')
            ->cursor() as $r) {
            $this->idCache['policies'][(string) $r->application_no] = (int) $r->id;
        }

        return ['inserted' => $ins, 'updated' => $upd, 'skipped' => $skip];
    }

    private function checkPremium(object $row): ?string
    {
        $total = (float) ($row->total_premium_paid ?? 0);
        if ($total <= 0) {
            return null;
        }
        $sum = (float) ($row->main_premium ?? 0)
             + (float) ($row->duty_stamp ?? 0)
             + (float) ($row->vat ?? 0);

        return abs($total - $sum) > 1.0 ? 'mismatch' : 'ok';
    }

    private function resolvePaymentMethodId(mixed $legacyId): ?int
    {
        if ($legacyId === null || $legacyId === '') {
            return null;
        }

        return null; // TODO: wire once payment methods importer resolves label→id
    }

    /** @return array<string, int> */
    private function stepPoliciesPass2(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $upd = 0;
        $skip = 0;

        foreach ($legacy->table('applications')
            ->select('application_code', 'referred_application_code')
            ->whereNotNull('referred_application_code')
            ->where('referred_application_code', '<>', '')
            ->cursor() as $row) {
            $policyId = $this->idCache['policies'][$this->normCode($row->application_code)] ?? null;
            $refId = $this->idCache['policies'][$this->normCode($row->referred_application_code)] ?? null;
            if (! $policyId || ! $refId) {
                $skip++;

                continue;
            }
            $primary->table('policies')->where('id', $policyId)->update([
                'ref_app_to_id' => $refId,
                'updated_at' => $this->now,
            ]);
            $upd++;
        }

        return ['inserted' => 0, 'updated' => $upd, 'skipped' => $skip];
    }

    /** @return array<string, int> */
    private function stepPoliciesMotor(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $upd = 0;
        $skip = 0;

        // Prefetch product motor_flag map to identify non-Motor mismatches.
        $motorFlags = $primary->table('products')
            ->where('tenant_id', $this->tenantId)
            ->pluck('sub_category_2', 'id')
            ->all();

        foreach ($legacy->table('vehicle_details')->cursor() as $row) {
            $policyId = $this->idCache['policies'][$this->normCode($row->application_code)] ?? null;
            if (! $policyId) {
                $skip++;

                continue;
            }
            $policyRow = $primary->table('policies')->where('id', $policyId)->first();
            $isNonMotor = ! str_contains(strtolower((string) ($motorFlags[$policyRow->product_id] ?? '')), 'motor');

            $primary->table('policies')->where('id', $policyId)->update([
                'motor_type_driver' => $this->luDriverType[(string) ($row->type_driver ?? '')] ?? null,
                'motor_type_vehicle' => $this->luVehicleType[(string) ($row->type_vehicle_id ?? '')] ?? null,
                'motor_vehicle_brand' => $row->brand,
                'motor_vehicle_model' => $row->model,
                'motor_license_no' => $row->license_no,
                'motor_engine_no' => $row->engine_no,
                'motor_chassis_no' => $row->chassis_no,
                'motor_register_year' => $row->register_year ? (string) $row->register_year : null,
                'motor_no_passenger' => $row->no_passenger,
                'motor_notes' => $row->note,
                'vehicle_on_non_motor' => $isNonMotor,
                'updated_at' => $this->now,
            ]);
            $upd++;
        }

        return ['inserted' => 0, 'updated' => $upd, 'skipped' => $skip];
    }

    /** @return array<string, int> */
    private function stepPoliciesProperty(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $upd = 0;
        $skip = 0;

        foreach ($legacy->table('property_details')->cursor() as $row) {
            $policyId = $this->idCache['policies'][$this->normCode($row->application_code)] ?? null;
            if (! $policyId) {
                $skip++;

                continue;
            }
            $primary->table('policies')->where('id', $policyId)->update([
                'property_insured_name' => $row->company_name,
                'property_insured_address' => $row->address,
                'property_building_cov' => $row->building_coverage,
                'property_furniture_cov' => $row->furniture_coverage,
                'property_stock_cov' => $row->stock_coverage,
                'property_other_cov' => $row->other_coverage,
                'property_other_detail' => $row->other_detail,
                'property_notes' => $row->note,
                'updated_at' => $this->now,
            ]);
            $upd++;
        }

        return ['inserted' => 0, 'updated' => $upd, 'skipped' => $skip];
    }

    /** @return array<string, int> */
    private function stepRiders(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;
        $skip = 0;

        if (! $this->dryRun) {
            $primary->table('policy_riders')
                ->whereIn('policy_id', array_values($this->idCache['policies']))
                ->delete();
        }

        $chunk = [];
        foreach ($legacy->table('application_riders')->cursor() as $row) {
            $policyId = $this->idCache['policies'][$this->normCode($row->application_code)] ?? null;
            if (! $policyId) {
                $skip++;

                continue;
            }
            $productId = $this->idCache['products'][$this->normCode($row->rider_code)] ?? null;
            $productName = null;
            if ($productId) {
                $productName = $primary->table('products')->where('id', $productId)->value('name');
            }
            $chunk[] = [
                'policy_id' => $policyId,
                'slot' => (int) $row->rider_no,
                'product_id' => $productId,
                'name' => $productName ?? (string) $row->rider_code,
                'premium' => (float) ($row->premium ?? 0),
                'com_rate_inh' => $row->commission_inh_pct,
                'com_rate_ag' => $row->commission_ag_pct,
                'com_amt_inh' => $row->commission_inh_amount,
                'com_amt_ag' => $row->commission_ag_amount,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ];
            if (count($chunk) >= self::BATCH_SIZE) {
                $primary->table('policy_riders')->insert($chunk);
                $ins += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            $primary->table('policy_riders')->insert($chunk);
            $ins += count($chunk);
        }

        return ['inserted' => $ins, 'updated' => 0, 'skipped' => $skip];
    }

    /** @return array<string, int> */
    private function stepBeneficiaries(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;
        $skip = 0;

        if (! $this->dryRun) {
            $primary->table('policy_beneficiaries')
                ->whereIn('policy_id', array_values($this->idCache['policies']))
                ->delete();
        }

        $chunk = [];
        foreach ($legacy->table('application_beneficiaries')->cursor() as $row) {
            $policyId = $this->idCache['policies'][$this->normCode($row->application_code)] ?? null;
            if (! $policyId) {
                $skip++;

                continue;
            }
            $relation = $this->luRelation[(string) ($row->relation_id ?? '')] ?? $row->relation_raw;
            $chunk[] = [
                'policy_id' => $policyId,
                'slot' => (int) ($row->sequence ?? 0),
                'name' => (string) $row->name,
                'relation' => $relation ? mb_substr((string) $relation, 0, 32) : null,
                'share' => 100.00,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ];
            if (count($chunk) >= self::BATCH_SIZE) {
                $primary->table('policy_beneficiaries')->insert($chunk);
                $ins += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            $primary->table('policy_beneficiaries')->insert($chunk);
            $ins += count($chunk);
        }

        return ['inserted' => $ins, 'updated' => 0, 'skipped' => $skip];
    }

    /** @return array<string, int> */
    private function stepPayments(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;
        $skip = 0;

        if (! $this->dryRun) {
            $primary->table('policy_payments')
                ->whereIn('policy_id', array_values($this->idCache['policies']))
                ->delete();
        }

        $chunk = [];
        foreach ($legacy->table('payments')->cursor() as $row) {
            $policyId = $this->idCache['policies'][$this->normCode($row->application_code)] ?? null;
            if (! $policyId) {
                $skip++;

                continue;
            }
            $chunk[] = [
                'policy_id' => $policyId,
                'payment_date' => $this->parseDate($row->payment_date) ?? $this->now->toDateString(),
                'amount' => (float) ($row->amount ?? 0),
                'method' => 'bankTransfer',
                'reference' => null,
                'payment_inscomp_to_id' => $this->idCache['payment_inscomp_tos'][(string) ($row->to_company ?? '')] ?? null,
                'payment_inscomp_status_id' => $this->idCache['payment_inscomp_statuses'][(string) ($row->to_company_status ?? '')] ?? null,
                'count_slip' => is_numeric($row->slip_count_status) ? (int) $row->slip_count_status : null,
                'validate_amount' => $row->validate_status ? mb_substr((string) $row->validate_status, 0, 16) : null,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ];
            if (count($chunk) >= self::BATCH_SIZE) {
                $primary->table('policy_payments')->insert($chunk);
                $ins += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            $primary->table('policy_payments')->insert($chunk);
            $ins += count($chunk);
        }

        return ['inserted' => $ins, 'updated' => 0, 'skipped' => $skip];
    }

    /** @return array<string, int> */
    private function stepRefunds(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $upd = 0;
        $skip = 0;

        foreach ($legacy->table('refunds')->cursor() as $row) {
            $policyId = $this->idCache['policies'][$this->normCode($row->application_code)] ?? null;
            if (! $policyId) {
                $skip++;

                continue;
            }
            $primary->table('policies')->where('id', $policyId)->update([
                'cancel_status' => $row->cancel_status,
                'refund_premium' => $row->refund_premium,
                'refund_vat' => $row->refund_vat,
                'refund_total_premium' => $row->refund_total,
                'refund_discount' => $row->refund_discount,
                'net_refund_amount' => $row->net_refund,
                'refund_rebate_amt' => $row->refund_rebate_amount,
                'refund_rebate_ov' => $row->refund_rebate_ov,
                'updated_at' => $this->now,
            ]);
            $upd++;
        }

        return ['inserted' => 0, 'updated' => $upd, 'skipped' => $skip];
    }

    /** @return array<string, int> */
    private function stepRebates(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;
        $skip = 0;

        if (! $this->dryRun) {
            $primary->table('policy_rebates')
                ->whereIn('policy_id', array_values($this->idCache['policies']))
                ->delete();
        }

        $chunk = [];
        foreach ($legacy->table('rebates_ledger')->cursor() as $row) {
            $policyId = $this->idCache['policies'][$this->normCode($row->application_code)] ?? null;
            if (! $policyId) {
                $skip++;

                continue;
            }
            $chunk[] = [
                'tenant_id' => $this->tenantId,
                'policy_id' => $policyId,
                'rebate_status' => $row->rebate_status,
                'earn_date' => $this->parseDate($row->earn_date),
                'ov_status' => $row->ov_status,
                'ov_date' => $this->parseDate($row->ov_date),
                'calculated_amount' => $row->calculated_amount,
                'calculated_ov' => $row->calculated_ov,
                'actual_amount' => $row->actual_amount,
                'actual_ov' => $row->actual_ov,
                'validate_amount' => $row->validate_amount ? mb_substr((string) $row->validate_amount, 0, 16) : null,
                'validate_ov' => $row->validate_ov ? mb_substr((string) $row->validate_ov, 0, 16) : null,
                'agent_rebate_status' => $row->agent_rebate_status_id !== null ? (string) $row->agent_rebate_status_id : null,
                'agent_receive_date' => $this->parseDate($row->agent_receive_date),
                'calculated_agent_amount' => $row->calculated_agent_amount,
                'actual_agent_amount' => $row->actual_agent_amount,
                'agent_check_status' => $row->agent_check_status ? mb_substr((string) $row->agent_check_status, 0, 16) : null,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ];
            if (count($chunk) >= self::BATCH_SIZE) {
                $primary->table('policy_rebates')->insert($chunk);
                $ins += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            $primary->table('policy_rebates')->insert($chunk);
            $ins += count($chunk);
        }

        return ['inserted' => $ins, 'updated' => 0, 'skipped' => $skip];
    }

    /** @return array<string, int> */
    private function stepImportFailures(ConnectionInterface $legacy, ConnectionInterface $primary): array
    {
        $ins = 0;

        if (! $this->dryRun) {
            $primary->table('applications_import_failures')->delete();
        }

        // Diff stg_application against applications.
        $stgCodes = $legacy->table('stg_application')
            ->whereNotNull('application_code')
            ->where('application_code', '<>', '')
            ->pluck('application_code')
            ->map(fn ($c) => trim((string) $c))
            ->unique();

        $inApps = $legacy->table('applications')
            ->pluck('application_code')
            ->map(fn ($c) => trim((string) $c))
            ->flip();

        $droppedCodes = $stgCodes->reject(fn ($c) => isset($inApps[$c]))->values();

        if ($droppedCodes->isEmpty()) {
            return ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $chunk = [];
        foreach ($droppedCodes->chunk(200) as $codesChunk) {
            $stgRows = $legacy->table('stg_application')
                ->whereIn('application_code', $codesChunk->all())
                ->get();

            foreach ($stgRows as $stg) {
                $reason = $this->classifyDropReason($legacy, $stg);
                $chunk[] = [
                    'application_code' => $this->normCode($stg->application_code),
                    'reason' => $reason,
                    'detail' => null,
                    'raw_json' => json_encode($stg, JSON_UNESCAPED_UNICODE),
                    'imported_at' => $this->now,
                    'resolved' => false,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ];
                if (count($chunk) >= self::BATCH_SIZE) {
                    $primary->table('applications_import_failures')->insert($chunk);
                    $ins += count($chunk);
                    $chunk = [];
                }
            }
        }
        if ($chunk) {
            $primary->table('applications_import_failures')->insert($chunk);
            $ins += count($chunk);
        }

        return ['inserted' => $ins, 'updated' => 0, 'skipped' => 0];
    }

    private function classifyDropReason(ConnectionInterface $legacy, object $stg): string
    {
        // Determine which FK didn't resolve. Reuses in-memory legacy tables where cheap.
        $clientCode = trim((string) ($stg->client_code ?? ''));
        $inClientsExists = $clientCode !== '' && $legacy->table('clients')->where('client_code', $clientCode)->exists();
        if (! $inClientsExists) {
            return 'missing_client';
        }
        $productCode = trim((string) ($stg->product_code ?? ''));
        if ($productCode === '' || ! $legacy->table('products')->where('product_code', $productCode)->exists()) {
            return 'missing_product';
        }
        $agentCode = trim((string) ($stg->insure_influencer_code ?? ''));
        if ($agentCode === '' || ! $legacy->table('agents')->where('agent_code', $agentCode)->exists()) {
            return 'missing_agent';
        }
        $carrierCode = trim((string) ($stg->inc_code ?? ''));
        if ($carrierCode === '' || ! $legacy->table('insurance_companies')->where('company_code', $carrierCode)->exists()) {
            return 'missing_company';
        }

        return 'other';
    }

    // ---------- helpers ----------

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function normAgent(mixed $value): string
    {
        return strtoupper($this->normCode($value));
    }

    private function normCode(mixed $value): string
    {
        // Strip ASCII whitespace + Unicode whitespace incl. NBSP (U+00A0) and
        // zero-width space (U+200B). Access CSV exports have all three.
        $s = (string) ($value ?? '');

        return preg_replace('/^[\s\x{00A0}\x{200B}]+|[\s\x{00A0}\x{200B}]+$/u', '', $s) ?? $s;
    }

    private function truncStr(mixed $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr((string) $value, 0, $limit);
    }

    /**
     * Access data misuses `moo` as a free-text village name. The Laravel schema
     * expects `moo` to hold the numeric moo id (varchar(8)) and `moo_ban` to hold
     * the village name. When `moo` overflows 8 chars, promote it to `moo_ban`
     * (concatenating with any existing legacy `moo_ban`).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitMoo(mixed $moo, mixed $mooBan): array
    {
        $moo = $moo === null ? null : (string) $moo;
        $mooBan = $mooBan === null ? null : (string) $mooBan;

        if ($moo !== null && mb_strlen($moo) > 8) {
            $mooBan = $mooBan ? trim("{$moo} {$mooBan}") : $moo;
            $moo = null;
        }

        return [
            $moo,
            $this->truncStr($mooBan, 255),
        ];
    }
}
