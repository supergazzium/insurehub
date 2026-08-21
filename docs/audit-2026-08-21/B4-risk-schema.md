# B4 — `product_types.risk_schema` JSON format

Design doc for the JSON shape stored on `product_types.risk_schema` and
consumed by the new 5-step wizard's Step 3 dynamic risk-field renderer.
Storage of user-entered values goes into `policies.risk_data` (JSON) —
schema keys and value keys mirror 1:1.

Ground-truth references:
- Field inventory: `02-policy-schema.md` (motor 10 cols, property 9, travel 5, health 8)
- Taxonomy: `04-product-schema.md` (26 types → 6 kinds)
- Existing wizard branching: `03-wizard-current.md` §2 Step 3
- Constraints: `00-summary.md` (motor `license_no`/`vehicle_brand`/`vehicle_model` stay top-level)

## 1. Schema contract

### Root shape

```json
{
  "version": 1,
  "kind": "motor",
  "sections": [
    {
      "key": "vehicle",
      "label_th": "รายละเอียดรถยนต์",
      "label_en": "Vehicle details",
      "fields": []
    }
  ]
}
```

- `version` — integer, incremented on breaking edits. Renderer must
  reject unknown versions with a fallback ("Please upgrade the app").
  Non-breaking additions (new field with a default, new optional
  section) keep the version.
- `kind` — one of `motor | travel | fire | health | life | misc`. Must
  match `product_types.kind` on the same row; a mismatch is a data
  integrity bug and the admin editor should refuse to save it.
- `sections` — ordered array. Renders as vertically stacked cards under
  a shared H2 for the section label. `sections: []` renders no risk
  block (misc default).

### Field shape

```json
{
  "key": "license_no",
  "label_th": "ทะเบียนรถ",
  "label_en": "License plate",
  "type": "string",
  "required": true,
  "storage": "column",
  "column_name": "motor_license_no",
  "placeholder": "กข1234",
  "help_th": "…",
  "help_en": "…",
  "default": null,
  "options": [
    { "value": "class1_garage", "label_th": "ชั้น 1 อู่", "label_en": "Class 1 Garage" }
  ],
  "validation": {
    "min": 1,
    "max": 32,
    "pattern": "^[A-Z0-9ก-๙\\s\\-]+$",
    "validator": "thai_license_plate"
  },
  "depends_on": null,
  "prior_autofill": true
}
```

Key-by-key justification:

| Key | Purpose | Required? |
|---|---|---|
| `key` | JSON path segment. Must be `[a-z_][a-z0-9_]*`. Immutable once shipped (renaming = migration). | yes |
| `label_th` / `label_en` | Inline labels — see §6 for justification. | both required |
| `type` | Renderer picks the widget. Enum: `string | text | number | date | select | boolean | passport | phone | array_of_objects`. `string` is a single-line input; `text` is a multi-line textarea. | yes |
| `required` | Enforced only when the wizard reaches "Submit to carrier". Draft and Quotation ignore it. | default `false` |
| `storage` | `column` writes the field to a top-level policies column (see `column_name`); `risk_data` (default) writes to `policies.risk_data.<section_key>.<field_key>`. | default `risk_data` |
| `column_name` | The exact top-level column when `storage=column`. Renderer ignores it — used only by writer/reader shim in B2. | required iff `storage=column` |
| `placeholder` / `help_th` / `help_en` | Renderer hints. Optional. | no |
| `default` | Initial value applied when the wizard opens a fresh Draft. | default `null` |
| `options` | Enum for `type=select`. Values are stored verbatim; labels are display-only. | required iff `type=select` |
| `validation` | See §4. Subset of Zod's runtime checks. | optional |
| `depends_on` | `{ field: "trip_start", operator: "gte" }` — the target field's value participates in `min`/`max` of this field. | optional |
| `prior_autofill` | Marks the field as a candidate for the "Reuse from prior policy" dropdown (§5). | default `false` |

### Versioning strategy

