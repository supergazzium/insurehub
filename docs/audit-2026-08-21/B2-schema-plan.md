# B2 — Schema migration plan

Design doc for the `product_types.kind` + `product_types.risk_schema` +
`policies.risk_data` migration, the shim window, and the drop of ~30
retired risk columns on `policies`.

Ground truth: `02-policy-schema.md`, `04-product-schema.md`, `00-summary.md`.

Zero-data-loss constraint applies to 474 live policies + 515 seed rows.

## 1. Migration 1 — additive fields on `product_types`

**Filename**: `backend/database/migrations/2027_02_14_000100_add_kind_and_risk_schema_to_product_types.php`

**Schema change** (Blueprint pseudo-code):

```
Schema::table('product_types', function (Blueprint $t): void {
    // 'motor' | 'travel' | 'fire' | 'health' | 'life' | 'misc'
    $t->string('kind', 16)->nullable()->after('sub_of');
    // JSON schema: { fields: [{ key, label_th, label_en, type, required, ... }] }
    $t->json('risk_schema')->nullable()->after('kind');
});
```

Both columns nullable → additive, non-breaking. `sub_of` position preserved.

**Backfill in same migration** (`up()` after schema):

Loop the 26-row kind mapping from `04-product-schema.md §5` — a static
associative array in the migration itself so re-running via `migrate:fresh`
on any tenant lands the same values. `risk_schema` stays `NULL` here — the
schema authoring is a separate later PR (see rollout §9 step 4).

```
$kindByCode = [
    'MOTOR_CLASS1_GARAGE' => 'motor', 'MOTOR_CLASS1_DEALER' => 'motor',
    'MOTOR_CLASS23' => 'motor', 'MOTOR_HEAVY_GARAGE' => 'motor',
    'MOTOR_HEAVY_DEALER' => 'motor', 'MOTOR_HEAVY_CLASS23' => 'motor',
    'PORROR_CAR' => 'motor', 'PORROR_OTHER' => 'motor',
    'TA_INDIVIDUAL' => 'travel',
    'FIRE_HOUSE_BASIC' => 'fire', 'FIRE_SME_BASIC' => 'fire',
    'FIRE_HOUSE_PACKAGE' => 'fire', 'FIRE_SME_PACKAGE' => 'fire',
    'IAR_CAR_EAR' => 'fire',
    'MARINE' => 'misc', 'PA_INDIVIDUAL' => 'misc', 'MISC' => 'misc',
    'HEALTH_ADULT' => 'health', 'HEALTH_CHILD' => 'health',
    'GROUP_ACCIDENT' => 'health', 'GROUP_HEALTH' => 'health',
    'WHOLE_LIFE_STANDARD' => 'life', 'ENDOWMENT_STANDARD' => 'life',
    'ANNUITY' => 'life', 'TERM' => 'life', 'LIFE_RIDER' => 'life',
];
foreach ($kindByCode as $code => $kind) {
    DB::table('product_types')->where('code', $code)->update(['kind' => $kind]);
}
```

**Model cast** (`app/Models/ProductType.php`):

```
protected $casts = [
    'sort_order' => 'int',
    'active' => 'bool',
    'risk_schema' => 'array',   // new
];
```

**Resource additions** (`app/Http/Resources/ProductTypeResource.php`):

```
'kind' => $this->kind,
'riskSchema' => $this->risk_schema,
```

**Optional passthrough on `ProductResource`** — recommended so the wizard
hydrates in one fetch:

```
'productType' => $this->productType ? [
    'id' => (string) $this->productType->id,
    'code' => $this->productType->code,
    'kind' => $this->productType->kind,
    'riskSchema' => $this->productType->risk_schema,
] : null,
```

**Request additions** (`app/Http/Requests/ProductTypeRequest.php`):

```
'kind' => ['sometimes', 'nullable', 'string', 'in:motor,travel,fire,health,life,misc'],
'riskSchema' => ['sometimes', 'nullable', 'array'],
```

**Rollback**: `down()` drops both columns. No data outside these columns
is touched. Safe to re-run `migrate:refresh`.

## 2. Migration 2 — additive `risk_data` JSON on `policies`

**Filename**: `backend/database/migrations/2027_02_14_000200_add_risk_data_to_policies.php`

**Schema change**:

```
Schema::table('policies', function (Blueprint $t): void {
    $t->json('risk_data')->nullable()->after('motor_notes');
});
```

No backfill in this migration. The column ships empty; the reader/writer
shim (§3) starts populating it on the next write; the backfill command
(§4) fills historical rows.

**Model cast** (`app/Models/Policy.php` — add to existing `$casts`):

```
'risk_data' => 'array',
```

