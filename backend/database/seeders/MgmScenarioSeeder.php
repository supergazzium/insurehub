<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Carrier;
use App\Models\CarrierProductTypeRate;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Policy;
use App\Models\PolicyPayment;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Rank;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * MgmScenarioSeeder — end-to-end test fixtures for the MGM commission engine.
 *
 * Plants seven scenarios, each exercising a distinct engine branch. Every
 * scenario ends with a PolicyPayment insert; the PolicyPaymentObserver fires
 * VolumeAccumulator → RankPromotionService → MgmCommissionEngine, producing
 * commission_ledgers rows that the test can assert against.
 *
 * Every entity created here uses a stable `SCN{N}_` prefix so a browser or
 * SQL test can locate them without guessing IDs.
 *
 *   Agent codes:     SCN{N}_L{level}        (S5 has SCN5_L5_INACTIVE)
 *   Customer codes:  SCN{N}_CUST
 *   Product codes:   SCN{N}_PROD
 *   Policy no:       SCN{N}_POL
 *   Payment ref:     SCN{N}_PAY
 *
 * Scenarios:
 *   S1  Base direct + referral + differential (TIER_FULL Motor)
 *   S2  TIER_PARTIAL — no referral, small differential (PORROR_CAR)
 *   S3  TIER_DIRECT_ONLY — only DIRECT_COMMISSION row (IAR_CAR_EAR)
 *   S4  Max_passed cap — Lv5 → Lv5 → Lv7 → Lv8 chain (Fire)
 *   S5  Inactive upline skipped — differential walks past to next live
 *   S6  Missing matrix cell — no accrual at all (unknown carrier×type)
 *   S7  Skip-level promotion — one big payment promotes Lv1 → Lv5
 *
 * Verification: see docs/mgm-test-prompt.md for the exact expected rows +
 * a browser-agent script to walk them.
 */
class MgmScenarioSeeder extends Seeder
{
    private int $tenantId;

    private array $ranksByLevel = [];

    private array $typesByCode = [];

    private array $carriersByCode = [];

    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if ($tenant === null) {
            $this->command?->error('No tenant found — run TenantSeeder first.');

            return;
        }
        $this->tenantId = (int) $tenant->id;

        $this->ranksByLevel = Rank::all()->keyBy('level')->all();
        $this->typesByCode = ProductType::query()
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->keyBy('code')
            ->all();
        $this->carriersByCode = Carrier::query()
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->keyBy('code')
            ->all();

        if (empty($this->ranksByLevel) || empty($this->typesByCode) || empty($this->carriersByCode)) {
            $this->command?->error('MGM prerequisites missing (ranks/types/carriers). Run db:seed first.');

            return;
        }

        // Pick a well-known non-life carrier for S1..S5 and S7.
        $nonLifeCarrier = $this->carriersByCode['AIG'] ?? $this->carriersByCode['MSIG'] ?? null;
        if ($nonLifeCarrier === null) {
            // Fallback — first non-life carrier we can find.
            $nonLifeCarrier = Carrier::query()
                ->where('tenant_id', $this->tenantId)
                ->where('insure_type', 'non-life')
                ->first();
        }
        if ($nonLifeCarrier === null) {
            $this->command?->error('No non-life carrier available.');

            return;
        }

