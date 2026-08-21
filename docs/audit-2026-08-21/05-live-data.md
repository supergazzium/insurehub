# Policy live-data audit — application.csv distribution

Analysis of the Access-export CSV that seeds the `policies` table. All numbers are computed directly from `backend/database/seed-data/application.csv` joined against `main_product.csv` for product-type mapping. No DB or Laravel boot required.

## 1. Seed data location

- **File**: `backend/database/seed-data/application.csv`
- **Loader**: `backend/database/seeders/PolicySeeder.php:86` (`streamCsv('application.csv')`)
- **Raw rows**: 710 data rows (711 with header)
- **Rows with `Application_code` set**: **515** — the seeder's first filter (`PolicySeeder.php:87-90`)
- **Rows expected to survive join filters**: **≤515**. Spec says "474 rows" — the gap (~41 rows) is drop-outs from the seeder's additional join filters at `PolicySeeder.php:92-121`:
  - `skipped_no_customer` (Client_Code missing from `customers` table)
  - `skipped_no_product` (Product_Code missing)
  - `skipped_no_carrier` (INC_Code missing)
  - `skipped_no_agent` (Insure_Influencer_Code missing)
  - `skipped_duplicate_policy_no`
- **Enrichment**: `main_product.csv` (894 rows) — joined on `Product_Code` for category → kind mapping.

## 2. Status distribution (mapped through `PolicySeeder::STATUS_MAP`)

| Raw Access status | Count | % of 515 | Mapped to new enum |
|---|---:|---:|---|
| อนุมัติแล้ว | 490 | 95.1% | `active` |
| Cancel | 11 | 2.1% | `cancelled` |
| รอพิจารณา | 10 | 1.9% | `submitted` |
| รอตรวจรถ | 3 | 0.6% | `submitted` |
| Reject | 1 | 0.2% | `cancelled` |
| **Total** | **515** | 100% | |

Mapped totals: `active` 490, `submitted` 13, `cancelled` 12. Any other raw value falls through to `submitted` per `PolicySeeder.php:124`.

**Key implication for state machine**: 95% of the seeded fleet is in one bucket. The old `quote / application / issued / active` distinction is not preserved in the source data — everything active-ish becomes `active`. The new state machine's `Issued` vs `Active` split cannot be reconstructed from CSV; the backfill must decide by rule (e.g., `expiry_date > now` → `Active`, else `Expired`).

## 3. Product-type / kind distribution

Raw Categories from `main_product.csv` on the 515 policy rows:

| Categories | Count | % |
|---|---:|---:|
| การประกันภัยเบ็ดเตล็ด (misc-parent) | 207 | 40.2% |
| การประกันรถโดยความสมัครใจ (voluntary motor) | 95 | 18.4% |
| การประกันอัคคีภัย (fire) | 81 | 15.7% |
| การประกันรถโดยข้อบังคับแห่งกฏหมาย (CTPL / พรบ) | 68 | 13.2% |
| ประเภทสามัญ (life) | 52 | 10.1% |
| ประกันกลุ่ม (group life) | 11 | 2.1% |
| ต่อภาษี (tax renewal) | 1 | 0.2% |

Applied `kind_of(cat, sub)` mapping — where `การประกันภัยเบ็ดเตล็ด` splits into travel/health/pa/marine/misc via `Sub_Categories`:

| Kind | Count | % |
|---|---:|---:|
| motor (voluntary + CTPL) | 163 | 31.7% |
| travel | 149 | 28.9% |
| fire | 81 | 15.7% |
| life (ประเภทสามัญ + ประกันกลุ่ม) | 63 | 12.2% |
| pa (ประกันอุบัติเหตุส่วนบุคคล) | 36 | 7.0% |
| misc | 12 | 2.3% |
| health | 8 | 1.6% |
| marine (ขนส่ง) | 2 | 0.4% |
| tax | 1 | 0.2% |

**Motor is 32% of the fleet — but 68% of rows are non-motor and currently store NULL in every motor column (see §4).**

