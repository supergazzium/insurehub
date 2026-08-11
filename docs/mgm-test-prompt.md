# MGM Commission Engine — Browser-Agent Test Prompt

Copy the section titled **PROMPT** below and paste it into a Chrome-driven
browser agent (Claude for Chrome, Playwright MCP, camofox, etc.). The agent
will log in, run seven scenario checks against the live MGM engine, and PASS
or FAIL each one against a known-good expected-value table.

## Precondition — seed the fixtures

Before running the browser agent, apply the scenario fixtures to your local
stack:

```bash
docker compose up -d
docker exec insurehub-backend-1 php artisan db:seed --force
docker exec insurehub-backend-1 php artisan db:seed \
  --class=Database\\Seeders\\MgmScenarioSeeder --force
```

The scenario seeder plants seven policies + payments and prints the
commission ledger rows it produced. Confirm you see `Total ledger rows: 17`
in the output before proceeding.

## Expected ledger rows (source of truth)

Every row below MUST exist in `commission_ledgers` for the browser test to
pass. `AMOUNT` is Baht rounded to 2 dp; `RATE` is decimal (0.13000 = 13%).

| PAYMENT   | PAYOUT_TYPE               | BENEFICIARY       | RATE     | AMOUNT      |
|-----------|---------------------------|-------------------|----------|-------------|
| SCN1_PAY  | DIRECT_COMMISSION         | SCN1_L2           | 0.13000  | 1,300.00    |
| SCN1_PAY  | REFERRAL_FEE              | SCN1_L5           | 0.01000  | 100.00      |
| SCN1_PAY  | MANAGEMENT_DIFFERENTIAL   | SCN1_L5           | 0.02000  | 200.00      |
| SCN1_PAY  | MANAGEMENT_DIFFERENTIAL   | SCN1_L8           | 0.01250  | 125.00      |
| SCN2_PAY  | DIRECT_COMMISSION         | SCN2_L2           | 0.07500  | 375.00      |
| SCN2_PAY  | MANAGEMENT_DIFFERENTIAL   | SCN2_L5           | 0.00300  | 15.00       |
| SCN3_PAY  | DIRECT_COMMISSION         | SCN3_L5           | 0.09000  | 1,800.00    |
| SCN3_PAY  | MANAGEMENT_DIFFERENTIAL   | SCN3_L7           | 0.00000  | 0.00        |
| SCN4_PAY  | DIRECT_COMMISSION         | SCN4_L5A          | 0.14000  | 14,000.00   |
| SCN4_PAY  | REFERRAL_FEE              | SCN4_L5B          | 0.01000  | 1,000.00    |
| SCN4_PAY  | MANAGEMENT_DIFFERENTIAL   | SCN4_L5B          | 0.00000  | 0.00        |
| SCN4_PAY  | MANAGEMENT_DIFFERENTIAL   | SCN4_L7           | 0.01000  | 1,000.00    |
| SCN4_PAY  | MANAGEMENT_DIFFERENTIAL   | SCN4_L8           | 0.00250  | 250.00      |
| SCN5_PAY  | DIRECT_COMMISSION         | SCN5_L2           | 0.13000  | 1,300.00    |
| SCN5_PAY  | REFERRAL_FEE              | SCN5_L8           | 0.01000  | 100.00      |
| SCN5_PAY  | MANAGEMENT_DIFFERENTIAL   | SCN5_L8           | 0.03250  | 325.00      |
| SCN6_PAY  | *(no rows — orphan carrier)*                                          |
| SCN7_PAY  | DIRECT_COMMISSION         | SCN7_SELLER       | 0.15000  | 225,000.00  |

Additional expected state:

- `agents.rank_id` for `SCN7_SELLER` must be the ID of rank `Lv5`
  (skip-level promotion fired before accrual).
- `rank_promotions` must contain one row: agent = SCN7_SELLER,
  from_rank = Lv1, to_rank = Lv5, trigger = auto.