**Rollback**: `down()` drops the column. Anything written into
`risk_data` during the shim is lost, but every retired risk column still
carries the same value in parallel, so no user-visible data loss.

## 3. Reader/writer shim strategy

The shim is the safety layer that lets us ship the JSON column, prove it
under production load, and only then drop the retired top-level columns.

### Writer shim — dual-write

`PolicyRequest::toModel()` writes to BOTH places for every retired risk
column:

```
// Given kind = 'motor' and input has motorEngineNo = 'ABC123'
$out['motor_engine_no'] = 'ABC123';         // legacy top-level column
$out['risk_data']['motor']['engine_no'] = 'ABC123';  // new home
```

Every write that touches a shimmed field lands in both. `risk_data` is
merged, not overwritten, so unrelated kinds' data survives.

### Reader shim — prefer JSON, fall back to column, log the fallback

`PolicyResource` (and every internal consumer that touches these fields)
reads via a helper:

```
private function riskField(string $kind, string $key, ?string $legacyCol): mixed
{
    $val = data_get($this->risk_data, "{$kind}.{$key}");
    if ($val !== null) return $val;
    if ($legacyCol !== null && $this->{$legacyCol} !== null) {
        Log::channel('risk_shim')->info('risk_data fallback', [
            'policy_id' => $this->id, 'kind' => $kind, 'key' => $key,
        ]);
        return $this->{$legacyCol};
    }
    return null;
}
```

Fallback log → dedicated channel → daily counter. The drop-column
migration is gated on this counter reaching zero for N consecutive days.

### Duration of shim

**Minimum 2 minor releases** live in production before the drop is
scheduled. If backfill runs on release N, drop can be scheduled for
release N+2 at earliest. In wall-clock: at least 2-4 weeks of production
soak, subject to §5 gate.

### Fields shimmed (moved into `risk_data`)

From `02-policy-schema.md`. All existing top-level columns on `policies`
are kept during the shim; the reader prefers `risk_data`.

- **motor** (`risk_data.motor.*`): `motor_type_driver`, `motor_type_vehicle`,
  `motor_engine_no`, `motor_chassis_no`, `motor_register_year`,
  `motor_no_passenger`, `motor_notes` (7 fields)
- **property/fire** (`risk_data.property.*`): all 9 `property_*` columns —
  `insured_name`, `insured_address`, `phone`, `building_cov`,
  `furniture_cov`, `stock_cov`, `other_cov`, `other_detail`, `notes`
- **travel** (`risk_data.travel.*`): all 5 — `destination`, `start`, `end`,
  `traveler_count`, `traveler_passport`
- **life/health** (`risk_data.life.*` / `risk_data.health.*`): all 8 —
  `insured_person_name`, `insured_person_id_card`,
  `insured_person_birth_date`, `sum_assured`, `premium_paying_term`,
  `health_declaration`, `health_beneficiary_name`,
  `health_beneficiary_relation`

Total shimmed columns: **29**.

### Fields that stay top-level forever

Query hotspots — either drive list-column sort/search or index:

- `motor_license_no` — list column + search filter (`api/policies.ts:41`)
- `motor_vehicle_brand` — list column
- `motor_vehicle_model` — list column

These are motor-only by content but stay as columns because moving them
into JSON forces `WHERE JSON_EXTRACT(risk_data,'$.motor.license_no') LIKE ?`
on the search path — order-of-magnitude worse than `WHERE motor_license_no LIKE ?`
with a B-tree index.

The three columns become nullable-on-purpose for non-motor rows. No
change from today.

## 4. Backfill — Artisan command, not migration

**Recommendation**: **Artisan command**, not migration.

Trade-off:

| Approach | Pros | Cons |
|---|---|---|
| Migration | Auto-runs in deploy, one-shot | Hard to retry, hard to dry-run per env, mixes schema with data |
| Command | Idempotent, dry-run, per-env control, small blast radius | Manual invocation step |

515 rows is trivial — the manual step is worth the safety. A migration
that fails halfway leaves both `migrations` table and data in a bad state.

**Command**: `php artisan policies:backfill-risk-data`

**Flags**:
- `--dry-run` (default): prints per-policy diff, writes nothing
- `--force`: overwrites rows where `risk_data IS NOT NULL` (only for
  reruns after schema fix — normally skipped)
- `--tenant=<id>`: restrict scope; defaults to all
- `--chunk=<n>`: default 100

**Algorithm**:

```
for each policy P chunked(100):
    kind = P.product?.productType?.kind ?? ProductKind::derive(...)
    if kind === null: warn + skip
    if P.risk_data !== null and not --force: skip (idempotent)
    shape = build($this, $kind)   // read top-level cols → shape by kind
    if --dry-run: print diff; continue
    P.update(['risk_data' => $shape])
```

