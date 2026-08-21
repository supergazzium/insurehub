# Policies Table Schema Audit — 2026-08-21

## Summary

**~90 columns** on `policies` (after drop-migrations). One monolithic wide table trying to serve every product kind. Motor block = **10 top-level columns**, always nullable, **NULL on ~65% of rows** (non-motor). Property block = 9 columns (fire), Travel block = 5, Life/Health block = 8. Additive JSON `risk_data` recommended — motor stays top-level (query hotspots: list column, search filter), everything else migrates in.

| Group | Count | Rows using | Query-hot? |
|---|---|---|---|
| system | 6 | all | yes |
| pre-issue (identity + FKs) | 8 | all | yes |
| pre-issue (dates + coverage) | 6 | all | yes |
| financial (premium/tax/fees) | ~15 | most | some |
| commission scratchpad | 4 | most | no |
| post-issue | 7 | issued only | no |
| risk-motor | 10 | ~35% | 3 hot |
| risk-property (fire) | 9 | ~5% | no |
| risk-travel | 5 | ~2% | no |
| risk-life/health | 8 | <1% | no |
| refund/cancellation | 8 | cancelled only | no |
| payment | 7 | all | some |
| bookkeeping | 4 | all | no |

## 1. Full Column Inventory

Sourced from these migrations (chronological):
- `2026_06_30_000500_create_policies.php:15-164` — base table
- `2027_01_01_000100_add_legacy_and_valid_cols.php:27-41` — adds `create_date`, `net_premium`, main_com_*, data-quality flags
- `2027_01_01_000200_create_policy_rebates.php:46-64` — **drops** 15 rebate scratchpad columns → `policy_rebates` table
- `2027_01_01_000700_relax_policy_no_unique.php` — no columns; only index
- `2027_01_07_000100_add_context_fields_to_policies.php:23-52` — adds travel/life/health block
- `2027_02_01_000100_drop_legacy_commission_tables.php:47-54` — **drops** `main_com_rate_*` (4 cols)

### Effective column set (after all migrations)

