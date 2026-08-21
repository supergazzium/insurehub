# Audit 04 — Product + ProductType schema

Goal: plan an additive `kind` + `risk_schema` on **product_types** (NOT on products) so the new dynamic-risk wizard has a stable schema to key on without touching the Products page.

## Summary

| Item                             | Answer                                                                                                                          |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `kind` already exists?           | **Derived at runtime** in `App\Support\ProductKind::derive()` from `type`, `category`, `sub_category`, `sub_category_2`. Not stored. |
| `risk_schema` already exists?    | **No.** No JSON column on either table.                                                                                          |
| Where to put new fields          | `product_types` — one per taxonomy row (26 rows), not per SKU (894 rows).                                                        |
| Products page changes            | **Zero.** Fields added on product_types, consumed by wizard via product → productType relationship.                              |
| Admin surface changes            | `AdminProductTypes.vue` piggybacks — inline `saveField` pattern already supports adding new columns.                             |
| Product-type count               | 26 (21 non-life + 5 life) — matches spec.                                                                                        |
| Hierarchy                        | Flat table; `sub_of` is a **string** label for UI grouping only, not a FK.                                                       |
| Breaking risk                    | 0 — both new columns are nullable + additive to Resource payload.                                                                |

---

## 1. Column inventory

### `products` (created `2026_06_30_000300_create_carriers_and_products.php:60-96`)

| Column                  | Type                        | Nullable | Default   | Added by |
| ----------------------- | --------------------------- | -------- | --------- | -------- |
| id                      | bigint PK                   | no       | —         | create |
| tenant_id               | FK tenants                  | no       | —         | create |
| carrier_id              | FK carriers (restrict)      | no       | —         | create |
| code                    | string(16)                  | no       | —         | create |
| name                    | string                      | no       | —         | create |
| name_en                 | string                      | yes      | null      | create |
| type                    | string(32)                  | yes      | null      | create — free-text, misused (see ProductKind notes) |
| category                | string                      | yes      | null      | create |
| sub_category            | string                      | yes      | null      | create |
| sub_category_2          | string(64)                  | yes      | null      | create — coarse Motor / Non-Motor bucket |
| main_rider              | string(32)                  | yes      | null      | create |
| summary                 | text                        | yes      | null      | create |
| coverage                | decimal(15,2)               | no       | 0         | create |
| duration_years          | usmallint                   | no       | 1         | create |
| pay_years               | usmallint                   | no       | 1         | create |
| premium_mode            | string(16)                  | no       | 'annual'  | create |
| min_premium             | decimal(15,2)               | no       | 0         | create |
| max_premium             | decimal(15,2)               | no       | 0         | create |
| min_age                 | usmallint                   | no       | 0         | create — insured-person age |
| max_age                 | usmallint                   | no       | 99        | create |
| min_sum_assure          | decimal(15,2)               | yes      | null      | create |
| max_sum_assure          | decimal(15,2)               | yes      | null      | create |
| min_rar                 | decimal(8,4)                | yes      | null      | create |
| max_rar                 | decimal(8,4)                | yes      | null      | create |
| gender                  | string(16)                  | no       | 'all'     | create |
| require_medical         | boolean                     | no       | false     | create |
| smoker_accepted         | boolean                     | no       | true      | create |
| preexisting_excluded    | boolean                     | no       | false     | create |
| occupation_classes      | json                        | yes      | null      | create |
| notes                   | text                        | yes      | null      | create |
| active                  | boolean                     | no       | true      | create |
| timestamps + soft-deletes                                                                                    |
| commission_code         | string(16), index           | yes      | null      | `2027_01_01_000100_add_legacy_and_valid_cols.php:14` (added, later dropped `:71-72`, re-added elsewhere — see file) |
| valid_start / valid_end | date                        | yes      | null      | same migration, dropped in down |
| coverage_class          | string(8)                   | yes      | null      | `2027_01_06_000100_add_motor_fields_to_products.php:20` — Thai motor tier "1", "2+", "2", "3+", "3" |
| vehicle_age_min         | usmallint                   | yes      | null      | same, `:21` — motor bias in Products schema |
| vehicle_age_max         | usmallint                   | yes      | null      | same, `:22` |
| product_type_id         | FK product_types (nullOnDel)| yes      | null      | `2027_02_03_000200_add_product_type_id_to_products.php:27-33` |
| commission_tier_id      | FK commission_tiers         | yes      | null      | `2027_02_10_000100_add_commission_tier_id_to_products.php:34-41` |