## Why these numbers (short version)

- **S1** — TIER_FULL, Motor garage 10% + seller Lv2 mgmt 3% = 13% direct.
  Referral 1% flat for TIER_FULL. Differential walks: Lv5 mgmt 5% > passed 3% → +2%;
  Lv8 mgmt 6.25% > passed 5% → +1.25%.
- **S2** — TIER_PARTIAL. PORROR_CAR standard 7% + Lv2 mgmt 0.5% = 7.5% direct.
  Referral rate 0 (skipped). Diff Lv5 mgmt 0.8% - passed 0.5% = 0.3%.
- **S3** — TIER_DIRECT_ONLY. IAR_CAR_EAR 9% direct. Referral rate 0. Diff row
  is written for the upline with rate 0 (audit trail per spec).
- **S4** — Max_passed cap. Same-rank Lv5 upline gets diff 0 (already covered).
  Referral goes to the seller's DIRECT upline (SCN4_L5B), same person who
  also gets a zero diff row — TWO rows for one beneficiary, distinguished
  by payout_type.
- **S5** — Inactive Lv5 is fully skipped for both referral and differential.
  Referral goes to Lv8 (first live upline). Diff Lv8 mgmt 6.25% - passed 3% = 3.25%.
- **S6** — Orphan carrier has no matrix cell. Engine returns null from
  resolveBaseRate → zero ledger rows written.
- **S7** — Payment ฿1,500,000 triggers rolling volume that meets the Lv5
  three-month target. RankPromotionService promotes Lv1 → Lv5 BEFORE the
  engine reads `rank_id`, so DIRECT uses Lv5's mgmt fee (5%).

## Login credentials (dev)

- URL: `http://localhost:5173/insurehub/`
- Email: `admin@insurehub.co.th`
- Password: `insurehub`

## Backend API endpoints for verification

- `GET  http://localhost:8080/api/v1/commission-tiers`
  Returns the 3 tiers + all rank rates. Auth required (Sanctum session cookie
  set by the login flow).
- `GET  http://localhost:8080/api/v1/product-types`
- `GET  http://localhost:8080/api/v1/carrier-product-type-rates`
- The engine outputs are in `commission_ledgers` — no admin UI ships in
  this PR. Read via:
  ```bash
  docker exec insurehub-mysql-1 mysql -uroot -prootpw insurehub -Nse "
    SELECT pp.reference, cl.payout_type, ba.agent_code AS beneficiary,
           CAST(cl.rate_applied AS DECIMAL(10,5)) AS rate,
           CAST(cl.amount AS DECIMAL(15,2)) AS amount
    FROM commission_ledgers cl
    JOIN policy_payments pp ON pp.id = cl.policy_payment_id
    JOIN agents ba ON ba.id = cl.beneficiary_agent_id
    WHERE pp.reference LIKE 'SCN%_PAY'
    ORDER BY pp.reference, cl.payout_type, cl.id
  "
  ```

## ────────────────────────────────────────────────────────────────
## PROMPT — paste this block into the browser agent
## ────────────────────────────────────────────────────────────────

You are verifying the InsureHub MGM commission engine end-to-end. Local
stack must already be running (`docker compose ps` shows backend, frontend,
mysql all Up) and the `MgmScenarioSeeder` must have run
(`docker exec insurehub-backend-1 php artisan db:seed \
  --class=Database\\Seeders\\MgmScenarioSeeder --force`).

Your goal: for every scenario below, PROVE the engine wrote the exact
ledger rows in the expected table by querying the mysql container.

### Step 0 — login and confirm session

Open Chrome. Navigate to `http://localhost:5173/insurehub/`. Fill in:
- Email: `admin@insurehub.co.th`
- Password: `insurehub`

Click "เข้าสู่ระบบ" (or "Login"). Wait for redirect to the dashboard.

Confirm login by verifying the URL is NOT `/login` anymore and that the
admin sidebar is visible. If login fails, STOP and report the failure.