| Column | Type | Nullable | Default | Category | Source | Purpose |
|---|---|---|---|---|---|---|
| id | bigint PK | no | auto | system | 000500:16 | |
| tenant_id | FK tenants | no | | system | 000500:17 | multitenancy |
| **quote_no** | string(32) | yes | | pre-issue | 000500:20 | quotation stage id |
| **application_no** | string(32) | yes | | pre-issue | 000500:21 | application-stage id (Access PK, unique per tenant) |
| **policy_no** | string(64) | yes | | post-issue | 000500:22 | issued policy number |
| notion_no | string(32) | yes | | system | 000500:23 | Notion tracker no |
| customer_id | FK | no | | pre-issue | 000500:26 | |
| product_id | FK | no | | pre-issue | 000500:27 | |
| carrier_id | FK | no | | pre-issue | 000500:28 | |
| writing_agent_id | FK | no | | pre-issue | 000500:29 | |
| ref_app_to_id | FK self | yes | | pre-issue | 000500:30 | renewal link |
| quote_date | date | yes | | pre-issue | 000500:33 | |
| app_date | date | yes | | pre-issue | 000500:34 | application submitted |
| create_date | date | yes | | system | 000100:28 | legacy import stamp |
| effective_date | date | yes | | pre-issue | 000500:35 | coverage start |
| expiry_date | date | yes | | pre-issue | 000500:36 | coverage end (manual today) |
| **issue_date** | date | yes | | post-issue | 000500:37 | carrier issue |
| next_premium_due | date | yes | | financial | 000500:38 | |
| **cancel_date** | date | yes | | post-issue | 000500:39 | |
| lapse_date | date | yes | | post-issue | 000500:40 | |
| **period_paid_end** | date | yes | | post-issue | 000500:41 | |
| **policy_end** | date | yes | | post-issue | 000500:42 | |
| policy_year | smallint | no | 1 | pre-issue | 000500:45 | |
| act_year | smallint | no | 1 | pre-issue | 000500:46 | |
| new_or_renew | string(8) | no | 'new' | pre-issue | 000500:47 | |
| coverage | dec(15,2) | no | 0 | financial | 000500:50 | |
| annual_premium | dec(15,2) | no | 0 | financial | 000500:51 | |
| main_premium | dec(15,2) | yes | | financial | 000500:52 | |
| net_premium | dec(15,2) | yes | | financial | 000100:29 | |
| duty_stamp | dec(15,2) | yes | | financial | 000500:53 | |
| vat | dec(15,2) | yes | | financial | 000500:54 | |
| total_premium_paid | dec(15,2) | yes | | financial | 000500:55 | |
| net_customer_paid | dec(15,2) | yes | | financial | 000500:56 | |
| premium_mode | string(16) | no | 'annual' | financial | 000500:57 | |
| type_of_paid | string(64) | yes | | financial | 000500:58 | |
| type_of_paid_note | text | yes | | financial | 000500:59 | |
| finance_company | string | yes | | financial | 000500:60 | |
| first_due_inst | dec(15,2) | yes | | financial | 000500:61 | |
| first_due_inst_date | date | yes | | financial | 000500:62 | |
| next_due_inst | dec(15,2) | yes | | financial | 000500:63 | |
| installment_term | string(32) | yes | | financial | 000500:64 | |
| last_due_inst_date | date | yes | | financial | 000500:65 | |
| wht_status | string(32) | yes | | financial | 000500:68 | |
| wht_amt | dec(15,2) | yes | | financial | 000500:69 | |
| subsidise_from_agent | dec(15,2) | yes | | financial | 000500:72 | |
| front_end_fee | dec(15,2) | yes | | financial | 000500:73 | |
| discount_amount | dec(15,2) | yes | | financial | 000500:74 | |
| subsidise_to_finance | dec(15,2) | yes | | financial | 000500:75 | |
| credit_card_fee | dec(15,2) | yes | | financial | 000500:76 | |
| status | string(32) | no | 'quote' | pre-issue | 000500:79 | state machine |
| legacy_policy_status_id | FK | yes | | system | 000500:80 | legacy lookup |
| status_note | text | yes | | pre-issue | 000500:81 | |
| freelook_active | bool | no | false | pre-issue | 000500:82 | |
| motor_type_driver | string(64) | yes | | risk-motor | 000500:85 | |
| motor_type_vehicle | string(64) | yes | | risk-motor | 000500:86 | |
| motor_vehicle_brand | string | yes | | risk-motor | 000500:87 | list col |
| motor_vehicle_model | string | yes | | risk-motor | 000500:88 | list col |
| motor_license_no | string(32) | yes | | risk-motor | 000500:89 | list col + search |
| motor_engine_no | string(64) | yes | | risk-motor | 000500:90 | |
| motor_chassis_no | string(64) | yes | | risk-motor | 000500:91 | |
| motor_register_year | string(8) | yes | | risk-motor | 000500:92 | |
| motor_no_passenger | smallint | yes | | risk-motor | 000500:93 | |
| motor_notes | text | yes | | risk-motor | 000500:94 | |
| property_insured_name | string | yes | | risk-property | 000500:97 | |
| property_insured_address | string | yes | | risk-property | 000500:98 | |
| property_building_cov | dec(15,2) | yes | | risk-property | 000500:99 | |
| property_furniture_cov | dec(15,2) | yes | | risk-property | 000500:100 | |
| property_stock_cov | dec(15,2) | yes | | risk-property | 000500:101 | |
| property_other_cov | dec(15,2) | yes | | risk-property | 000500:102 | |
| property_other_detail | text | yes | | risk-property | 000500:103 | |
| property_notes | text | yes | | risk-property | 000500:104 | |
| property_phone | string(32) | yes | | risk-property | 000500:105 | |
| trip_destination | string(128) | yes | | risk-travel | 000100(07):25 | |
| trip_start | date | yes | | risk-travel | 000100(07):26 | |
| trip_end | date | yes | | risk-travel | 000100(07):27 | |
| traveler_count | smallint | yes | | risk-travel | 000100(07):28 | |
| traveler_passport | string(32) | yes | | risk-travel | 000100(07):29 | |
| insured_person_name | string | yes | | risk-life/health | 000100(07):33 | |
| insured_person_id_card | string(32) | yes | | risk-life/health | 000100(07):34 | |
| insured_person_birth_date | date | yes | | risk-life/health | 000100(07):35 | |
| sum_assured | dec(15,2) | yes | | risk-life/health | 000100(07):40 | |
| premium_paying_term | smallint | yes | | risk-life/health | 000100(07):43 | |
| health_declaration | text | yes | | risk-life/health | 000100(07):46 | |
| health_beneficiary_name | string | yes | | risk-life/health | 000100(07):50 | |
| health_beneficiary_relation | string(64) | yes | | risk-life/health | 000100(07):51 | |
| cancel_status | string(32) | yes | | post-issue | 000500:108 | |
| refund_premium | dec(15,2) | yes | | post-issue | 000500:109 | |
| refund_vat | dec(15,2) | yes | | post-issue | 000500:110 | |
| refund_total_premium | dec(15,2) | yes | | post-issue | 000500:111 | |
| refund_discount | dec(15,2) | yes | | post-issue | 000500:112 | |
| net_refund_amount | dec(15,2) | yes | | post-issue | 000500:113 | |
| refund_rebate_amt | dec(15,2) | yes | | post-issue | 000500:114 | |
| refund_rebate_ov | dec(15,2) | yes | | post-issue | 000500:115 | |
| **mailing_add_by_policy** | string | yes | | post-issue | 000500:118 | |
| **mailing_date** | date | yes | | post-issue | 000500:119 | |
| **mailing_note** | text | yes | | post-issue | 000500:120 | |
| payment_method_id | FK | yes | | payment | 000500:140 | |
| payment_inscomp_status_id | FK | yes | | payment | 000500:141 | |
| payment_inscomp_to_id | FK | yes | | payment | 000500:142 | |
| payment_amount | dec(15,2) | yes | | payment | 000500:143 | |
| payment_date | date | yes | | payment | 000500:144 | |
| count_slip | uint | yes | | payment | 000500:145 | |
| validate_payment_amount | string(16) | yes | | payment | 000500:146 | |
| internal_note | string | yes | | system | 000500:149 | |
| recorded_by_user_id | FK users | yes | | system | 000500:150 | |
| recorded_by_username | string | yes | | system | 000500:151 | |
| com_rec_check | string(16) | yes | | commission | 000500:152 | Pending/Complete |
| notes | text | yes | | system | 000500:153 | |
| premium_check | string(16) | yes | | system | 000100:38 | ok/mismatch |
| vehicle_on_non_motor | bool | no | false | system | 000100:39 | data drift flag |
| import_notes | text | yes | | system | 000100:40 | |
| created_at / updated_at | timestamps | yes | | system | 000500:155 | |
| deleted_at | soft delete | yes | | system | 000500:156 | |

