# Quotes module audit

**TL;DR**: The `/quotes` module is NOT orphaned — it is a fully wired
front-end + back-end feature backed by the `policies` table (`status =
'quote'`). There is no separate `quotes` table. `convertQuoteToApplication`
flips `status` from `quote` → `application` in place. The "empty" state the
spec observed is a live-data condition (no rows), not a missing feature.

Recommendation: **Option A — treat "Quotation" as a policy state.** The
implementation already works that way; keeping the /quotes route just
means the wizard's "Draft → Quotation" gate lives at `POST /quotes`, and
the "Submitted" (application) gate is the existing `convert` endpoint.
No entity split needed.

## 1. Route & page

`frontend/src/pages/quotes/` — 3 files:

| File | LOC | Role |
|---|---|---|
| `QuoteList.vue` | 100 | List view at `/insurehub/quotes`, search + "New" button |
| `QuoteEdit.vue` | 287 | Create + edit form (motor tab with ACT tariff picker, non-motor tab) |
| `QuoteDetail.vue` | 105 | Read-only detail + "Convert to application" button |

Subtitle confirmed:
`frontend/src/i18n/th.ts:1319` → `subtitle: 'สร้าง / แก้ไข และแปลงใบเสนอราคาเป็นใบสมัคร'`.
Rendered by `QuoteList.vue:37` via `t('quotes.subtitle')`.

Routes registered in the router (paths grepped from `../router/*`):
- `quotes` → `QuoteList`
- `quotes/new` → `QuoteEdit` (name: `quote-new`)
- `quotes/:id/edit` → `QuoteEdit` (name: `quote-edit`)
- `quotes/:id` → `QuoteDetail` (name: `quote-detail`)

## 2. Backend model + migration

**No `quotes` table exists.** No migration under
`backend/database/migrations/` creates a `quotes` table, and no
`app/Models/Quote.php` exists.

Quotes live inside `policies`:
- `backend/database/migrations/2026_06_30_000500_create_policies.php:20`
  → `quote_no VARCHAR(32) nullable`
- `…:33` → `quote_date DATE nullable`
- `…:79` → `status VARCHAR(32) DEFAULT 'quote'`

Every quote is a `policies` row with `status = 'quote'`.

## 3. Backend controller + routes

`backend/app/Http/Controllers/Api/QuoteController.php` exists (263 lines).
Endpoints registered in `backend/routes/api.php:169-177`:

```
GET   /quotes                    index — Policy::where('status','quote')
POST  /quotes                    store — creates Policy with status=quote
GET   /quotes/act-tariffs        list MotorActTariff rows
POST  /quotes/premium/preview    stateless PremiumCalculator dispatch
GET   /quotes/{policy}           show
PATCH /quotes/{policy}           update (409 if status != quote)
POST  /quotes/{policy}/convert   flips status quote → application, assigns application_no
```

All operations use `Policy` and `PolicyResource`.

## 4. Store / API client

- No `frontend/src/stores/quotes.ts` (no Pinia store). Pages call the API
  client directly.
- `frontend/src/api/quotes.ts` (91 lines). Types: `Quote`, `ActTariff`,
  `PremiumMode`, `PremiumPreview`, `QuoteWritePayload`. Methods:
  `fetchQuoteList`, `fetchQuote`, `createQuote`, `updateQuote`,
  `convertQuoteToApplication`, `fetchActTariffs`, `previewPremium`.

## 5. Convert-to-application logic

Wired end-to-end.

- Backend: `QuoteController::convert()`
  (`backend/app/Http/Controllers/Api/QuoteController.php:101-115`).
  409 if not `status = 'quote'`. Sets `status = 'application'`, assigns
  `application_no` via `nextApplicationNo()` (format
  `A<YY><6-digit-serial>`, matches Access import pattern e.g.
  `A2507160006`), stamps `app_date = now()`. `quote_no` is retained for
  traceability.
- Frontend: `convertQuoteToApplication(id)` in
  `frontend/src/api/quotes.ts:75-77`, called from
  `QuoteDetail.vue:33-35` and redirects to `policy-edit` for the same id.