- Bump `version` when a rename / removal / type change happens — any
  edit that would break an old renderer.
- Non-breaking additions (new optional field, new section, new
  `options` entry, new `help_th` line) keep the version.
- Renderer keeps a table of `{ kind → supported_version_ceiling }`. On
  a schema with `version > ceiling`, render a "Product configuration
  requires app update — contact support" banner and disable
  Save-as-Quotation / Submit. Save-as-Draft stays available so nothing
  is lost.
- Migrations that bump the version must ship a follow-up seeder that
  rewrites all `policies.risk_data` blobs to the new shape.

## 2. Worked examples — 6 kinds

### 2.1 motor

Covers MOTOR_CLASS1_GARAGE, MOTOR_CLASS1_DEALER, MOTOR_CLASS23,
MOTOR_HEAVY_*, PORROR_CAR, PORROR_OTHER (see `04-product-schema.md`
§5 kind mapping — 8 product types share this schema).

```json
{
  "version": 1,
  "kind": "motor",
  "sections": [
    {
      "key": "vehicle",
      "label_th": "รายละเอียดรถยนต์",
      "label_en": "Vehicle details",
      "fields": [
        {
          "key": "license_no",
          "label_th": "ทะเบียนรถ",
          "label_en": "License plate",
          "type": "string",
          "required": true,
          "storage": "column",
          "column_name": "motor_license_no",
          "placeholder": "กข 1234",
          "validation": { "min": 1, "max": 32, "validator": "thai_license_plate" },
          "prior_autofill": true
        },
        {
          "key": "vehicle_brand",
          "label_th": "ยี่ห้อรถ",
          "label_en": "Vehicle brand",
          "type": "string",
          "required": true,
          "storage": "column",
          "column_name": "motor_vehicle_brand",
          "prior_autofill": true
        },
        {
          "key": "vehicle_model",
          "label_th": "รุ่นรถ",
          "label_en": "Vehicle model",
          "type": "string",
          "required": true,
          "storage": "column",
          "column_name": "motor_vehicle_model",
          "prior_autofill": true
        },
        {
          "key": "register_year",
          "label_th": "ปีจดทะเบียน",
          "label_en": "Registration year",
          "type": "string",
          "validation": { "pattern": "^\\d{4}$" },
          "prior_autofill": true
        },
        {
          "key": "chassis_no",
          "label_th": "เลขตัวถัง",
          "label_en": "Chassis number",
          "type": "string",
          "validation": { "max": 64 },
          "prior_autofill": true
        },
        {
          "key": "engine_no",
          "label_th": "เลขเครื่อง",
          "label_en": "Engine number",
          "type": "string",
          "validation": { "max": 64 },
          "prior_autofill": true
        },
        {
          "key": "no_passenger",
          "label_th": "จำนวนที่นั่ง",
          "label_en": "Seats",
          "type": "number",
          "validation": { "min": 1, "max": 99 }
        },
        {
          "key": "type_vehicle",
          "label_th": "ประเภทรถ",
          "label_en": "Vehicle type",
          "type": "select",
          "options": [
            { "value": "sedan", "label_th": "เก๋ง", "label_en": "Sedan" },
            { "value": "pickup", "label_th": "กระบะ", "label_en": "Pickup" },
            { "value": "van", "label_th": "ตู้", "label_en": "Van" },
            { "value": "suv", "label_th": "SUV", "label_en": "SUV" },
            { "value": "truck", "label_th": "รถบรรทุก", "label_en": "Truck" },
            { "value": "motorcycle", "label_th": "รถจักรยานยนต์", "label_en": "Motorcycle" }
          ]
        },
        {
          "key": "type_driver",
          "label_th": "ประเภทผู้ขับขี่",
          "label_en": "Driver type",
          "type": "select",
          "options": [
            { "value": "named", "label_th": "ระบุชื่อ", "label_en": "Named driver" },
            { "value": "any", "label_th": "ไม่ระบุชื่อ", "label_en": "Any driver" }
          ]
        },
        {
          "key": "notes",
          "label_th": "หมายเหตุ",
          "label_en": "Notes",
          "type": "text",
          "validation": { "max": 1000 }
        }
      ],
      "dedupe_keys": ["license_no", "chassis_no"]
    }
  ]
}
```