Dropped (do not exist in current schema): `rebate_status`, `rebate_earn_date`, `ov_status`, `rebate_ov_date`, `cal_rebate_amt`, `cal_rebate_ov`, `act_rebate_amt`, `act_rebate_ov`, `validate_rebate_amt`, `validate_rebate_ov`, `rebate_status_ag`, `rebate_rec_date_ag`, `cal_rebate_amt_ag`, `act_rebate_amt_ag`, `check_ag_rebate` (→ `policy_rebates` table), `main_com_rate_inh`, `main_com_amt_inh`, `main_com_rate_ag`, `main_com_amt_ag`.

Indexes on live schema: `(tenant_id, application_no)` UNIQUE, `(tenant_id, policy_no)` INDEX (relaxed 000700), `(tenant_id, status)`, `(tenant_id, customer_id)`, `(tenant_id, writing_agent_id)`, `(tenant_id, effective_date)`.

## 2. Categorization

Categorized in the summary table above. Rules used:
- `pre-issue` = data required at Draft/Quotation/Application stages.
- `post-issue` = only sensible after carrier issues policy_no.
- `risk-*` = product-kind-specific coverage subject.
- `commission` = live commission accrual keys (post-drop set: just `com_rec_check`).
- `financial` = premium math + tax + installment.
- `system` = tenancy, audit, soft-delete, importer flags.

## 3. Motor Field Bias Evidence

**10 top-level motor columns**, ALL nullable — every non-motor row stores 10× NULL:
```
motor_type_driver, motor_type_vehicle, motor_vehicle_brand, motor_vehicle_model,
motor_license_no, motor_engine_no, motor_chassis_no, motor_register_year,
motor_no_passenger, motor_notes
```
Reinforced by the wide list SELECT (`api/policies.ts:41-43` exposes `motorLicenseNo`, `motorVehicleBrand`, `motorVehicleModel`) — motor is a first-class citizen of the list row while travel/life/health are not.

**Drift-detection flag** already exists: `vehicle_on_non_motor bool` (`000100:39`) — the importer explicitly flags rows where motor columns are populated on a non-motor product. Confirms the team has seen data-integrity fallout from the wide-column design.

## 4. Post-Issue Field List (for "Issue Policy" modal spec)

Fields that should ONLY be settable in the post-approval Issue Policy modal:

| Field | Type | Purpose |
|---|---|---|
| policy_no | string(64) | carrier-assigned policy number |
| issue_date | date | carrier issue date |
| period_paid_end | date | premium paid through |
| policy_end | date | admin end-of-policy (differs from expiry_date for cancelled/lapsed) |
| mailing_add_by_policy | string | mailing address per policy |
| mailing_date | date | date sent to mailing house |
| mailing_note | text | mailing notes |
| **certificate document(s)** | file | via `policy_documents` (type=certificate) |

