# Phase A audit — summary & Phase B recommendations

Consolidated from the 6 fork reports in this directory. Read those for
line-refs; this file is the one-page decision brief.

## What we found vs the audit brief

| Brief claim | Reality (per audit) | Impact on plan |
|---|---|---|
| "Modal creates both an Application and an Issued Policy" | Correct — Step 1 collects `policyNo` (post-issue). | Split wizard: Application (pre-issue) + Issue modal (post-issue). |
| "9-code enum is semantically broken" | 9 codes exist (`quote…expired`), but seed data only produces 3 (`active` 95%, `submitted` 3%, `cancelled` 2%). Frontend i18n block for status is defined but unused (drawer/list/wizard hardcode strings inline). Two label sources drift (`i18n/th.ts` vs `policy_statuses.name_th`). | Enum redesign is safe — no rich distribution to preserve. Backfill infers Issued/Active/Expired from `expiry_date`. |
| "Manual expiry, no duration chips" | Confirmed. Fleet-wide duration histogram validates 5 chip presets — 1-7d (travel), 1mo (travel), 1y (motor/CTPL/health/pa), 3y and 5y (fire). | Duration chip engine is straightforward — 5 buttons per kind. |
| "Static, motor-biased Step 3" | Confirmed with hard numbers: 3,168 NULL cells across 9 motor columns on non-motor rows. `vehicle_on_non_motor` bool already exists — the team has already seen drift. | JSON `risk_data` migration is justified. Motor `license_no`, `brand`, `model` stay top-level (list col + search); the other ~30 fields move into `risk_data.<kind>.*`. |
| "No data reuse" (customer re-types plate/chassis every time) | Renewal-source prefill IS wired at `PolicyCreateWizard.vue:470-541` (pulls from a previous policy on pick). No "same car, different policy" autofill though. | Ship an EntityPicker with a "previous vehicles by customer" API. Low priority — renewal prefill already handles the common case. |
| "Quote module orphaned" | **False.** Fully wired backend + 3 frontend pages + `convertQuoteToApplication`. Empty because no rows have `status='quote'` in seed data. | Keep it. Option A (single-table lifecycle) is what already exists. |
| Products page constraint (no breaking changes) | `kind` is already derived at runtime by `App\Support\ProductKind::derive()` — not stored. `risk_schema` doesn't exist yet. | Add both fields to `product_types` (NOT `products`). Zero edits to Product pages. |

## Key discoveries (things the brief didn't flag)

1. **`i18n/th.ts` status labels are dead code.** `policies.status.*` block (lines 980-1001) is defined but `grep t('policies.status.` returns zero hits. Badges and dropdowns hardcode Thai strings inline. This is why the DB `policy_statuses.name_th` labels don't match the i18n block — nobody reads the i18n block. Safe to repurpose during the state-machine refactor.

2. **`PolicySeeder::STATUS_MAP` contradicts `policy_status_translations`.** Both map legacy Thai → new code, differently, for `รอตรวจรถ`. This is a bug the state-machine refactor should fix (one source of truth).

3. **Backend has no direct `status =` write in `PolicyController`.** All transitions flow through `PolicyEventController` (a central table with 7 verbs). This is EXCELLENT news — the state machine already has a chokepoint. We add transition validation once, in one file.

4. **`PolicyResource` emits `motor`+`property` as nested objects but `travel`/`life`/`health` as flat.** Inconsistency — same wizard binds two ways. New JSON-schema renderer should normalize on nested.

5. **`QuoteController` already assigns `quote_no` on POST.** So `Draft` (pre-quote-number) doesn't exist yet — the new wizard must add a `status='draft'` intermediate save that DOESN'T mint a quote number.

6. **10 policy columns can be dropped from top-level** post-migration: `motor_type_driver/type_vehicle/engine_no/chassis_no/register_year/no_passenger/notes` + all `property_*` + all `trip_*`/`traveler_*` + all `insured_person_*`/`health_*`. Total ~30 columns move to JSON. `policies` table shrinks meaningfully.

## Phase B design docs — I'll draft these

Recommended state-machine spec (based on the audit — open for discussion):

```
Draft ─────┐
           ├→ Quotation ── (convert) ──┐
           │                            ↓
           └────────────────────→ Submitted ── (carrier response) ──┬→ Approved → Issued → Active → Expired
                                                                    ├→ Rejected
                                                                    └→ Cancelled

  From Active: → Lapsed | Cancelled | Renewed (→ new record, chained via ref_app_to_id)
```

Backfill rules (from `05-live-data.md`):
- `active` + `policy_no` set + `expiry_date > now` → **Active**
- `active` + `policy_no` set + `expiry_date <= now` → **Expired**
- `active` + no `policy_no` → **Submitted** (data-quality downgrade)
- `submitted` → **Submitted**
- `cancelled` → **Cancelled**
- Rare CSV placeholder-date rows → **Draft** (11 anomalous rows)

## Phase B deliverables (I propose, ~2 more rounds of audit-agent work)

I'll write these as docs first, no code:

1. **B1 — State machine spec** — the enum, allowed transitions per role, migration + backfill script (safe, reversible)
2. **B2 — Schema migration plan** — additive columns on `product_types` (`kind`, `risk_schema`) + `policies.risk_data JSON` + backfill + drop-column follow-up migration (later, gated)
3. **B3 — Wizard IA (5 steps)** — field-by-field, validation per step, save-draft behavior, resume flow
4. **B4 — JSON risk-schema format** — with worked examples for motor / travel / fire / health / life / misc
5. **B5 — Issue Policy modal spec** — the 7 post-issue fields + certificate document upload

Then Phase C is 5-8 small PRs, each independently deployable.

## Decision needed before Phase B

1. **State-machine shape** — the diagram above merges Approved+Issued in practice (Thai insurance workflow), but the brief explicitly lists both. Confirm whether:
   - (a) Keep Approved+Issued as separate states (7-state model), OR
   - (b) Collapse to 6 (Approved==Issued, no separate carrier-approved-but-no-number-yet state)

2. **Products vs product_types for `kind` + `risk_schema`** — audit recommends `product_types` (26 rows, one config per taxonomy row, no Products page edit). Confirm you're OK with this vs putting on `products` (894 rows).

3. **Quote route** — audit recommends KEEP `/quotes` (it already works, and the wizard reuses `POST /quotes/premium/preview` and `POST /quotes/{id}/convert`). The alternative is delete the route and fold it into `/policies?status=quote`. Which do you prefer?

4. **Risk-column drop timing** — the schema plan is two-migration: (a) add `risk_data`, backfill, both readers/writers work; (b) drop retired columns. Confirm you want (b) scheduled, or leave columns permanently for safety.

Tell me which decisions to lock and I'll launch the Phase B audit-agent set.