Storage mapping produced by the writer:
- Top-level columns: `motor_license_no`, `motor_vehicle_brand`,
  `motor_vehicle_model` (3 fields with `storage=column`).
- `policies.risk_data.vehicle.{register_year, chassis_no, engine_no,
  no_passenger, type_vehicle, type_driver, notes}` (7 fields).

### 2.2 travel

Covers TA_INDIVIDUAL. All fields under `risk_data`.

```json
{
  "version": 1,
  "kind": "travel",
  "sections": [
    {
      "key": "trip",
      "label_th": "รายละเอียดการเดินทาง",
      "label_en": "Trip details",
      "fields": [
        {
          "key": "destination",
          "label_th": "ประเทศปลายทาง",
          "label_en": "Destination",
          "type": "string",
          "required": true,
          "validation": { "max": 128 }
        },
        {
          "key": "start",
          "label_th": "วันเริ่มเดินทาง",
          "label_en": "Trip start",
          "type": "date",
          "required": true
        },
        {
          "key": "end",
          "label_th": "วันสิ้นสุดการเดินทาง",
          "label_en": "Trip end",
          "type": "date",
          "required": true,
          "depends_on": { "field": "start", "operator": "gte" }
        },
        {
          "key": "traveler_count",
          "label_th": "จำนวนผู้เดินทาง",
          "label_en": "Traveler count",
          "type": "number",
          "required": true,
          "validation": { "min": 1, "max": 99 },
          "default": 1
        },
        {
          "key": "traveler_passport",
          "label_th": "เลขหนังสือเดินทาง",
          "label_en": "Passport",
          "type": "passport",
          "validation": { "max": 32 },
          "prior_autofill": true
        }
      ],
      "dedupe_keys": ["traveler_passport"]
    }
  ]
}
```

### 2.3 fire

Covers FIRE_HOUSE_BASIC/PACKAGE, FIRE_SME_BASIC/PACKAGE, IAR_CAR_EAR.

```json
{
  "version": 1,
  "kind": "fire",
  "sections": [
    {
      "key": "property",
      "label_th": "รายละเอียดทรัพย์สิน",
      "label_en": "Property details",
      "fields": [
        { "key": "insured_name",    "label_th": "ชื่อผู้เอาประกัน (ทรัพย์สิน)", "label_en": "Insured name",    "type": "string", "required": true },
        { "key": "insured_address", "label_th": "ที่ตั้งทรัพย์สิน",             "label_en": "Insured address", "type": "text",   "required": true },
        { "key": "phone",           "label_th": "โทรศัพท์",                     "label_en": "Phone",           "type": "phone",  "validation": { "max": 32 } },
        { "key": "building_cov",    "label_th": "ความคุ้มครองอาคาร (บาท)",     "label_en": "Building coverage",  "type": "number", "validation": { "min": 0 } },
        { "key": "furniture_cov",   "label_th": "ความคุ้มครองเฟอร์นิเจอร์ (บาท)", "label_en": "Furniture coverage", "type": "number", "validation": { "min": 0 } },
        { "key": "stock_cov",       "label_th": "ความคุ้มครองสต๊อกสินค้า (บาท)", "label_en": "Stock coverage",     "type": "number", "validation": { "min": 0 } },
        { "key": "other_cov",       "label_th": "ความคุ้มครองอื่นๆ (บาท)",       "label_en": "Other coverage",     "type": "number", "validation": { "min": 0 } },
        { "key": "other_detail",    "label_th": "รายละเอียดอื่นๆ",              "label_en": "Other detail",       "type": "text" },
        { "key": "notes",           "label_th": "หมายเหตุ",                     "label_en": "Notes",              "type": "text" }
      ],
      "dedupe_keys": ["insured_address"]
    }
  ]
}
```

