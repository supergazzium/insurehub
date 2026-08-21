# Policy state enum — audit (2026-08-21)

## 1. Migrations

| File | Column op | Definition |
|---|---|---|
| `backend/database/migrations/2026_06_30_000500_create_policies.php:79` | CREATE | `$table->string('status', 32)->default('quote')` |
| `backend/database/migrations/2026_06_30_000500_create_policies.php:80` | CREATE | `legacy_policy_status_id` FK → `policy_statuses.id`, nullable |
| `backend/database/migrations/2026_06_30_000500_create_policies.php:81` | CREATE | `status_note` text nullable |
| `backend/database/migrations/2026_06_30_000500_create_policies.php:160` | CREATE | index `(tenant_id, status)` |
| `backend/database/migrations/2027_01_01_000600_seed_policy_status_translation.php` | ADD (data + tables) | Creates `policy_status_translations` (maps legacy Thai label → `policy_statuses.id`), seeds `policy_statuses` with 9 codes. Does NOT alter the `policies.status` column definition. |

No later migration changes the `policies.status` column shape. It remains `string(32)` default `'quote'`.

### Seeded rows in `policy_statuses` (2027_01_01_000600:44-54)

| code | name_th | group_name_th |
|---|---|---|
| quote | ใบเสนอราคา | Draft |
| application | รอตรวจรถ | Pending |
| submitted | รอพิจารณา | Pending |
| issued | ออกกรมธรรม์แล้ว | Issued |
| active | อนุมัติแล้ว | Approved |
| lapsed | ขาดต่ออายุ | Lapsed |
| cancelled | Cancel | Cancelled |
| reinstated | กลับมาคุ้มครองใหม่ | Reinstated |
| expired | หมดอายุ | Expired |

Legacy → code translation seeded at 2027_01_01_000600:71-78 (`อนุมัติแล้ว→active`, `Cancel→cancelled`, `Reject→cancelled`, `รอพิจารณา→submitted`, `รอตรวจรถ→application`).

## 2. Model

`backend/app/Models/Policy.php`

- **No `$casts`** for `status` (Line 20 casts block covers dates + booleans only).
- **No constants, enum accessors, or scopes** tied to `status`.
- Only status-adjacent relation is `legacyStatus()` at line 103: `belongsTo(PolicyStatusLookup::class, 'legacy_policy_status_id')`.

## 3. FormRequest validation

`backend/app/Http/Requests/PolicyRequest.php:72`

```
'status' => ['sometimes', 'string', 'in:quote,application,submitted,issued,active,lapsed,cancelled,reinstated,expired'],
```

Field map at line 185: `'status' => 'status'`. No sibling `PolicyStoreRequest` / `PolicyUpdateRequest` — one request class serves both verbs.

## 4. Resources

`backend/app/Http/Resources/PolicyResource.php`:
- L46: `'status' => $this->status`
- L48: `'statusLabel' => $this->legacyStatus?->name_th ?? $this->status`
- L49: `'statusGroup' => $this->legacyStatus?->group_name_th`
- L50: `'statusNote' => $this->status_note ?? ''`

`backend/app/Http/Resources/PolicyListResource.php`:
- L35: `'status' => $this->status`
- L39: `'statusLabel' => $this->status_label ?? $this->status` (fed by joined `policy_statuses.name_th` — see `PolicyController::index` L39,63)
- L40: `'statusGroup' => $this->status_group` (joined `policy_statuses.group_name_th`)

## 5. Seeder / Factory

`backend/database/seeders/PolicySeeder.php`

- Reads `backend/database/seed-data/application.csv` (711 rows including header).
- Line 40-47: `STATUS_MAP` collapses raw legacy values to canonical codes:
  - `อนุมัติแล้ว → active`
  - `รอพิจารณา → submitted`
  - `รอตรวจรถ → submitted` **(NOTE: `รอตรวจรถ` is coerced to `submitted`, not `application` — divergent from the translation table at 2027_01_01_000600:77 which maps it to `application`).**
  - `Cancel → cancelled`
  - `Reject → cancelled`
  - `True → active`
- L124: default when key not found = `'submitted'`.
- No policy factory ships in `backend/database/factories/` (grep for `PolicyFactory` returns none — verified: no factory used in tests).

## 6. Frontend enum surfaces

### PolicyStatus union type — single source of truth
`frontend/src/stores/policies.ts:24-33` — `quote | application | submitted | issued | active | lapsed | cancelled | reinstated | expired`.

### Hard-coded status arrays

`frontend/src/pages/policies/PolicyListV2.vue:361-366` (filter dropdown):
```
active → 'อนุมัติแล้ว'
submitted → 'รอพิจารณา'
application → 'รอตรวจรถ'
quote → 'ใบเสนอราคา'
issued → 'ออกกรมธรรม์แล้ว'
```

`frontend/src/pages/policies/PolicyDetailDrawer.vue:15-19` (inline-edit `EditableField` options) — same 5 pairs.

`frontend/src/pages/policies/PolicyEdit.vue:445-448` (badge color map):
- `quote` → slate
- `application` → brand
- `issued|active` → emerald
- `lapsed|submitted` → amber
- L108: "Fields locked once status ≥ issued" comment mirrors server `LOCK_TRIGGER_STATUSES`.

### Wizard defaults

`frontend/src/pages/policies/PolicyCreateWizard.vue`
- L249: `status: 'submitted' as 'quote' | 'application' | 'submitted' | 'issued' | 'active'` (narrower union — omits terminal states).
- L646-652: post-kind pick → `k === 'motor' ? 'application' : 'submitted'`.
- L869: reset default `status: 'submitted'`.

### Store transition guards