        // For S6 we need a carrier with NO matrix cell for a specific type.
        // Easiest: create a synthetic carrier so no matrix cell will ever exist.
        $orphanCarrier = Carrier::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'code' => 'SCN6ORPH'],
            [
                'name' => 'Scenario 6 Orphan Carrier (no matrix rates)',
                'insure_type' => 'non-life',
                'active' => true,
            ],
        );

        $customer = $this->ensureCustomer();

        DB::transaction(function () use ($nonLifeCarrier, $orphanCarrier, $customer): void {
            $this->scenario1($nonLifeCarrier, $customer);
            $this->scenario2($nonLifeCarrier, $customer);
            $this->scenario3($nonLifeCarrier, $customer);
            $this->scenario4($nonLifeCarrier, $customer);
            $this->scenario5($nonLifeCarrier, $customer);
            $this->scenario6($orphanCarrier, $customer);
            $this->scenario7($nonLifeCarrier, $customer);
            $this->scenario8($nonLifeCarrier, $customer);
            $this->scenario9($nonLifeCarrier, $customer);
            $this->scenario10($nonLifeCarrier, $customer);
        });

        $this->printSummary();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Scenarios
    // ─────────────────────────────────────────────────────────────────────

    /**
     * S1 — Base case. TIER_FULL, Motor Class 1 (garage), chain Lv2 → Lv5 → Lv8.
     *   Standard rate: 10% (garage)
     *   Seller Lv2 mgmt fee: 3%     Referral rate: 1% (TIER_FULL)
     *   Upline Lv5 mgmt fee: 5%     Uplineupline Lv8 mgmt fee: 6.25%
     *   Payment: ฿10,000
     *
     * Expected commission_ledgers rows for this payment:
     *   DIRECT       → Lv2 seller     amount = 10000 * (0.10 + 0.03) = ฿1,300
     *   REFERRAL     → Lv5 (upline)   amount = 10000 * 0.01         = ฿100
     *   DIFFERENTIAL → Lv5 (upline)   diff = 0.05 - 0.03 = 0.02 → ฿200
     *   DIFFERENTIAL → Lv8 (top)      diff = 0.0625 - 0.05 = 0.0125 → ฿125
     *   (4 rows total)
     */
    private function scenario1(Carrier $carrier, Customer $customer): void
    {
        $l8 = $this->makeAgent('SCN1_L8', level: 8, parent: null, active: true, hasLicense: true);
        $l5 = $this->makeAgent('SCN1_L5', level: 5, parent: $l8, active: true, hasLicense: false);
        $l2 = $this->makeAgent('SCN1_L2', level: 2, parent: $l5, active: true, hasLicense: false);

        $product = $this->makeProduct('SCN1_PROD', $carrier, 'MOTOR_CLASS1_GARAGE');
        $policy = $this->makePolicy('SCN1_POL', $customer, $product, $carrier, $l2);
        $this->makePayment($policy, 10000, 'SCN1_PAY');
    }

    /**
     * S2 — TIER_PARTIAL. PORROR_CAR (compulsory).
     *   Standard rate: 7%
     *   Seller Lv2 mgmt fee (TIER_PARTIAL): 0.5%    Referral: 0% (skipped)
     *   Upline Lv5 mgmt fee (TIER_PARTIAL): 0.8%
     *   Payment: ฿5,000
     *
     * Expected rows:
     *   DIRECT       → Lv2 seller     10000×(0.07+0.005) actually 5000×(0.07+0.005)=฿375
     *   REFERRAL     → (none — referral_rate = 0)
     *   DIFFERENTIAL → Lv5            diff = 0.008 - 0.005 = 0.003 → 5000×0.003 = ฿15.00
     *   (2 rows total)
     */
    private function scenario2(Carrier $carrier, Customer $customer): void
    {
        $l5 = $this->makeAgent('SCN2_L5', level: 5, parent: null, active: true, hasLicense: false);
        $l2 = $this->makeAgent('SCN2_L2', level: 2, parent: $l5, active: true, hasLicense: false);

        $product = $this->makeProduct('SCN2_PROD', $carrier, 'PORROR_CAR');
        $policy = $this->makePolicy('SCN2_POL', $customer, $product, $carrier, $l2);
        $this->makePayment($policy, 5000, 'SCN2_PAY');
    }

    /**
     * S3 — TIER_DIRECT_ONLY. IAR_CAR_EAR — no mgmt fee for anyone, no referral.
     *   Standard rate: 9%
     *   Seller Lv5 mgmt fee (TIER_DIRECT_ONLY): 0%
     *   Upline Lv7 mgmt fee (TIER_DIRECT_ONLY): 0%
     *   Payment: ฿20,000
     *
     * Expected rows:
     *   DIRECT       → Lv5 seller     20000×(0.09+0)   = ฿1,800
     *   REFERRAL     → (none — referral_rate = 0)
     *   DIFFERENTIAL → Lv7  diff = 0 - 0 = 0 → ฿0.00  (audit row per user spec)
     *   (2 rows total — DIRECT + one ZERO differential)
     */
    private function scenario3(Carrier $carrier, Customer $customer): void
    {
        $l7 = $this->makeAgent('SCN3_L7', level: 7, parent: null, active: true, hasLicense: true);
        $l5 = $this->makeAgent('SCN3_L5', level: 5, parent: $l7, active: true, hasLicense: false);

        $product = $this->makeProduct('SCN3_PROD', $carrier, 'IAR_CAR_EAR');
        $policy = $this->makePolicy('SCN3_POL', $customer, $product, $carrier, $l5);
        $this->makePayment($policy, 20000, 'SCN3_PAY');
    }

    /**
     * S4 — Max_passed cap. Chain Lv5(seller) → Lv5(same rank) → Lv7 → Lv8, Fire.
     *   Standard rate FIRE_HOUSE_BASIC AIG: 9%
     *   Seller  Lv5 mgmt fee (TIER_FULL): 5%
     *   Upline1 Lv5 mgmt fee: 5%   diff = 0
     *   Upline2 Lv7 mgmt fee: 6%   diff = 0.06 - 0.05 = 0.01
     *   Upline3 Lv8 mgmt fee: 6.25% diff = 0.0625 - 0.06 = 0.0025
     *   Payment: ฿100,000
     *
     * Expected rows:
     *   DIRECT       → Lv5 seller     100000×(0.09+0.05) = ฿14,000
     *   REFERRAL     → 1st Lv5 upline 100000×0.01         = ฿1,000
     *   DIFFERENTIAL → 1st Lv5 upline diff=0 → ฿0.00 (audit row)
     *   DIFFERENTIAL → Lv7            diff=0.01 → ฿1,000
     *   DIFFERENTIAL → Lv8            diff=0.0025 → ฿250
     *   (5 rows total)
     */
    private function scenario4(Carrier $carrier, Customer $customer): void
    {
        $l8 = $this->makeAgent('SCN4_L8', level: 8, parent: null, active: true, hasLicense: true);
        $l7 = $this->makeAgent('SCN4_L7', level: 7, parent: $l8, active: true, hasLicense: true);
        $l5b = $this->makeAgent('SCN4_L5B', level: 5, parent: $l7, active: true, hasLicense: false);
        $l5a = $this->makeAgent('SCN4_L5A', level: 5, parent: $l5b, active: true, hasLicense: false);

        $product = $this->makeProduct('SCN4_PROD', $carrier, 'FIRE_HOUSE_BASIC');
        $policy = $this->makePolicy('SCN4_POL', $customer, $product, $carrier, $l5a);
        $this->makePayment($policy, 100000, 'SCN4_PAY');
    }

    /**
     * S5 — Inactive upline is SKIPPED but the walk continues.
     *   Chain: Lv2 (seller) → Lv5 INACTIVE → Lv8, Motor Class 1 garage.
     *   Standard rate: 10%
     *   Seller Lv2 mgmt fee: 3%   Referral rate: 1% (TIER_FULL)
     *   Lv5 (INACTIVE): skipped for BOTH referral and differential
     *   Lv8 mgmt fee: 6.25%  → differential diff = 0.0625 - 0.03 = 0.0325
     *   Referral goes to Lv8 (first LIVE upline above seller).
     *   Payment: ฿10,000
     *
     * Expected rows:
     *   DIRECT       → Lv2 seller     10000×(0.10+0.03) = ฿1,300
     *   REFERRAL     → Lv8            10000×0.01        = ฿100
     *   DIFFERENTIAL → Lv8            10000×0.0325      = ฿325
     *   (3 rows total — no rows for inactive Lv5)
     */
    private function scenario5(Carrier $carrier, Customer $customer): void
    {
        $l8 = $this->makeAgent('SCN5_L8', level: 8, parent: null, active: true, hasLicense: true);
        $l5x = $this->makeAgent('SCN5_L5_INACTIVE', level: 5, parent: $l8, active: false, hasLicense: false);
        $l2 = $this->makeAgent('SCN5_L2', level: 2, parent: $l5x, active: true, hasLicense: false);

        $product = $this->makeProduct('SCN5_PROD', $carrier, 'MOTOR_CLASS1_GARAGE');
        $policy = $this->makePolicy('SCN5_POL', $customer, $product, $carrier, $l2);
        $this->makePayment($policy, 10000, 'SCN5_PAY');
    }

    /**
     * S6 — Missing matrix cell. SCN6ORPH carrier has ZERO rows in
     * carrier_product_type_rates for any product-type. Engine returns null
     * from resolveBaseRate and writes ZERO ledger rows.
     *   Payment: ฿99,999 (deliberately weird — sanity check that payment is
     *   inserted but no ledger rows produced)
     *
     * Expected rows: 0.
     */
    private function scenario6(Carrier $carrier, Customer $customer): void
    {
        $l2 = $this->makeAgent('SCN6_L2', level: 2, parent: null, active: true, hasLicense: false);

        $product = $this->makeProduct('SCN6_PROD', $carrier, 'MOTOR_CLASS1_GARAGE');
        $policy = $this->makePolicy('SCN6_POL', $customer, $product, $carrier, $l2);
        $this->makePayment($policy, 99999, 'SCN6_PAY');
    }

    /**
     * S7 — Skip-level promotion. Seller starts at Lv1 (has_license=true so
     * they can jump past Lv7 gate). A single ฿1,500,000 payment produces:
     *   • rolling_3_month_volume = 1,500,000 (only period)
     *   • qualifies for Lv5 (three_month_accum_target = 1,500,000)
     *   • RankPromotionService promotes Lv1 → Lv5 BEFORE commission accrues
     *   • So the DIRECT_COMMISSION row uses Lv5's mgmt_fee_rate (5%), not Lv1's (0%)
     *
     * MOTOR_CLASS1_GARAGE, TIER_FULL, standard rate 10%.
     * No upline → no REFERRAL, no DIFFERENTIAL rows.
     *
     * Expected rows:
     *   DIRECT       → Lv5 (post-promotion)  1500000×(0.10+0.05) = ฿225,000
     *   rank_id_at_accrual should match Lv5 (not Lv1)
     *   (1 ledger row)
     * Expected side effect:
     *   rank_promotions row: from_rank_id=Lv1.id, to_rank_id=Lv5.id, trigger='auto'
     *   agents.rank_id updated to Lv5.id
     */
    private function scenario7(Carrier $carrier, Customer $customer): void
    {
        $seller = $this->makeAgent('SCN7_SELLER', level: 1, parent: null, active: true, hasLicense: true);

        $product = $this->makeProduct('SCN7_PROD', $carrier, 'MOTOR_CLASS1_GARAGE');
        $policy = $this->makePolicy('SCN7_POL', $customer, $product, $carrier, $seller);
        $this->makePayment($policy, 1500000, 'SCN7_PAY');
    }

    /**
     * S8 — 5-level upline chain. TIER_FULL, Motor Class 1 (garage) 10%.
     *   Seller Lv2 → Lv3 → Lv5 → Lv7 → Lv10 (5 tiers of humans total: 1 seller + 4 uplines).
     *   Seller Lv2 mgmt fee: 3%      Referral: 1% (TIER_FULL) → goes to direct upline (Lv3)
     *   Uplines (all TIER_FULL):
     *     Lv3 mgmt fee = 4%   diff = 4  - 3  = 1%   → 10000×0.01   = ฿100
     *     Lv5 mgmt fee = 5%   diff = 5  - 4  = 1%   → 10000×0.01   = ฿100
     *     Lv7 mgmt fee = 6%   diff = 6  - 5  = 1%   → 10000×0.01   = ฿100
     *     Lv10 mgmt fee= 6.75% diff= 6.75-6 = 0.75% → 10000×0.0075 = ฿75
     *   Payment: ฿10,000
     *
     * Expected ledger rows (6 total):
     *   DIRECT       → Lv2 seller     10000×(0.10+0.03) = ฿1,300
     *   REFERRAL     → Lv3 (direct upline) 10000×0.01   = ฿100
     *   DIFFERENTIAL → Lv3 rate=0.01000 → ฿100
     *   DIFFERENTIAL → Lv5 rate=0.01000 → ฿100
     *   DIFFERENTIAL → Lv7 rate=0.01000 → ฿100
     *   DIFFERENTIAL → Lv10 rate=0.00750 → ฿75
     */
    private function scenario8(Carrier $carrier, Customer $customer): void
    {
        $l10 = $this->makeAgent('SCN8_L10', level: 10, parent: null, active: true, hasLicense: true);
        $l7 = $this->makeAgent('SCN8_L7', level: 7, parent: $l10, active: true, hasLicense: true);
        $l5 = $this->makeAgent('SCN8_L5', level: 5, parent: $l7, active: true, hasLicense: false);
        $l3 = $this->makeAgent('SCN8_L3', level: 3, parent: $l5, active: true, hasLicense: false);
        $l2 = $this->makeAgent('SCN8_L2', level: 2, parent: $l3, active: true, hasLicense: false);

        $product = $this->makeProduct('SCN8_PROD', $carrier, 'MOTOR_CLASS1_GARAGE');
        $policy = $this->makePolicy('SCN8_POL', $customer, $product, $carrier, $l2);
        $this->makePayment($policy, 10000, 'SCN8_PAY');
    }

    /**
     * S9 — 8-agent chain (root → seller): L10 → L9 → L8 → L7 → L5 → L3 → L2 → L1.
     *   Seller is L1 (bottom). TIER_FULL Motor Class 1 (garage) 10%. ฿10,000.
     *
     *   IMPORTANT: RankPromotionService fires BEFORE accrual. Lv1 and Lv2 both
     *   have three_month_accum_target = 0, so the seller's own ฿10,000 payment
     *   promotes SCN9_L1 from Lv1 → Lv2 instantly. The engine accrues DIRECT
     *   at Lv2's mgmt fee (3%), not Lv1's (0%).
     *
     *   TIER_FULL mgmt fees along the chain, post-promotion:
     *     L1 → Lv2  mgmt=3%      (seller, max_passed starts here)
     *     L2 → Lv2  mgmt=3%      diff = 3  - 3    = 0     → ฿0     (audit row)
     *     L3 → Lv3  mgmt=4%      diff = 4  - 3    = 1%    → ฿100
     *     L5 → Lv5  mgmt=5%      diff = 5  - 4    = 1%    → ฿100
     *     L7 → Lv7  mgmt=6%      diff = 6  - 5    = 1%    → ฿100
     *     L8 → Lv8  mgmt=6.25%   diff = 6.25-6    = 0.25% → ฿25
     *     L9 → Lv9  mgmt=6.5%    diff = 6.5-6.25  = 0.25% → ฿25
     *     L10→ Lv10 mgmt=6.75%   diff = 6.75-6.5  = 0.25% → ฿25
     *
     * Expected ledger rows (9 total: 1 DIRECT + 1 REFERRAL + 7 DIFFERENTIAL):
     *   DIRECT       → SCN9_L1  applied = 0.10 + 0.03 = 0.13000 → ฿1,300
     *   REFERRAL     → SCN9_L2  applied = 0.01000              → ฿100
     *   DIFFERENTIAL → SCN9_L2  applied = 0.00000              → ฿0    (audit row)
     *   DIFFERENTIAL → SCN9_L3  applied = 0.01000              → ฿100
     *   DIFFERENTIAL → SCN9_L5  applied = 0.01000              → ฿100
     *   DIFFERENTIAL → SCN9_L7  applied = 0.01000              → ฿100
     *   DIFFERENTIAL → SCN9_L8  applied = 0.00250              → ฿25
     *   DIFFERENTIAL → SCN9_L9  applied = 0.00250              → ฿25
     *   DIFFERENTIAL → SCN9_L10 applied = 0.00250              → ฿25
     * Grand total payout across all 8 agents: ฿1,775
     */
    private function scenario9(Carrier $carrier, Customer $customer): void
    {
        $l10 = $this->makeAgent('SCN9_L10', level: 10, parent: null, active: true, hasLicense: true);
        $l9 = $this->makeAgent('SCN9_L9', level: 9, parent: $l10, active: true, hasLicense: true);
        $l8 = $this->makeAgent('SCN9_L8', level: 8, parent: $l9, active: true, hasLicense: true);
        $l7 = $this->makeAgent('SCN9_L7', level: 7, parent: $l8, active: true, hasLicense: true);
        $l5 = $this->makeAgent('SCN9_L5', level: 5, parent: $l7, active: true, hasLicense: false);
        $l3 = $this->makeAgent('SCN9_L3', level: 3, parent: $l5, active: true, hasLicense: false);
        $l2 = $this->makeAgent('SCN9_L2', level: 2, parent: $l3, active: true, hasLicense: false);
        $l1 = $this->makeAgent('SCN9_L1', level: 1, parent: $l2, active: true, hasLicense: false);

        $product = $this->makeProduct('SCN9_PROD', $carrier, 'MOTOR_CLASS1_GARAGE');
        $policy = $this->makePolicy('SCN9_POL', $customer, $product, $carrier, $l1);
        $this->makePayment($policy, 10000, 'SCN9_PAY');
    }

    /**
     * S10 — Lv2 seller with Lv5→Lv5→Lv7→Lv8 uplines (user-supplied spec).
     *   TIER_FULL, FIRE_HOUSE_BASIC (AIG standard rate 9%), payment ฿100,000.
     *
     *   Chain (root → seller):
     *     SCN10_L8   Lv8   root
     *     SCN10_L7   Lv7   parent of L5B
     *     SCN10_L5B  Lv5   parent of L5A
     *     SCN10_L5A  Lv5   parent of L2  (direct upline of seller)
     *     SCN10_L2   Lv2   SELLER
     *
     *   Max_passed walk starts at seller_mgmt_fee = 3% (Lv2 TIER_FULL):
     *     L5A mgmt 5%  → diff = 5-3   = 2%    → ฿2,000
     *     L5B mgmt 5%  → diff = 5-5   = 0     → ฿0     (audit row per spec)
     *     L7  mgmt 6%  → diff = 6-5   = 1%    → ฿1,000
     *     L8  mgmt 6.25% → diff = 6.25-6 = 0.25% → ฿250
     *
     * Expected ledger rows (6 total):
     *   DIRECT       → SCN10_L2   applied = 0.09 + 0.03 = 0.12000 → ฿12,000
     *   REFERRAL     → SCN10_L5A  (direct upline)   0.01000       → ฿1,000
     *   DIFFERENTIAL → SCN10_L5A  0.02000                         → ฿2,000
     *   DIFFERENTIAL → SCN10_L5B  0.00000  (audit row)            → ฿0
     *   DIFFERENTIAL → SCN10_L7   0.01000                         → ฿1,000
     *   DIFFERENTIAL → SCN10_L8   0.00250                         → ฿250
     *   Grand total payout across all agents: ฿16,250
     *
     * IMPORTANT: SCN10_L2 sits at Lv2 which shares a 0-volume target with Lv1,
     * so the auto-promotion pipeline is a no-op for this seller — they were
     * seeded at Lv2 and stay at Lv2 through accrual.
     */
    private function scenario10(Carrier $carrier, Customer $customer): void
    {
        $l8 = $this->makeAgent('SCN10_L8', level: 8, parent: null, active: true, hasLicense: true);
        $l7 = $this->makeAgent('SCN10_L7', level: 7, parent: $l8, active: true, hasLicense: true);
        $l5b = $this->makeAgent('SCN10_L5B', level: 5, parent: $l7, active: true, hasLicense: false);
        $l5a = $this->makeAgent('SCN10_L5A', level: 5, parent: $l5b, active: true, hasLicense: false);
        $l2 = $this->makeAgent('SCN10_L2', level: 2, parent: $l5a, active: true, hasLicense: false);

        $product = $this->makeProduct('SCN10_PROD', $carrier, 'FIRE_HOUSE_BASIC');
        $policy = $this->makePolicy('SCN10_POL', $customer, $product, $carrier, $l2);
        $this->makePayment($policy, 100000, 'SCN10_PAY');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Factory helpers
    // ─────────────────────────────────────────────────────────────────────

    private function ensureCustomer(): Customer
    {
        return Customer::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'customer_code' => 'SCN_CUST'],
            [
                'customer_type' => 'individual',
                'first_name' => 'MGM',
                'last_name' => 'ScenarioCustomer',
            ],
        );
    }

    private function makeAgent(string $code, int $level, ?Agent $parent, bool $active, bool $hasLicense): Agent
    {
        $rank = $this->ranksByLevel[$level] ?? null;
        if ($rank === null) {
            throw new \RuntimeException("Rank level {$level} not seeded.");
        }

        return Agent::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'agent_code' => $code],
            [
                'agent_type' => 'AG',
                'first_name' => $code,
                'last_name' => 'Test',
                'kind' => 'individual',
                'vat_type' => '',
                'level' => 'l'.min($level, 5), // legacy string column
                'rank_id' => $rank->id,
                'parent_agent_id' => $parent?->id,
                'active' => $active,
                'has_license' => $hasLicense,
                'approval_status' => 'approved',
                'joined_at' => now()->subYears(2)->toDateString(),
            ],
        );
    }

    private function makeProduct(string $code, Carrier $carrier, string $productTypeCode): Product
    {
        $type = $this->typesByCode[$productTypeCode] ?? null;
        if ($type === null) {
            throw new \RuntimeException("Product type {$productTypeCode} not seeded.");
        }

        return Product::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'code' => $code],
            [
                'carrier_id' => $carrier->id,
                'name' => "Scenario product {$code}",
                'product_type_id' => $type->id,
                'coverage' => 100000,
                'duration_years' => 1,
                'pay_years' => 1,
                'premium_mode' => 'annual',
                'min_premium' => 0,
                'max_premium' => 1000000,
                'min_age' => 0,
                'max_age' => 99,
                'gender' => 'all',
                'require_medical' => false,
                'smoker_accepted' => true,
                'preexisting_excluded' => false,
                'active' => true,
            ],
        );
    }

    private function makePolicy(string $policyNo, Customer $customer, Product $product, Carrier $carrier, Agent $seller): Policy
    {
        return Policy::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'policy_no' => $policyNo],
            [
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'carrier_id' => $carrier->id,
                'writing_agent_id' => $seller->id,
                'app_date' => now()->toDateString(),
                'effective_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
                'policy_year' => 1,
                'act_year' => 1,
                'new_or_renew' => 'new',
                'coverage' => 100000,
                'annual_premium' => 10000,
                'main_premium' => 10000,
                'net_premium' => 10000,
                'premium_mode' => 'annual',
                'status' => 'active',
                'vehicle_on_non_motor' => false,
            ],
        );
    }

    private function makePayment(Policy $policy, float $amount, string $reference): PolicyPayment
    {
        // Wipe any prior payment with this reference to force the observer to fire.
        // (The engine is idempotent via idempotency_key, but if the payment row
        // is reused we don't want stale ledger rows to hide new engine bugs.)
        $existing = PolicyPayment::query()
            ->where('policy_id', $policy->id)
            ->where('reference', $reference)
            ->first();
        if ($existing !== null) {
            CommissionLedger::query()->where('policy_payment_id', $existing->id)->delete();
            $existing->delete();
        }

        return PolicyPayment::create([
            'policy_id' => $policy->id,
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'method' => 'bankTransfer',
            'reference' => $reference,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Summary
    // ─────────────────────────────────────────────────────────────────────

    private function printSummary(): void
    {
        $rows = DB::table('commission_ledgers as cl')
            ->join('policy_payments as pp', 'pp.id', '=', 'cl.policy_payment_id')
            ->join('agents as ba', 'ba.id', '=', 'cl.beneficiary_agent_id')
            ->where('pp.reference', 'like', 'SCN%_PAY')
            ->orderBy('pp.reference')
            ->orderBy('cl.payout_type')
            ->orderBy('cl.id')
            ->select([
                'pp.reference as pay',
                'cl.payout_type',
                'ba.agent_code as beneficiary',
                'cl.rate_applied',
                'cl.amount',
            ])
            ->get();

        $this->command?->info('');
        $this->command?->info('== MGM SCENARIO SEEDER — commission_ledgers rows produced ==');
        $this->command?->info(sprintf('%-10s %-25s %-20s %10s %12s', 'PAY', 'PAYOUT_TYPE', 'BENEFICIARY', 'RATE', 'AMOUNT'));
        foreach ($rows as $r) {
            $this->command?->info(sprintf(
                '%-10s %-25s %-20s %10.5f %12.2f',
                $r->pay, $r->payout_type, $r->beneficiary, (float) $r->rate_applied, (float) $r->amount,
            ));
        }
        $this->command?->info('Total ledger rows: '.$rows->count());
    }
}