### 2.4 health

Covers HEALTH_ADULT, HEALTH_CHILD, GROUP_ACCIDENT, GROUP_HEALTH. Two
sections because "insured person" is the covered life while
"beneficiary" is who gets the payout.

```json
{
  "version": 1,
  "kind": "health",
  "sections": [
    {
      "key": "insured_person",
      "label_th": "ผู้เอาประกัน",
      "label_en": "Insured person",
      "fields": [
        { "key": "name",       "label_th": "ชื่อ-นามสกุล",           "label_en": "Full name",     "type": "string", "required": true, "prior_autofill": true },
        { "key": "id_card",    "label_th": "เลขบัตรประชาชน",         "label_en": "National ID",   "type": "string", "required": true, "validation": { "pattern": "^\\d{13}$", "validator": "thai_id_checksum" }, "prior_autofill": true },
        { "key": "birth_date", "label_th": "วันเกิด",                "label_en": "Birth date",    "type": "date",   "required": true },
        { "key": "sum_assured","label_th": "จำนวนเงินเอาประกัน (บาท)","label_en": "Sum assured",   "type": "number", "validation": { "min": 0 } },
        { "key": "premium_paying_term", "label_th": "ระยะเวลาชำระเบี้ย (ปี)", "label_en": "Premium paying term (years)", "type": "number", "validation": { "min": 1, "max": 99 } },
        { "key": "declaration","label_th": "แถลงสุขภาพ",             "label_en": "Health declaration", "type": "text" }
      ],
      "dedupe_keys": ["id_card"]
    },
    {
      "key": "beneficiary",
      "label_th": "ผู้รับผลประโยชน์",
      "label_en": "Beneficiary",
      "fields": [
        { "key": "name",     "label_th": "ชื่อ-นามสกุล", "label_en": "Full name", "type": "string" },
        { "key": "relation", "label_th": "ความสัมพันธ์", "label_en": "Relation",  "type": "string", "validation": { "max": 64 } }
      ]
    }
  ]
}
```

### 2.5 life

Covers WHOLE_LIFE_STANDARD, ENDOWMENT_STANDARD, ANNUITY, TERM,
LIFE_RIDER. Three sections; beneficiaries and riders are multi-row.