## 4. Motor-field fill rate — the "motor bias" evidence

| Column | Filled | Null | Fill % |
|---|---:|---:|---:|
| Type_Driver | 164 | 351 | 31.8% |
| Type_Vehicle | 167 | 348 | 32.4% |
| Vehicle_Brand | 167 | 348 | 32.4% |
| Vehicle_model | 165 | 350 | 32.0% |
| License_no | 166 | 349 | 32.2% |
| Engine_No | 167 | 348 | 32.4% |
| chassis_No | 167 | 348 | 32.4% |
| Register_Year | 167 | 348 | 32.4% |
| No_Passenger | 145 | 370 | 28.2% |

Restricted to **non-motor rows only (n=352)** — these are the ones the current schema forces to carry motor columns:

| Column | Filled | Null | Fill % (should be ~0) |
|---|---:|---:|---:|
| Type_Driver | 7 | 345 | 2.0% |
| Type_Vehicle | 4 | 348 | 1.1% |
| Vehicle_Brand | 4 | 348 | 1.1% |
| Vehicle_model | 4 | 348 | 1.1% |
| License_no | 4 | 348 | 1.1% |
| Engine_No | 4 | 348 | 1.1% |
| chassis_No | 4 | 348 | 1.1% |
| Register_Year | 4 | 348 | 1.1% |
| No_Passenger | 3 | 349 | 0.9% |

**~98.9% NULL on non-motor rows** = 9 wasted columns × 352 rows = **~3168 NULL cells**. The 4 non-motor rows with vehicle data are legacy noise (probably data-entry error in the Access DB — e.g., a fire policy that got a plate number entered by mistake).

Confirms the audit finding: motor columns are top-level on `policies` but semantically belong under `risk_data.motor.*`.

## 5. Duration distribution (Coverage_End - Coverage_start)

| Bucket | Count | Notes |
|---|---:|---|
| 1-7 days | 110 | Almost all travel |
| 8-30 days | 18 | Travel |
| 31-180 days | 10 | Mixed |
| 181-365 days | 347 | Motor + fire + life 1-year |
| 366-730 days (1-2y) | 4 | Rare |
| 731-1825 days (2-5y) | 16 | Fire multi-year |
| >1825 days (>5y) | 6 | Fire long-term |

Missing dates: 0 — every seeded row has both dates.

By **kind × duration** — this validates the wizard's duration quick-chip design:

| Kind | Dominant duration | Evidence |
|---|---|---|
| **motor** (n=162 dated) | **1 year** | 161 of 162 sit in 181-365d bucket |
| **travel** (n=149 dated) | **1-7 days** (n=107) + 8-30d (n=17) | Short trips |
| **fire** (n=81 dated) | **1 year** (n=61) or **2-5 years** (n=13) or **>5y** (n=5) | Multi-year is real |
| **life** (n=62 dated) | 1 year (n=54) | Some short/long outliers (data noise) |
| **health** (n=7) | 1 year | All 7 sit at 181-365d |
| **pa** (n=36) | 1 year | 34 of 36 |

**Wizard chip proposal validated**:
- motor / CTPL → 1-year chip (default)
- travel → 1d, 3d, 5d, 7d, 14d, 30d chips
- fire → 1y, 3y, 5y chips
- health / pa / life → 1y default

## 6. Post-issue field fill rate — backfill mapping evidence

Grouped by mapped status:

**status=active (n=490)**

| Field | Filled | Fill % |
|---|---:|---:|
| Policy_Number | 483 | 98.6% |
| Period_Paid_End | 485 | 99.0% |
| Mailing_Add_by_Policy | 166 | 33.9% |
| Mailing_Date | 377 | 76.9% |
| Mailing_Note | 218 | 44.5% |

**status=cancelled (n=12)**