### `product_types` (created `2027_02_03_000100_create_product_types.php:32-51`)

| Column      | Type               | Nullable | Default | Notes |
| ----------- | ------------------ | -------- | ------- | ----- |
| id          | bigint PK          | no       | —       |       |
| tenant_id   | FK tenants         | no       | —       |       |
| code        | string(64)         | no       | —       | e.g. `MOTOR_CLASS1_GARAGE` |
| name_th     | string(128)        | no       | —       |       |
| name_en     | string(128)        | no       | —       |       |
| sub_of      | string(32)         | yes      | null    | Grouping label — NOT a FK. "Motor" / "Fire" / "Health" / ... |
| tier_id     | FK commission_tiers (restrict) | no | — | Descriptive only after `2027_02_10` moved commission tier onto products directly. |
| sort_order  | usmallint          | no       | 0       |       |
| active      | boolean            | no       | true    |       |
| notes       | string             | yes      | null    |       |
| timestamps                                                             |
| unique(tenant_id, code); index(tenant_id, tier_id)                     |

## 2. Model + Resource inventory

**Product** (`backend/app/Models/Product.php`)
- `$guarded = ['id']` → all columns mass-assignable.
- Casts: `occupation_classes` array; `require_medical/smoker_accepted/preexisting_excluded/active` boolean; `valid_start/valid_end` date.
- Relations: `tenant()`, `carrier()`, `productType()`, `commissionTier()`, `commissionRates()`, `commissionBands()`.

**ProductResource** (`backend/app/Http/Resources/ProductResource.php:22-70`)
- Emits every scalar column (32 keys total).
- **Line 43**: `productKind` — computed live via `ProductKind::derive($this->type, $this->category, $this->sub_category_2, $this->sub_category)`. Consumed by the wizard.
- Nested: `commissionRates` (scheme + two panels), `commissionBands` (life bands).

**ProductType** (`backend/app/Models/ProductType.php`)
- `$guarded = ['id']`; casts `sort_order` int, `active` bool.
- Relations: `tenant()`, `tier()`, `products()` (has-many).

**ProductTypeResource** (`backend/app/Http/Resources/ProductTypeResource.php:18-32`)
- Emits: `id, code, nameTh, nameEn, subOf, tierId, tierCode, tierNameTh, sortOrder, active, notes`.

## 3. Hierarchy — `sub_of`

Migration comment (`2027_02_03_000100:26-29`): *"Flat taxonomy (no parent_id). If the list grows past ~30 entries, we can promote parent_code to a proper FK — for now sub_of is a string used only for display grouping."*

Distinct `sub_of` values in the seeder:

```
Motor       → 6 types (MOTOR_CLASS1_GARAGE/DEALER, MOTOR_CLASS23, MOTOR_HEAVY_*)
Compulsory  → 2 types (PORROR_CAR, PORROR_OTHER — พรบ)
PA          → 1  (PA_INDIVIDUAL)
Travel      → 1  (TA_INDIVIDUAL)
Fire        → 4  (FIRE_HOUSE/SME × BASIC/PACKAGE)
Property    → 1  (IAR_CAR_EAR)
Marine      → 1  (MARINE)
Other       → 1  (MISC)
Health      → 2  (HEALTH_ADULT, HEALTH_CHILD)
Group       → 2  (GROUP_ACCIDENT, GROUP_HEALTH)
Life        → 5  (WHOLE_LIFE_STANDARD, ENDOWMENT_STANDARD, ANNUITY, TERM, LIFE_RIDER)
                             ── total 26 ──
```

## 4. 26 product types