```json
{
  "version": 1,
  "kind": "life",
  "sections": [
    {
      "key": "insured_person",
      "label_th": "ผู้เอาประกัน",
      "label_en": "Insured person",
      "fields": [
        { "key": "name",       "label_th": "ชื่อ-นามสกุล",   "label_en": "Full name",   "type": "string", "required": true, "prior_autofill": true },
        { "key": "id_card",    "label_th": "เลขบัตรประชาชน", "label_en": "National ID", "type": "string", "required": true, "validation": { "pattern": "^\\d{13}$", "validator": "thai_id_checksum" }, "prior_autofill": true },
        { "key": "birth_date", "label_th": "วันเกิด",        "label_en": "Birth date",  "type": "date",   "required": true },
        { "key": "sum_assured","label_th": "จำนวนเงินเอาประกัน (บาท)", "label_en": "Sum assured", "type": "number", "validation": { "min": 0 } },
        { "key": "premium_paying_term", "label_th": "ระยะเวลาชำระเบี้ย (ปี)", "label_en": "Premium paying term (years)", "type": "number", "validation": { "min": 1, "max": 99 } },
        { "key": "declaration","label_th": "แถลงสุขภาพ",     "label_en": "Health declaration", "type": "text" }
      ],
      "dedupe_keys": ["id_card"]
    },
    {
      "key": "beneficiaries",
      "label_th": "ผู้รับผลประโยชน์",
      "label_en": "Beneficiaries",
      "fields": [
        {
          "key": "rows",
          "label_th": "รายชื่อผู้รับผลประโยชน์",
          "label_en": "Beneficiary list",
          "type": "array_of_objects",
          "min_rows": 0,
          "max_rows": 4,
          "row_validation": { "sum_of": "share", "equals": 100, "empty_ok": true },
          "fields": [
            { "key": "name",     "label_th": "ชื่อ-นามสกุล", "label_en": "Full name", "type": "string", "required": true },
            { "key": "relation", "label_th": "ความสัมพันธ์", "label_en": "Relation",  "type": "string", "validation": { "max": 64 } },
            { "key": "share",    "label_th": "สัดส่วน (%)",  "label_en": "Share (%)", "type": "number", "required": true, "validation": { "min": 0, "max": 100 } }
          ]
        }
      ]
    },
    {
      "key": "riders",
      "label_th": "สัญญาเพิ่มเติม (Riders)",
      "label_en": "Riders",
      "fields": [
        {
          "key": "rows",
          "label_th": "รายการสัญญาเพิ่มเติม",
          "label_en": "Rider list",
          "type": "array_of_objects",
          "min_rows": 0,
          "max_rows": 5,
          "fields": [
            { "key": "name",     "label_th": "ชื่อสัญญาเพิ่มเติม", "label_en": "Rider name",  "type": "string", "required": true },
            { "key": "premium",  "label_th": "เบี้ยประกัน (บาท)",  "label_en": "Premium",     "type": "number", "validation": { "min": 0 } },
            { "key": "rate_inh", "label_th": "ค่าคอมมิชชั่น Inh (%)", "label_en": "Comm rate (Inh)", "type": "number", "validation": { "min": 0, "max": 100 } },
            { "key": "rate_ag",  "label_th": "ค่าคอมมิชชั่น Agent (%)", "label_en": "Comm rate (Agent)", "type": "number", "validation": { "min": 0, "max": 100 } }
          ]
        }
      ]
    }
  ]
}
```

### 2.6 misc

Covers MARINE, PA_INDIVIDUAL, MISC.

**Decision: seed a non-null empty schema, not NULL.**

```json
{ "version": 1, "kind": "misc", "sections": [] }
```

Reasons:
1. Renderer contract is simpler if `risk_schema` is always non-null when
   `product_types.kind` is set. NULL forces every caller to test.
2. Empty `sections: []` renders a "ไม่มีข้อมูลเพิ่มเติมสำหรับผลิตภัณฑ์นี้"
   placeholder, giving the user a clear "nothing to fill in here"
   signal instead of a suspicious blank card.
3. Future misc products (e.g., cyber, D&O) can override by publishing
   their own schema per product_type — the misc catch-all doesn't
   force everyone into the empty shape.

`policies.risk_data` for misc rows stays NULL.

## 3. Renderer specification

### Data flow

1. Wizard Step 3 receives `product` from Step 2's picker. The wizard
   fetches the full product via `GET /products/{id}` (already exists);
   B2 §1 extends `ProductResource` to include
   `productType.{kind, riskSchema}` in the same payload — no extra
   round-trip.
2. `product.productType.riskSchema` is passed to the renderer.
3. Renderer emits `risk_data` on every field change, upward to the
   wizard's `form.riskData` (a plain object shaped exactly like
   `policies.risk_data`).

### Component contract

```
<RiskFieldRenderer
  :schema="product.productType.riskSchema"
  v-model="form.riskData"
  :customer-id="form.customerId"
  :submit-attempted="submitAttempted"
  @update:top-level="handleTopLevel"
/>
```

- `schema` — the full JSON blob.
- `v-model` — the risk_data blob (only `storage=risk_data` fields land here).
- `customer-id` — needed for the prior-asset autofill dropdown (§5).
- `submit-attempted` — the wizard flips this to `true` when the user
  clicks "Submit to carrier". Only then does the renderer surface
  `required` errors inline.
- `@update:top-level` — for `storage=column` fields, the renderer emits
  `{ payloadKey: value }` pairs. The wizard merges these directly into
  the top-level payload. Example: `{ motorLicenseNo: 'กข1234' }`.