### Step 1 — smoke-test the three new admin pages

Navigate to each URL in turn and confirm the page renders WITHOUT the
"หน้านี้กำลังพัฒนา" placeholder text (that placeholder means the route
wasn't wired):

1. `http://localhost:5173/insurehub/admin/commission-tiers`
   Expect: 3 tier cards (Full-commission tier / Partial-commission tier /
   Direct-only tier), each with a 10-row rank rate grid (Lv10 → Lv1).
   Take a screenshot named `mgm-01-tiers.png`.

2. `http://localhost:5173/insurehub/admin/product-types`
   Expect: sections grouped by sub_of (Motor, Fire, Life, Health, Compulsory,
   PA, Travel, Property, Marine, Other, Group), each with editable rows
   showing code + name_th + name_en + tier dropdown + active toggle.
   Take screenshot `mgm-02-product-types.png`.

3. `http://localhost:5173/insurehub/admin/carrier-product-type-rates`
   Expect: a wide sticky-header grid, carriers down the left, product-types
   across the top. Cells show percentages (e.g. "10.00") or "-" for null.
   Take screenshot `mgm-03-matrix.png`.

If any page shows the placeholder text or fails to load, STOP and report
which URL failed.

### Step 2 — verify each scenario against expected values

You need shell access to run mysql inside the DB container. If the browser
agent has shell access, use it. Otherwise skip Step 2 and report that
manual DB inspection is required.

For each scenario (`SCN1_PAY` through `SCN7_PAY`), run:

```bash
docker exec insurehub-mysql-1 mysql -uroot -prootpw insurehub -Nse "
  SELECT cl.payout_type,
         ba.agent_code,
         CAST(cl.rate_applied AS DECIMAL(10,5)),
         CAST(cl.amount AS DECIMAL(15,2))
  FROM commission_ledgers cl
  JOIN policy_payments pp ON pp.id = cl.policy_payment_id
  JOIN agents ba ON ba.id = cl.beneficiary_agent_id
  WHERE pp.reference = 'SCN<N>_PAY'
  ORDER BY cl.payout_type, cl.id
"
```

Substitute `<N>` with 1..7. Compare the rows returned to the "Expected
ledger rows" table above.

For SCN6 the expected result is ZERO rows.

For SCN7, additionally verify:
```bash
docker exec insurehub-mysql-1 mysql -uroot -prootpw insurehub -Nse "
  SELECT r.code FROM agents a JOIN ranks r ON r.id=a.rank_id
  WHERE a.agent_code='SCN7_SELLER'
"
```
Expected: `Lv5` (not `Lv1`).

And:
```bash
docker exec insurehub-mysql-1 mysql -uroot -prootpw insurehub -Nse "
  SELECT fr.code, tr.code, rp.trigger
  FROM rank_promotions rp
  JOIN agents a ON a.id = rp.agent_id
  LEFT JOIN ranks fr ON fr.id = rp.from_rank_id
  JOIN ranks tr ON tr.id = rp.to_rank_id
  WHERE a.agent_code='SCN7_SELLER'
"
```
Expected: one row with `Lv1  Lv5  auto`.

### Step 3 — report

Print a compact PASS/FAIL matrix like:

```
S1  DIRECT+REFERRAL+DIFFERENTIAL     PASS/FAIL
S2  TIER_PARTIAL no referral         PASS/FAIL
S3  TIER_DIRECT_ONLY audit zero      PASS/FAIL
S4  max_passed cap + dual row Lv5B   PASS/FAIL
S5  inactive upline skipped          PASS/FAIL
S6  no matrix cell → zero rows       PASS/FAIL
S7  skip-level promotion Lv1→Lv5     PASS/FAIL
```

For every FAIL, print:
- Expected rows (from table)
- Actual rows (from mysql query)
- The exact diff

Do NOT modify any data during the test. This is read-only.