No `quote_id` FK anywhere — the record IS the same row.

## 6. i18n keys

`frontend/src/i18n/th.ts:1317-1378` — full `quotes.*` block: `title`,
`subtitle`, `new`, `newTitle`, `editTitle`, `newSubtitle`, `search`,
`refresh`, `empty`, `open`, `edit`, `save`, `create`, `convert`,
`convertConfirm`, `col.{quoteNo,date,status,premium}`,
`kind.{motor,nonMotor}`, `motor.{actTitle,actHint}`,
`field.{status,applicationNo,carrier,product,writingAgent,effectiveDate,expiryDate,newOrRenew,internalNote}`,
`premium.{title,recalc,netPremium,totalPaid,fixedDuty,net,duty,vat,total,mode.{iterativeVat,vatInclusive,fixedDuty,bare}}`,
`detail.title`.

Also sidebar labels at `th.ts:172` / `en.ts:220`: `quotes.name` /
`quotes.short`. English mirror at `en.ts:249`.

Usage: all keys are consumed by the 3 quote pages (grepped `t('quotes.` —
only quote files hit).

## 7. Relationship with policy

**A "Quotation" IS a policy in `status = 'quote'`** — not a separate
entity. Evidence:

- `QuoteController::store()` calls `Policy::create(['status' => 'quote', …])`
  (line 76).
- Every list/show/update operates on `Policy` rows filtered by status.
- Convert simply mutates `status`.
- The default value of `policies.status` in the migration is `'quote'`
  (line 79 of the create migration), so any bare `Policy::create()` is
  already a quote.

This matches the spec's proposed lifecycle: `Draft → Quotation →
Submitted → Approved → Issued → Active → Expired`. What already exists is
a compressed `Quotation → Application`. The wizard work will just plug
into this: `Draft` = new state before `quote_no` is minted; `Quotation`
= existing `status = 'quote'`; `Submitted` = existing `status =
'application'`; `Approved` / `Issued` / `Active` / `Expired` are new
states to add (see the state-enum audit).

## 8. Recommendation

**Option A — treat "Quotation" as a policy state.** Do NOT introduce a
`quotes` table or `Quote` model. Rationale:

- The current code already implements Option A. Option B would be a full
  entity split with data migration.
- All 474 seeded policies would need a "backfill quote_id" step under
  Option B.
- Convert to application is a single UPDATE — no cross-entity copy.
- The Phase 5 developer already wired UI, controller, resource, i18n,
  premium calculator, ACT tariff seed, and application-no minting to
  this model.

### What to keep as-is
- `QuoteController` (rename responsibilities are optional; the endpoint
  path `/quotes` maps cleanly to "policies in quotation stage" — no
  breaking change needed on the wire).
- Frontend pages + i18n block.
- `Policy` model as the single home for quote and application.

### What the new wizard should reuse
- `POST /quotes/premium/preview` — the same stateless premium calculator
  the 5-step wizard needs at step "ความคุ้มครองและเบี้ย".
- `GET /quotes/act-tariffs` — the ACT motor tariff picker for compulsory
  motor products.
- `POST /quotes/{id}/convert` — the state gate between Quotation and
  Submitted/Application.

### What the new wizard must add
- A `Draft` state BEFORE `quote_no` is minted (currently, POST /quotes
  immediately assigns `quote_no`). The wizard's step 1 needs a
  `status='draft'` intermediate save so a partial fill doesn't consume a
  quote-number serial.
- State transitions after Application: `Approved`, `Issued`, `Active`,
  `Expired`, `Cancelled`, `Rejected`, `Lapsed`. Covered by the state-enum
  audit (`01-policy-state.md`), not this one.

### What can potentially be deleted later (not now)
- `QuoteList.vue` — the new /policies list already shows all policies
  including quotes. Once the 5-step wizard replaces `QuoteEdit`, the
  `/quotes` route can either redirect to `/policies?status=quote` or
  stay as a filtered view. No urgent removal.