### Widget map (`type` → Vue component)

| `type` | Widget |
|---|---|
| `string` | `<input type="text">` with debounce 200ms |
| `text` | `<textarea>` |
| `number` | `<input type="number">` with numeric coercion |
| `date` | Existing `<DateInput>` (dd/mm/yyyy, ISO out) |
| `select` | `<select>` with option list |
| `boolean` | Checkbox |
| `passport` | `<input>` + uppercase-normalized, regex `^[A-Z0-9]{6,12}$` |
| `phone` | `<input>` + phone regex (reuse `CustomerRequest` regex) |
| `array_of_objects` | `<MultiRow>` component; add/remove rows; internal field renderer per row |

### Section layout

- Each `section` is a Tailwind card with the section label as H3.
- Fields render in schema order, 2-col grid on md+ breakpoint.
- `type: text` and `type: array_of_objects` span both columns.

## 4. Validation renderer

### Runtime checks — schema `validation` → live errors

| Schema key | Runtime check | Error type |
|---|---|---|
| `min` / `max` (number) | Numeric range | inline red text under input |
| `min` / `max` (string) | String length | inline |
| `pattern` | RegExp.test | inline |
| `validator` | Named callable from a frontend registry | inline |
| `required` | Value non-empty | inline **only after `submitAttempted=true`** |
| `depends_on` | Cross-field: `end >= start`, `birth_date < today`, etc. | inline on the dependent field |
| `row_validation.sum_of` | Sum of a per-row field equals target | inline at array level |

### Named validator registry

Custom validators keyed by string. Frontend maps names to functions.
Initial registry:

| Name | Purpose | Reuse from |
|---|---|---|
| `thai_id_checksum` | 13-digit MOD-11 | `backend/app/Support/ThaiIdentifier.php` mirror in TS |
| `thai_license_plate` | Thai plate format | new |
| `thai_phone` | 10-digit mobile / landline | already in `CustomerCreateModal.vue:200-213` |
| `intl_phone` | +country digits | already in the same file |

Adding a validator = registering in the map + shipping the function.
Schema editor autocompletes the enum list.

### Validation gating (wizard integration)

- Draft: ignore all validation. Save whatever's in the form.
- Quotation: enforce `pattern`, `min`, `max`, `depends_on`. Do NOT
  enforce `required`.
- Submit: enforce all rules including `required` and
  `row_validation`. Block the button when errors exist.

## 5. Prior-asset autofill contract

Requirement: a customer's 3rd motor policy shouldn't re-type the same
plate + chassis + engine.

### Schema declares candidate fields

- Field-level `prior_autofill: true` marks the field as a candidate to
  hydrate from a prior policy's `risk_data`.
- Section-level `dedupe_keys: ["license_no", "chassis_no"]` names the
  keys that uniquely identify a physical asset. The backend uses these
  to deduplicate the "reuse" list — 3 renewals of the same car should
  show up as 1 row in the dropdown, not 3.

### Backend endpoint

```
GET /customers/{customerId}/prior-assets?kind=motor
  → 200 [
      {
        "label": "TOYOTA HILUX / ฆง 8000 (2 policies)",
        "policy_count": 2,
        "last_effective": "2025-12-15",
        "values": {
          "license_no":     "ฆง 8000",
          "vehicle_brand":  "TOYOTA",
          "vehicle_model":  "HILUX",
          "register_year":  "2020",
          "chassis_no":     "MR053ABC123",
          "engine_no":      "2GD-FTV-99999"
        }
      }
    ]
```

Backend logic:
1. Load the `risk_schema` for `product_types.kind = kind`.
2. Read `section.dedupe_keys` — the tuple of columns to group by.
3. For each of this customer's non-Draft policies matching the kind:
   assemble `{ dedupe_key → { values, count, most_recent_effective } }`
   from `policies.risk_data.<section>.*` + top-level columns via the
   B2 shim.
4. Return sorted by `most_recent_effective DESC`.