From `backend/database/seeders/ProductTypeSeeder.php:38-72`:

| # | code                    | name_th                            | name_en                          | sub_of     | tier             |
| - | ----------------------- | ---------------------------------- | -------------------------------- | ---------- | ---------------- |
| 1 | MOTOR_CLASS1_GARAGE     | มอเตอร์ ชั้น 1 (อู่)               | Motor Class 1 (Garage)           | Motor      | TIER_FULL        |
| 2 | MOTOR_CLASS1_DEALER     | มอเตอร์ ชั้น 1 (ห้าง)              | Motor Class 1 (Dealer)           | Motor      | TIER_FULL        |
| 3 | MOTOR_CLASS23           | มอเตอร์ ชั้น 2+/3+/2/3             | Motor Class 2+/3+/2/3            | Motor      | TIER_FULL        |
| 4 | MOTOR_HEAVY_GARAGE      | มอเตอร์รถหนัก (อู่)                | Motor Heavy Vehicle (Garage)     | Motor      | TIER_FULL        |
| 5 | MOTOR_HEAVY_DEALER      | มอเตอร์รถหนัก (ห้าง)               | Motor Heavy Vehicle (Dealer)     | Motor      | TIER_FULL        |
| 6 | MOTOR_HEAVY_CLASS23     | มอเตอร์รถหนัก ชั้น 2+/3+/2/3       | Motor Heavy Class 2+/3+/2/3      | Motor      | TIER_FULL        |
| 7 | PORROR_CAR              | พรบ (เก๋ง/กระบะ/ตู้)                | Compulsory (Car)                 | Compulsory | TIER_PARTIAL     |
| 8 | PORROR_OTHER            | พรบ (อื่นๆ)                        | Compulsory (Other)               | Compulsory | TIER_PARTIAL     |
| 9 | PA_INDIVIDUAL           | PA รายเดี่ยว                       | Personal Accident (Individual)   | PA         | TIER_FULL        |
| 10| TA_INDIVIDUAL           | TA รายเดี่ยว (ท่องเที่ยว)          | Travel Accident (Individual)     | Travel     | TIER_FULL        |
| 11| FIRE_HOUSE_BASIC        | Fire บ้าน (พื้นฐาน)                | Fire House (Basic)               | Fire       | TIER_FULL        |
| 12| FIRE_SME_BASIC          | Fire SME (พื้นฐาน)                 | Fire SME (Basic)                 | Fire       | TIER_FULL        |
| 13| FIRE_HOUSE_PACKAGE      | Fire บ้าน (Package)                | Fire House (Package)             | Fire       | TIER_FULL        |
| 14| FIRE_SME_PACKAGE        | Fire SME (Package)                 | Fire SME (Package)               | Fire       | TIER_FULL        |
| 15| IAR_CAR_EAR             | IAR / CAR / EAR                    | IAR / CAR / EAR                  | Property   | TIER_DIRECT_ONLY |
| 16| MARINE                  | ประกันขนส่ง + Marine               | Cargo + Marine                   | Marine     | TIER_PARTIAL     |
| 17| MISC                    | MISC                               | Miscellaneous                    | Other      | TIER_DIRECT_ONLY |
| 18| HEALTH_ADULT            | ประกันสุขภาพผู้ใหญ่                | Health (Adult)                   | Health     | TIER_PARTIAL     |
| 19| HEALTH_CHILD            | ประกันสุขภาพเด็ก                    | Health (Child)                   | Health     | TIER_PARTIAL     |
| 20| GROUP_ACCIDENT          | ประกันกลุ่ม อุบัติเหตุ              | Group Accident                   | Group      | TIER_DIRECT_ONLY |
| 21| GROUP_HEALTH            | ประกันกลุ่ม สุขภาพ                 | Group Health                     | Group      | TIER_DIRECT_ONLY |
| 22| WHOLE_LIFE_STANDARD     | ประกันชีวิตตลอดชีพ (ประเภทสามัญ)   | Whole Life (Standard)            | Life       | TIER_FULL        |
| 23| ENDOWMENT_STANDARD      | ประกันสะสมทรัพย์ (ประเภทสามัญ)     | Endowment (Standard)             | Life       | TIER_FULL        |
| 24| ANNUITY                 | ประกันบำนาญ                        | Annuity                          | Life       | TIER_FULL        |
| 25| TERM                    | ประกันชีวิตชั่วระยะเวลา            | Term Life                        | Life       | TIER_FULL        |
| 26| LIFE_RIDER              | สัญญาเพิ่มเติม (Rider)              | Life Rider                       | Life       | TIER_PARTIAL     |