| Field | Filled | Fill % |
|---|---:|---:|
| Policy_Number | 3 | 25.0% |
| Period_Paid_End | 10 | 83.3% |
| Mailing_Add_by_Policy | 2 | 16.7% |
| Mailing_Date | 2 | 16.7% |

**status=submitted (n=13)**

| Field | Filled | Fill % |
|---|---:|---:|
| Policy_Number | 2 | 15.4% |
| Period_Paid_End | 11 | 84.6% |
| Mailing_* | 0 | 0.0% |

**Read**: active rows are ~99% "issued" (have policy_no + coverage dates). submitted rows are ~85% pre-issue. This means the state machine backfill can safely split `active` → `Issued`/`Active`/`Expired` based on `expiry_date` and treat missing-policy_no as a strong "not yet issued" signal.

## 7. Anomalies (backfill gotchas)

- **7 rows** with `status=active` but **no Policy_Number**. Likely draft-entered-as-active errors. Backfill rule: if no policy_no → downgrade to `Submitted`.
- **2 rows** with `Policy_Number` set but status is `submitted` or `cancelled` (not `active`). Ambiguous — likely cancelled-after-issue. Keep as-is.
- **0 rows** missing `Coverage_start` — seed is clean here.
- **2 rows** with `Coverage_End < Coverage_start`. Sample seen: a Cancel row with `Coverage_start=9000-01-01` (placeholder). Backfill should NULL these dates or flag for manual review.
- **1 row** with kind=`life` shows `Coverage_start=9000-01-01` (see sample §8). Same class of placeholder-date bug.

## 8. Sample rows spanning kinds (PII-redacted)

**KIND=fire (Categories=การประกันอัคคีภัย)** — 5-year term, big coverage
```
Application_code   A2407230005
Policy_Number      706-24-11-HHI-03839
Policy_Status      อนุมัติแล้ว
Coverage_start     2024-07-23
Coverage_End       2029-07-23   ← 5-year fire policy
Premium            29,557
Main_Premium       31,753.32
Coverage_amt       15,000,000
```

**KIND=life (Categories=ประเภทสามัญ / ประกันตลอดชีพ)** — placeholder-date anomaly
```
Application_code   A2512150027
Policy_Number      (empty)
Policy_Status      Cancel
Coverage_start     9000-01-01   ← placeholder, backfill must NULL
Coverage_End       9001-01-01
Premium            46,856.07
```

**KIND=motor (Categories=CTPL / พรบ)** — 1-year, plate + brand present
```
Application_code   A2512150029
Policy_Number      250901/M007987921
Coverage_start     2025-12-15
Coverage_End       2026-12-15   ← 1-year motor
Vehicle_Brand      TOYOTA
Vehicle_model      HILUX
License_no         ฆง8***
Main_Premium       645.21
```

**KIND=travel (Categories=เบ็ดเตล็ด / ประกันการเดินทาง)** — 4-day trip
```
Application_code   A2512190014
Policy_Number      25-52095834
Coverage_start     2025-12-18
Coverage_End       2025-12-22   ← 4 days
Premium            330
Coverage_amt       1,000,000
```

**KIND=health (Categories=เบ็ดเตล็ด / สุขภาพ)** — 1-year individual health
```
Application_code   A2512220007
Policy_Number      2025–G0026660–AHE
Coverage_start     2025-12-22
Coverage_End       2026-12-21   ← 1 year
Premium            129,583
```

## Summary for state machine design

1. Source data is skewed 95% → `active` after mapping. The new state enum must be inferred at backfill time (Issued vs Active vs Expired) via `expiry_date` comparison, not via lifted CSV status.
2. Motor bias in the schema is measurable: 3168 NULL cells across 9 columns just on non-motor rows. Migration to `risk_data JSON` is a clear win.
3. Duration data confirms the "quick chip" idea: 5 well-defined duration classes cover >99% of the fleet (1-7d, 1mo, 1y, 3y, 5y).
4. Backfill anomalies are small (11 rows) and manageable — placeholder dates and status/policy_no mismatches account for all of them.