### Renderer UX

- Above the section: `<PriorAssetPicker :endpoint :section-key>` dropdown.
- Pick → renderer fills every `prior_autofill: true` field in that
  section with the corresponding value. Fields the user has already
  edited (touched-flag) are not overwritten without confirmation.

## 6. i18n label strategy

**Decision: labels inline in schema (approach (a)).** Rejected the
i18n-namespace approach (b).

Reasons:
1. Schema is one document per product_type; translations live there
   too. Admin edits schema and both languages in one place — no
   cross-file coordination.
2. There is no dedupe win. Each kind has its own field labels;
   `motor.vehicle_brand` and `health.name` don't share strings across
   kinds. A shared `policyCreate.risk.commonName` key would need to be
   overridden per kind anyway.
3. Admin-authored schemas (once new product types are added) shouldn't
   require a frontend code change to translate. Inline labels ship
   with the schema and require no rebuild.
4. The renderer already gets `label_th` / `label_en` for free from the
   schema payload — no translation lookup needed.

Exception: enum-agnostic labels (footer buttons, "Add row", "Delete",
"No additional details") stay in `policyCreate.risk.*` namespace since
they don't depend on the schema.

## 7. Multi-row field example (beneficiaries)

Already inlined in §2.5 (life kind). Repeating standalone for clarity:

```json
{
  "key": "rows",
  "label_th": "รายชื่อผู้รับผลประโยชน์",
  "label_en": "Beneficiary list",
  "type": "array_of_objects",
  "min_rows": 0,
  "max_rows": 4,
  "row_validation": { "sum_of": "share", "equals": 100, "empty_ok": true },
  "fields": [
    { "key": "name",     "label_th": "ชื่อ-นามสกุล", "label_en": "Full name", "type": "string", "required": true },
    { "key": "relation", "label_th": "ความสัมพันธ์", "label_en": "Relation",  "type": "string", "validation": { "max": 64 } },
    { "key": "share",    "label_th": "สัดส่วน (%)",  "label_en": "Share (%)", "type": "number", "required": true, "validation": { "min": 0, "max": 100 } }
  ]
}
```

### Justification of `row_validation.sum_of`

Currently hardcoded at `PolicyCreateWizard.vue:619-622`
(life-beneficiary sum=100 rule) and duplicated as
`policyEdit.beneficiaries.overLimit` in i18n. Moving it into the
schema:

- Makes the rule product-type-driven (some carriers may allow sum<100
  with the residual paid to the insured's estate — schema can declare
  `equals: 100` or `lte: 100` per product type).
- Removes the special-case Vue code (`03-wizard-current.md` calls out
  L619-622 as one of two hardcoded validation gates in Step 3).
- The renderer's `<MultiRow>` component consumes `row_validation`
  generically; other schemas can define their own row sums (e.g.
  fire coverage components must sum to `coverage`).

`empty_ok: true` — allows 0 rows without triggering the sum rule (the
schema requires beneficiaries only when the operator adds them).

## 8. Schema authoring UX

**Decision: raw JSON editor for MVP (approach (a)).** Deferred (b) form-based generator.

Reasons:
1. 26 product types, one-time authoring per kind (all motor products
   share the schema via product_type.id).