## 5. Kind mapping (26 → 6)

| code                    | kind    |
| ----------------------- | ------- |
| MOTOR_CLASS1_GARAGE     | motor   |
| MOTOR_CLASS1_DEALER     | motor   |
| MOTOR_CLASS23           | motor   |
| MOTOR_HEAVY_GARAGE      | motor   |
| MOTOR_HEAVY_DEALER      | motor   |
| MOTOR_HEAVY_CLASS23     | motor   |
| PORROR_CAR              | motor   |
| PORROR_OTHER            | motor   |
| TA_INDIVIDUAL           | travel  |
| FIRE_HOUSE_BASIC        | fire    |
| FIRE_SME_BASIC          | fire    |
| FIRE_HOUSE_PACKAGE      | fire    |
| FIRE_SME_PACKAGE        | fire    |
| IAR_CAR_EAR             | fire    |
| MARINE                  | misc    |
| PA_INDIVIDUAL           | misc    |
| MISC                    | misc    |
| HEALTH_ADULT            | health  |
| HEALTH_CHILD            | health  |
| GROUP_ACCIDENT          | health  |
| GROUP_HEALTH            | health  |
| WHOLE_LIFE_STANDARD     | life    |
| ENDOWMENT_STANDARD      | life    |
| ANNUITY                 | life    |
| TERM                    | life    |
| LIFE_RIDER              | life    |

Notes:
- CTPL (พรบ) → `motor` — Thai practice treats it as part of the motor risk block (vehicle plate, chassis, engine).
- IAR/CAR/EAR → `fire` — property-risk block (fire risk-schema covers Industrial All Risks + Construction/Erection All Risks reasonably; a separate `property` kind is optional if the risk fields diverge).
- PA_INDIVIDUAL & MARINE → `misc` — no dedicated block; treated as "no risk-specific fields, just standard party + coverage".

## 6. Products page constraints

Frontend product surface:

| file                                                   | role                     |
| ------------------------------------------------------ | ------------------------ |
| `frontend/src/pages/products/ProductManagementV2.vue`  | list                     |
| `frontend/src/pages/products/ProductCreateModal.vue`   | create                   |
| `frontend/src/pages/products/ProductDetailDrawer.vue`  | detail + edit            |
| `frontend/src/pages/products/productNamePresets.ts`    | helper                   |

Grep confirms: **no reference to `kind`, `riskSchema`, `risk_schema`, or `productKind` in the Product pages** — the only `kind` hit is a local `'carrierToHub' | 'hubToAgent'` parameter on `ProductDetailDrawer.vue:154-160` (commission bands, unrelated). Adding fields to `product_types` therefore requires **zero edits** to any of these files.

## 7. Additive plan — **on product_types**

Recommendation: **add `kind` and `risk_schema` to `product_types`**, not to `products`.

Reasons:
1. Risk fields are a property of the *taxonomy*, not each SKU. 894 products should not each carry a copy of the same schema.
2. `ProductKind::derive()` already infers kind from the coarse type/category strings — we replace derivation with a stored value on the type, and Product's runtime derive can fall through to the type when set. Zero behavior change until admin populates the field.
3. Admin edits kind + risk_schema in one place (`AdminProductTypes.vue`) — the existing surface for taxonomy admin. No new admin page needed.
4. Products page stays untouched (satisfies spec constraint).

Migration name: `2027_02_14_000100_add_kind_and_risk_schema_to_product_types.php`