`frontend/src/stores/policies.ts`
- L485: `status: 'quote'` on client-side create.
- L558: `convertToApplication` guards `current.status !== 'quote'`.
- L566: `submitToCarrier` guards `!== 'application'`.
- L576: `issuePolicy` guards `!== 'submitted' && !== 'application'`.
- L606: `renewPolicy` guards `!== 'active' && !== 'issued'`.
- L622: `lapsePolicy` guards `!== 'active'`.

## 7. i18n keys

### `frontend/src/i18n/th.ts` — `policies.status.*` block (L980-1001)

```
policies.status.quote        = 'ใบเสนอราคา'
policies.status.application  = 'ใบสมัคร'          ← diverges from Thai in enum table (รอตรวจรถ)
policies.status.submitted    = 'ส่งบริษัทประกัน'   ← diverges (รอพิจารณา)
policies.status.issued       = 'ออกแล้ว'          ← diverges (ออกกรมธรรม์แล้ว)
policies.status.active       = 'ใช้งาน'           ← diverges (อนุมัติแล้ว)
policies.status.lapsed       = 'หลุด'
policies.status.cancelled    = 'ยกเลิก'
policies.status.reinstated   = 'นำกลับมาใช้'
policies.status.expired      = 'สิ้นสุด'
```

Sibling `policies.statusDesc.*` (L991) has one-line explanations per code, and `policies.events.*` (L1002) has event labels.

**Consumers**: `grep t('policies.` in `frontend/src/` returns zero hits. The `policies.status.*` block is defined but unused; badge/dropdown code hardcodes strings inline (see §6). This block is safe to repurpose.

`policyCreate:` block anchors at `frontend/src/i18n/th.ts:1515`; `policyEdit:` at `:1644`. Neither contains a `status` sub-object today.

### `frontend/src/i18n/en.ts`

- `policyCreate:` at L423, `policyEdit:` at L552.
- No `policies:` namespace and no status-code translations exist. Only status-adjacent en key is `Status` label at L547 inside `policyCreate.status`.
- **Gap**: an English `policies.status.*` block does not exist.

## 8. State transitions in backend

Central transition table: `backend/app/Http/Controllers/Api/PolicyEventController.php:26-35`:

```
convertedToApplication → status = application
submittedToCarrier     → status = submitted
issued                 → status = issued
renewed                → status = active
cancelled              → status = cancelled
lapsed                 → status = lapsed
reinstated             → status = active
detailsUpdated         → (no status change)
```

Applied at L58 (`$policy->update($updates)`) inside a `DB::transaction` also writing a `PolicyEvent` row (L60-66).

### Other status writes (grep `'status'` in `backend/app/`)

| File:line | Purpose |
|---|---|
| `Api/QuoteController.php:70` | Create quote → `status='quote'` |
| `Api/QuoteController.php:89,104` | Guard: only allow convert on `status === 'quote'` |
| `Api/QuoteController.php:109` | Convert quote → `status='application'` |
| `Api/PolicyController.php:103-104` | Read-only filter on `p.status` in list query |
| `Api/PolicyController.php:192` | `LOCK_TRIGGER_STATUSES = ['issued','active','lapsed','cancelled','reinstated','expired']` — used at L346-349 to reject edits to `LOCKED_AFTER_ISSUED` fields. |
| `Api/PolicyController.php:311` | `statusNote` validation on section-update endpoint. |
| `Api/MailController.php:61` | Unrelated (`mail_deliveries.status = cancelled`). |

The `PolicyController` itself has **no direct `status =` write** — status changes flow exclusively through `PolicyEventController` and `QuoteController`.

## 9. Live distribution

Source: `backend/database/seed-data/application.csv` column 133 (`Policy_Status`), 710 data rows:

| Raw value | Count | Maps to (via PolicySeeder::STATUS_MAP or default) |
|---|---|---|
| อนุมัติแล้ว | 450 | active |
| *(blank)* | 231 | submitted (default fallback) |
| Cancel | 11 | cancelled |
| รอพิจารณา | 10 | submitted |
| รอตรวจรถ | 3 | submitted (**not** application — PolicySeeder overrides the translation table) |
| True | 1 | active |
| Reject | 1 | cancelled |
| `2027-06-18 00:00:00` / `2027-03-16 00:00:00` / `2027-02-17 00:00:00` | 1 each | submitted (default — parser hit a mis-aligned CSV row) |

**Expected DB distribution after seed** (710 rows in — note user's audit says 474 policies live, so ~236 legacy rows are filtered elsewhere before insert):
- `active`: 451
- `submitted`: 245
- `cancelled`: 12
- Zero rows for `quote`, `application`, `issued`, `lapsed`, `reinstated`, `expired`.

**Migration-blocking observation**: the seeder never emits `application` or `issued`, and it flattens `รอตรวจรถ` (application) into `submitted`. Any new state machine must treat the DB distribution as effectively 3-valued (`active`, `submitted`, `cancelled`) even though 9 codes are declared.

### Scope notes / drift

- Frontend `i18n/th.ts` labels for `application`/`submitted`/`issued`/`active` **do not match** the `policy_statuses` table's Thai labels — two label systems (i18n vs DB `name_th`) render side-by-side and diverge.
- `PolicySeeder::STATUS_MAP` **contradicts** `policy_status_translations` for `รอตรวจรถ` (`submitted` vs `application`).
- `PolicyResource.statusLabel` (`legacyStatus?->name_th`) and `PolicyListResource.statusLabel` (joined `ps.name_th`) both prefer the DB label, so frontend badges see the DB Thai text, not the `i18n/th.ts` `policies.status.*` block. That i18n block is dead code, safe to replace during the state-machine refactor.