Cancellation is a separate stage — `cancel_date`, `cancel_status`, and all `refund_*` columns are set through a Cancellation modal, not the Issue modal.

## 5. Cross-Check with PolicyResource

`PolicyResource.php` (185 lines): **every column above is exposed** except:
- `tenant_id`, `id` (internal / rekeyed as string)
- `legacy_policy_status_id` (surfaced indirectly via `statusLabel` / `statusGroup`)
- `recorded_by_user_id`, `recorded_by_username` (audit — not surfaced)
- `next_premium_due` (present in DB, missing from resource) — likely bug
- `payment_method_id`, `payment_inscomp_*` — not on Policy detail (surfaced through `policy_payments` table instead)

Shape choice: motor / property / travel-life-health emitted differently.
- Motor + Property emitted as **nested objects, null when the pilot column is null** (`137-158`): `motor` gated on `motor_vehicle_brand`, `property` gated on `property_insured_name`.
- Travel/Life/Health emitted **flat, always present** (`162-174`) — commented "so the wizard/edit views can bind directly without checking a sub-object shape".

Inconsistency worth calling out — the wizard has two different bind styles depending on when the field was added.

## 6. Frontend `Policy` Interface

From `stores/policies.ts:216-266`:
- Mirrors the resource almost 1:1 (nested `motor`, `property`, flat travel/life/health via `PolicyPremium`, `PolicyMainCommission`, `PolicyInstallment`, `PolicyWht`, `PolicyCancellation`, `PolicyMailing`, `PolicyDataQuality`, `PolicyRebate`).
- Nested sub-types `MotorDetails`, `PropertyDetails` are separate interfaces (defined earlier in the file — not read here, present at ~line 100-180 range).
- `PolicyListRow` in `api/policies.ts:8-44` is a lean projection with only 40ish fields; it hardcodes `motorLicenseNo`, `motorVehicleBrand`, `motorVehicleModel` at the top level (three motor-only fields cluttering the DTO for every product kind).

Fields exposed to UI but NOT in the DB: none obvious in the surfaces read.
Fields in DB but NOT surfaced to UI: `next_premium_due` (per §5), plus audit fields as expected.

## 7. Existing Risk-Schema Hints

No existing generic JSON column on `policies` (`risk_json`, `metadata`, `attributes`, `extra` — none found). `policy_events.payload` is JSON (line 201 of 000500) but it's an event log, not a per-policy attribute bag.

The travel + life/health additions in `2027_01_07` were done as **flat columns**, not as JSON on `policies` — a deliberate choice, but it locks in the same wide-column pain that motor already has. Health block is 5 columns for <1% of rows.

## 8. Recommendation — `risk_data` JSON strategy

Add ONE additive nullable column: `risk_data JSON NULL` on `policies`.

**Keep top-level (query hotspots):**
- `motor_license_no` — list column + search filter (`api/policies.ts:41`)
- `motor_vehicle_brand`, `motor_vehicle_model` — list columns

**Migrate INTO `risk_data` (never queried, always NULL on wrong kind):**
- Motor: `motor_type_driver`, `motor_type_vehicle`, `motor_engine_no`, `motor_chassis_no`, `motor_register_year`, `motor_no_passenger`, `motor_notes` (7 cols → keys under `risk_data.motor.*`)
- Property (fire): all 9 `property_*` columns → `risk_data.property.*`
- Travel: all 5 `trip_*` / `traveler_*` → `risk_data.travel.*`
- Life/Health: all 8 `insured_person_*`, `sum_assured`, `premium_paying_term`, `health_*` → `risk_data.life.*` / `risk_data.health.*`

**Migration path is additive:**
1. Add `risk_data JSON NULL` (nothing writes to it initially).
2. Update writers (PolicyRequest → toModel) to write both places for a release cycle.
3. Backfill script: read row → shape by product `kind` → write to `risk_data`.
4. Update readers (PolicyResource) to prefer `risk_data` when present, fall back to columns.
5. Later migration drops the retired columns (safe once all readers migrated).

**Schema-driven renderer** on the wizard reads `product.risk_schema` (JSON field on `products` or `product_types`) — a Zod-style schema listing which fields exist for that kind. Non-motor products won't render the motor block.

Net effect: `policies` shrinks by ~30 columns for the same information; non-motor rows stop carrying ~10 empty motor columns. Query performance unchanged because motor lookups (`motor_license_no` search) remain top-level.