```php
Schema::table('product_types', function (Blueprint $t): void {
    // 'motor' | 'travel' | 'fire' | 'health' | 'life' | 'misc'
    $t->string('kind', 16)->nullable()->after('sub_of');
    // JSON schema describing risk fields the wizard renders on Step 3.
    // Format: { fields: [{ key, label_th, label_en, type, required, options?, ... }] }
    $t->json('risk_schema')->nullable()->after('kind');
});
```

Backfill (in the same migration or a follow-up seeder revision): loop the 26-row mapping in §5 and set `kind` on each. `risk_schema` stays null until admin authors JSON per kind (default renderer keys on `kind` alone until then).

## 8. Payload additivity

**Response (ProductTypeResource — the only edit)**:

```php
'kind' => $this->kind,             // string|null
'riskSchema' => $this->risk_schema, // array|null  (cast added)
```

**ProductResource passthrough** (optional but recommended so the wizard doesn't need a second fetch):

```php
'productType' => $this->productType ? [
    'id' => (string) $this->productType->id,
    'code' => $this->productType->code,
    'kind' => $this->productType->kind,
    'riskSchema' => $this->productType->risk_schema,
] : null,
```

**Request additions**:

```php
// ProductTypeRequest — additive:
'kind' => ['sometimes', 'nullable', 'string', 'in:motor,travel,fire,health,life,misc'],
'riskSchema' => ['sometimes', 'nullable', 'array'],
'riskSchema.fields' => ['sometimes', 'array'],
```

Product store/update payloads: **no changes.** Kind is set via product-type admin, not per-product.

## 9. product_types admin surface

Location: `/insurehub/admin/product-types` → `frontend/src/pages/admin/AdminProductTypes.vue`.

Current state:
- Full CRUD; inline `saveField(row, field)` PATCH-per-field pattern (`AdminProductTypes.vue:62-80`).
- Type interface at `frontend/src/api/mgm.ts:62-74` (11 fields, no `kind` / `riskSchema`).

To add `kind` + `riskSchema`:

1. Extend `ProductType` interface at `api/mgm.ts:62-74` with `kind: string | null; riskSchema: RiskSchema | null`.
2. Add `case 'kind': ...` and `case 'riskSchema': ...` in `saveField()`.
3. In the template row, add a `<select>` for kind (motor / travel / fire / health / life / misc) and a "Edit schema…" button that opens a JSON editor modal (or a light form).

Minimal admin form estimate: **~50 lines of Vue** on top of AdminProductTypes.vue. No new page.

## 10. Recommendation

- Schema plan: `product_types` gains 2 nullable columns — `kind VARCHAR(16)` and `risk_schema JSON`. `products` untouched.
- Migration: `2027_02_14_000100_add_kind_and_risk_schema_to_product_types.php` + optional backfill of the 26-row kind mapping in §5 via the same migration or `ProductTypeSeeder.php` update.
- Model: cast `risk_schema` → array; whitelist trivial via existing `$guarded = ['id']`.
- Resources: extend `ProductTypeResource` + optionally add a `productType` sub-object to `ProductResource` so the wizard hydrates in one fetch.
- Request: `ProductTypeRequest` gains three nullable rules — `kind`, `riskSchema`, `riskSchema.fields`.
- Admin UI: `AdminProductTypes.vue` gains a `kind` select + "Edit schema…" JSON editor button. Piggy-backs the existing inline saveField pattern.
- Wizard consumption: replace `ProductResource::productKind` derivation call with `product.productType?.kind ?? ProductKind::derive(...)` — derivation stays as fallback for taxonomy rows the admin hasn't set yet.
- Products page (`ProductManagementV2.vue`, `ProductCreateModal.vue`, `ProductDetailDrawer.vue`): **zero edits.** No renames, no drops, no required fields, no UI redesign — satisfies spec constraint.
- Breaking risk: **0.** Both columns nullable; null value falls through to the existing `ProductKind::derive()` behavior, so pre-populated policies + wizard continue working unchanged.
- Rollout: ship migration + Resource + Model cast first (no UI). Then admin form. Then wizard consumes `productType.kind` and `productType.riskSchema`.