`build()` skips NULL values so we don't write `{"motor": {"engine_no": null, ...}}`
for an empty motor block. Result on a fire-only policy: `{"property": { ... }}`.

**Idempotency**: identical input → identical output. Rerunning without
`--force` is a no-op on already-backfilled rows.

**Dry-run mandatory** in prod. Standard operating procedure:

1. `--dry-run` on staging, review diff for 5-10 sample rows
2. Live run on staging
3. Smoke-test wizard + drawer + list against backfilled staging DB
4. `--dry-run` on prod
5. Live run on prod

**Rollback**: `UPDATE policies SET risk_data = NULL` — the shim reads
from top-level columns and the app continues working unchanged.

## 5. Drop-column migration (gated, scheduled)

**Filename**: `backend/database/migrations/2027_03_01_000100_drop_retired_risk_columns_from_policies.php`

**Gate**: the migration's `up()` short-circuits unless `POLICY_RISK_SHIM_COMPLETE=true` in env:

```
if (env('POLICY_RISK_SHIM_COMPLETE') !== true) {
    Log::warning('drop-migration skipped: POLICY_RISK_SHIM_COMPLETE not set');
    return;
}
```

This lets the migration merge without accidentally firing on any env
that hasn't cleared the soak. The flag is flipped by the ops operator
after: (a) fallback log counter has been zero for ≥14 days, (b) a
manual grep of internal consumers confirms nothing outside the shim
helper reads the retired columns.

**Columns dropped** (29 total — matches §3 shim list):

```
motor: motor_type_driver, motor_type_vehicle,
       motor_engine_no, motor_chassis_no,
       motor_register_year, motor_no_passenger, motor_notes
property: property_insured_name, property_insured_address, property_phone,
          property_building_cov, property_furniture_cov, property_stock_cov,
          property_other_cov, property_other_detail, property_notes
travel: trip_destination, trip_start, trip_end,
        traveler_count, traveler_passport
life/health: insured_person_name, insured_person_id_card,
             insured_person_birth_date, sum_assured, premium_paying_term,
             health_declaration, health_beneficiary_name,
             health_beneficiary_relation
```

**Columns KEPT top-level** (3):

```
motor_license_no, motor_vehicle_brand, motor_vehicle_model
```

**Rollback complexity — hard**. Once dropped:

- Add-column `down()` restores the schema but not the data.
- Recovery = restore from the SQL export taken **immediately before** the
  drop (mandatory pre-flight — see §8).

**Recommendation**: attach the pre-flight raw dump of the 29 columns as
a Coolify artifact (or `mysqldump policies` filtered to those columns
via `--tables`) with a 90-day retention. Retention outlives the
plausible rollback window; if we don't need it in 90 days, we won't.

## 6. `PolicyResource` + `PolicyListResource` changes

### `PolicyResource` — normalize on `risk` sub-object

Emit ONE canonical shape, always:

```
'risk' => [
    'kind' => $productKind,   // 'motor' | 'travel' | ...
    'fields' => (object) $this->normalizedRiskFields(),
],
```

`normalizedRiskFields()` composes the sub-object from `risk_data` with
column fallback for each shimmed field. During the shim, output is
identical whether the read hit JSON or column; after the drop, the
column path just doesn't run.

**Retire the inconsistent emission** noted in `02-policy-schema.md §5`:

- Drop the `'motor' => [...]` block gated on `motor_vehicle_brand` (lines 137-158)
- Drop the `'property' => [...]` block gated on `property_insured_name`
- Drop the flat travel/life/health top-level keys

**Keep for the shim window**: emit both the new `risk` sub-object AND
the legacy blocks (with a deprecation comment), so frontend consumers
can migrate at their own pace. Delete the legacy blocks in the same
release as the drop migration.

### `PolicyListResource` — no change

The three surviving top-level columns (`motorLicenseNo`,
`motorVehicleBrand`, `motorVehicleModel`) stay in the list row for
search + display. Not adding `risk` sub-object to the list to keep it
lean — the drawer already fetches full detail.

## 7. Frontend `Policy` interface changes

Additive typing, mirroring the resource:

```
interface RiskBlock<K extends string = string, F = Record<string, unknown>> {
  kind: K
  fields: F
}
type PolicyRisk =
  | RiskBlock<'motor', MotorRiskFields>
  | RiskBlock<'travel', TravelRiskFields>
  | RiskBlock<'fire', FireRiskFields>
  | RiskBlock<'health', HealthRiskFields>
  | RiskBlock<'life', LifeRiskFields>
  | RiskBlock<'misc', Record<string, never>>

interface Policy {
  // ...existing fields...
  risk: PolicyRisk | null   // new
  // motor + property remain as legacy typing during the shim
  motor?: MotorDetails | null
  property?: PropertyDetails | null
}
```