2. Form-based generators are significant frontend work (~500+ LOC
   Vue) and require domain-model iteration ("how do we render a
   `depends_on` picker?"). Not worth it for 26 rows.
3. Raw JSON edited by a technical admin gives full expressiveness now.
   Ship (b) once the initial 6 schemas are stable and non-technical
   admins need to add product types (probably never).

### Component sketch (spec only — no code)

- `AdminProductTypes.vue` gets a new column: "Risk schema" with
  `<button>Edit schema…</button>` per row.
- Click → opens `<RiskSchemaEditorModal>`:
  - Header: product_type code + name + current kind.
  - Body: `<textarea>` prefilled with the current `risk_schema` JSON,
    2-space pretty-printed, `spellcheck=false`, monospace font,
    resizable, ~600px tall.
  - Live JSON parse: green "Valid" badge / red "Line X: unexpected
    character" error. No submit until valid.
  - Live schema validation: `Ajv` (or hand-rolled) validates against a
    meta-schema (§1 contract). Red field-level errors listed under
    the textarea.
  - "Preview" tab: renders `<RiskFieldRenderer>` against a mock
    `v-model` so the admin sees what the operator sees.
  - Save → `PATCH /admin/product-types/{id}` with `{ riskSchema: JSON }`.
- Meta-schema shipped with the frontend so validation is client-side.
  No round-trip.
- If a kind's schema is currently NULL, a "Load default" button pulls
  the seeded template from §2 as a starting point.

### Guardrails

- Meta-schema enforces:
  - `version` present and numeric
  - `kind` matches `product_types.kind`
  - Every field has `key`, `label_th`, `label_en`, `type`
  - `key` follows `[a-z_][a-z0-9_]*`
  - No duplicate `key`s within the same section
  - `storage=column` requires `column_name`
  - `type=select` requires `options`
- Save blocks on any meta-schema error.
- Warn (but allow save) when: renaming a `key` that already has
  `risk_data` values on live policies (data-loss risk).

## 9. Migration + seed

Depends on B2 Migration 1 (`add_kind_and_risk_schema_to_product_types`).

### Seeder plan

Add `ProductTypeRiskSchemaSeeder` (idempotent, runs after
`ProductTypeSeeder`):

```
foreach product_type where risk_schema IS NULL:
    match by kind (from B2 backfill of 26 → 6 mapping):
      motor  → seed §2.1
      travel → seed §2.2
      fire   → seed §2.3
      health → seed §2.4
      life   → seed §2.5
      misc   → seed §2.6
```

Seeder is safe to re-run: only touches rows where `risk_schema IS
NULL`. `--force` flag overwrites (destructive; only for schema
version bumps).

Seeder lives in `backend/database/seeders/ProductTypeRiskSchemaSeeder.php`;
the six schema JSONs live as separate files under
`backend/database/seed-data/risk-schemas/{motor,travel,fire,health,life,misc}.json`
so they're diff-friendly in git.

### Registration

`DatabaseSeeder::run()` calls `ProductTypeRiskSchemaSeeder` after
`ProductTypeSeeder`. On fresh install, both run in order. On existing
install (staging, prod), the seeder is idempotent and can be invoked
via `php artisan db:seed --class=ProductTypeRiskSchemaSeeder`.

## 10. Open questions for user

1. **`row_validation` on non-life kinds** — fire's `building_cov +
   furniture_cov + stock_cov + other_cov` sometimes must equal the
   policy's `coverage` field. Do we enforce this in the schema (via a
   new `cross_section_validation` construct) or leave to Step 4
   Premium validation? Impacts B4 scope: adding cross-section rules
   is another 30 lines of contract.

2. **`prior_autofill` scope** — should it be per-field, per-section,
   or per-schema? Currently spec'd per-field. Alternative: field
   inherits `section.prior_autofill: true` and can opt out. Simpler
   schemas but less explicit.

3. **Life riders sourcing** — the current wizard renders 5 fixed rider
   slots. Should the new schema list common rider names as `options`
   (WP, AI, CI, etc.) or leave free-text as in §2.5? Free-text
   matches current behavior; enum would enable per-carrier rider
   catalogs later.

4. **Schema version enforcement** — should the backend refuse to save
   a schema whose `version` isn't in the frontend's supported ceiling?
   Alternative: allow save + surface a warning in the admin UI. Backend
   refusal is stricter but requires shipping the ceiling to the API.

5. **Misc kind product-type override** — should MARINE be allowed to
   publish its own schema (say, cargo details) while other misc
   products stay empty? Currently spec'd as "yes, per product_type
   row" — confirms the design isn't kind-locked. Impacts admin UX:
   they can freely mix.