Keep `MotorDetails` / `PropertyDetails` as fallback typing until the
shim ends; delete in the same release as the resource cleanup.

**API surface**: no new endpoint. Same `GET /policies/{id}`, larger
response payload during the shim (~one extra sub-object per policy).

## 8. Testing plan

1. **Fresh migrate + seed** on a scratch DB — `migrate:fresh` then run
   the CustomerSeeder + PolicySeeder + this migration set → assert 515
   `policies` rows survive intact, no NULL-not-nullable errors.
2. **Rollback loop** — up/down/up/down on all three new migrations
   against the seeded DB. Assert `policies` and `product_types` row
   counts unchanged.
3. **Backfill dry-run** — capture diff for 10 sample rows across kinds
   (motor/fire/travel/life/health/misc). Verify field values match.
4. **Backfill live-run** — apply, then round-trip: for each sample,
   compare `PolicyResource` output before (from a saved dump) vs after
   backfill. Byte-for-byte identical output = pass.
5. **Shim regression** — a PHPUnit feature test: `POST /policies` with a
   motor payload, `GET /policies/{id}`, assert every motor field
   round-trips regardless of whether `risk_data` was set or fell back.
6. **Fallback log audit** — after 2 weeks of production, `wc -l` on the
   `risk_shim.log` daily buckets → expect zero. Only then flip
   `POLICY_RISK_SHIM_COMPLETE=true`.
7. **Pre-drop dump** — `mysqldump` filtered to the 29 columns, stored
   as a build artifact.
8. **Drop migration** — run on staging first with the flag set; verify
   `SHOW COLUMNS FROM policies` matches expectations; run in prod.
9. **Post-drop regression** — repeat step 5. Reads that previously fell
   back must now find the value in `risk_data`.

## 9. Rollout sequence (per PR)

1. **PR-A**: Migration 1 (`product_types.kind` + `risk_schema`), ProductType model cast, ProductTypeResource + optional ProductResource passthrough, ProductTypeRequest rules. Admin gets a `kind` select in `AdminProductTypes.vue`. **No wizard change.** Ships kind data to production.
2. **PR-B**: Migration 2 (`policies.risk_data`), Policy model cast, writer shim in `PolicyRequest::toModel()`, reader shim helper on `PolicyResource`, `risk` sub-object emitted alongside legacy blocks, fallback logging channel. Frontend `Policy.risk` typing added. **No visible UX change.**
3. **PR-C**: `php artisan policies:backfill-risk-data --dry-run`, review, then live run — first on staging (post PR-B deploy), then on prod. Manual step, not a PR.
4. **PR-D**: `AdminProductTypes.vue` gains the "Edit risk schema…" JSON editor button. Populates `risk_schema` for the 26 product_types.
5. **PR-E**: New 5-step wizard (B3 spec) reads `product.productType.riskSchema` for the dynamic step-3 renderer. Writes go through `PolicyRequest` unchanged (shim in place).
6. **Soak period** — 2-4 weeks. Ops watches `risk_shim.log`. Weekly check-in on fallback count.
7. **PR-F**: Set `POLICY_RISK_SHIM_COMPLETE=true` in staging env; deploy Migration 3 (drop columns). Run PR-B regression suite. If green, flip prod env flag and deploy.
8. **PR-G**: Delete legacy `motor`/`property` blocks from `PolicyResource`, delete fallback typing (`MotorDetails` etc), delete `riskField()` helper's column-fallback branch (JSON-only reads). This is the cleanup PR — safe once PR-F has been live for a week.

## 10. Open questions

1. **Does `sum_assured` belong to `life`, `health`, or both?** Some carriers
   record sum-assured on health products too. The current schema treats
   it as a single top-level column shared by both. Proposal: emit under
   both `risk_data.life.sum_assured` and `risk_data.health.sum_assured`
   at write time based on `kind`; reader picks by kind. Confirm.
2. **`vehicle_on_non_motor` bool** — this drift-detection flag becomes
   less useful when we adopt `risk_data`. Keep as a soft-check the
   backfill sets when it sees data misfiled? Or drop with the other
   motor columns?
3. **`policy_events.payload` JSON already exists** — should events log
   risk_data mutations for auditability? Not blocking; can defer.
4. **`ProductKind::derive()` fallback** — after `product_types.kind` is
   populated for all 26 rows, is the derivation helper still needed?
   Proposal: keep as a last-resort fallback for tenants where
   product_types isn't seeded, but log a warning when used.
5. **Retention for the pre-drop dump** — 90 days proposed. Longer if
   ops policy requires it. Confirm.
