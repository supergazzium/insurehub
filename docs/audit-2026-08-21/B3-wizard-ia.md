# B3 — 5-step Application Wizard IA

Design doc. Ground truth: `03-wizard-current.md` (existing 3-step surface),
`05-live-data.md` (duration histogram), `06-quotes.md` (single-table
lifecycle already exists), `B1-state-machine.md` (state names, verbs, lock
map), `B2-schema-plan.md` (writer shim for `risk_data`), `B4-risk-schema.md`
(dynamic Step 3 renderer contract), `B5-issue-modal.md` (post-issue lives
outside this wizard).

```
┌─────────────────────────────────────────────────────────────────────┐
│ 1. Party      → 2. Product     → 3. Risk        → 4. Premium   → 5. Review │
│    customer +      product +        dynamic          net/vat/       three    │
│    agent +         effective +      renderer from    installment +  buttons: │
│    ref_app_to      duration chip    schema           commission     draft /  │
│                    → auto expiry    → risk_data                     quote /  │
│                                                                     submit   │
└─────────────────────────────────────────────────────────────────────┘
         │                │                │              │            │
     draft-safe     draft-safe      draft-safe     draft-safe     terminal
     autosave       autosave        autosave       autosave       actions
```

## 1. Step-by-step field map

Every field carries a `stage-gate` column: **D**raft-safe (no requirement),
**Q**uotation-required (blocks บันทึกใบเสนอราคา), **S**ubmit-required
(blocks ส่งพิจารณา). Payload keys keep the existing `PolicyRequest`
naming so the writer shim (B2 §3) picks them up untouched.

### Step 1 — Party (ผู้เอาประกัน + ผู้ทำสัญญา)

| label_th | form_key | type | gate | payload → column | notes |
|---|---|---|---|---|---|
| ใหม่ / ต่ออายุ | `newOrRenew` | 2-button toggle | D | `newOrRenew` → `new_or_renew` | Default `'new'`. Move from existing wizard L36. |
| ต่ออายุจากกรมธรรม์เดิม | `refAppToId` | Autocomplete (renewalSearch) | D | `refAppToId` → `ref_app_to_id` | Visible only when `newOrRenew='renew'`. Existing prefill logic (L470-541) kept verbatim. |
| ลูกค้า | `customerId` | Typeahead (customerSearch, 250ms) | **Q** | `customerId` → `customer_id` | "New" button opens `customers?new=1`; BroadcastChannel bridge (L92-183) kept verbatim. |
| ตัวแทนที่ทำสัญญา | `writingAgentId` | Typeahead (agentSearch) | **Q** | `writingAgentId` → `writing_agent_id` | |
| เลขที่ใบสมัคร | `applicationNo` | text | D | `applicationNo` → `application_no` | Optional — auto-assigned on transition to Submitted (existing `nextApplicationNo()`). |
| เลขที่ Notion | `notionNo` | text | D | `notionNo` → `notion_no` | |

**Removed from Step 1** (was in existing wizard): `policyNo` — moves to
Issue Policy modal (B5). This is the lifecycle-collision fix.

### Step 2 — Product + Coverage (ประเภท + สินค้า + ระยะเวลา)

| label_th | form_key | type | gate | payload → column | notes |
|---|---|---|---|---|---|
| ประเภทการประกัน | `insureType` | 3-button toggle (life/non-life/tax) | **Q** | *(derived, not sent)* | Filters carrier dropdown. |
| บริษัทประกัน | `carrierId` | `<select>` (per insureType) | **Q** | `carrierId` → `carrier_id` | |
| สินค้า | `productId` | Typeahead (per carrier) | **Q** | `productId` → `product_id` | Reveals `productType.kind` badge. Drives Step 3 schema. |
| ปีกรมธรรม์ | `policyYear` | number `min=1` | D | `policyYear` → `policy_year` | |
| ปี พ.ร.บ. | `actYear` | number `min=1` | D | `actYear` → `act_year` | Motor-only display. |
| วันที่สมัคร | `appDate` | DateInput | D | `appDate` → `app_date` | Defaults to today on Draft-save. |
| วันเริ่มคุ้มครอง | `effectiveDate` | DateInput `:max="expiryDate"` | **Q** | `effectiveDate` → `effective_date` | |
| ระยะเวลา (chip row) | `durationChip` | chip picker (§3) | — | *(derived; drives expiryDate)* | See §3 for chip presets per kind. |
| วันสิ้นสุดความคุ้มครอง | `expiryDate` | DateInput `:min="effectiveDate"` | **Q** | `expiryDate` → `expiry_date` | Auto-computed from chip; editable, clears chip on manual edit. |
| ความคุ้มครอง (บาท) | `coverage` | number `min=0` | D | `coverage` → `coverage` | Optional; 0 default. |

### Step 3 — Risk (dynamic, schema-driven)

Zero hardcoded fields. Renderer reads `product.productType.riskSchema`
(B4 §1 shape) and produces inputs from `sections[].fields[]`.

Per-kind schemas (authored in Admin per B4 §2):
- **motor** — vehicle section (brand/model/license top-level via
  `storage:column`; type_driver/type_vehicle/register_year/engine_no/
  chassis_no/no_passenger/notes under `risk_data.motor.*`)
- **travel** — trip section (destination/start/end/traveler_count/passport)
- **fire** (property) — property section (insured_name/address/phone/
  building_cov/furniture_cov/stock_cov/other_cov/other_detail/notes)
- **health** — insured person section + single beneficiary section
- **life** — insured person + beneficiaries (array 0-4, share=100) +
  riders (array 0-5)
- **misc** — empty schema; renders "ไม่มีรายละเอียดเพิ่มเติม" placeholder

Gate: fields marked `required: true` in schema become **S**-gate at
Step 5's submit action. All Draft-safe by default (empty schema-required
fields don't block draft-save).

Prior-asset autofill (§4) renders as a dropdown above the section when
`schema.fields[].prior_autofill=true` AND `customerId` set.

### Step 4 — Premium + Payment

Preserves the auto-recalc watchers from existing wizard (L556-581) — see
§9 for KEEP/REBUILD map. Fields:

| label_th | form_key | gate | payload → column |
|---|---|---|---|
| เบี้ยสุทธิ | `netPremium` | **S** | `netPremium` → `net_premium` |
| เบี้ยหลัก | `mainPremium` (touched) | D | `mainPremium` → `main_premium` |
| อากรแสตมป์ | `dutyStamp` | D | `dutyStamp` → `duty_stamp` |
| ภาษีมูลค่าเพิ่ม | `vat` | D | `vat` → `vat` |
| รวมเบี้ยที่ต้องชำระ | `totalPremiumPaid` | **S** | `totalPremiumPaid` → `total_premium_paid` |
| ยอดหัก ณ ที่จ่าย | `whtAmt` | D | `whtAmt` → `wht_amt` |
| ยอดสุทธิที่ลูกค้าชำระ | `netCustomerPaid` | D | `netCustomerPaid` → `net_customer_paid` |
| โหมดชำระเบี้ย | `premiumMode` | D | `premiumMode` → `premium_mode` |
| งวดการผ่อน | `installmentTerm` | D | `installmentTerm` → `installment_term` |
| ยอดงวดแรก | `firstDueInst` | D | `firstDueInst` → `first_due_inst` |
| วันครบงวดแรก | `firstDueInstDate` | D | `firstDueInstDate` → `first_due_inst_date` |
| ยอดงวดถัดไป | `nextDueInst` | D | `nextDueInst` → `next_due_inst` |
| วันครบงวดสุดท้าย | `lastDueInstDate` | D | `lastDueInstDate` → `last_due_inst_date` |
| ค่าคอมมิสชั่น (override) | `mainCom{Rate,Amt}{Inh,Ag}` | D | same → `main_com_*` (via `policy_rebates`) |
| หมายเหตุ | `notes` | D | `notes` → `notes` |

**Status `<select>` REMOVED** (was at existing L1553-1558). State
transitions come from Step 5's action buttons — the operator never
picks status.

### Step 5 — Review + Save

Read-only summary card of everything above. Three action buttons in a
bottom bar:

| Button | Verb | Endpoint | Result | Requires |
|---|---|---|---|---|
| **บันทึกฉบับร่าง** (Save draft) | `draftCreated` (B1 §5) | `POST /policies/draft` (new — §7) | `status=draft`, no numbers minted | Nothing (any partial fill) |
| **บันทึกใบเสนอราคา** (Save quotation) | `quotationMinted` (B1 §5) | `POST /quotes` (existing) → mints `quote_no` | `status=quotation` | Q-gated fields filled |
| **ส่งพิจารณา** (Submit to carrier) | `convertedToApplication` or `submittedFromDraft` (B1 §5) | Two-call: `POST /quotes` then `POST /quotes/{id}/convert`, OR one-call short-path if operator skipped Quotation | `status=submitted`, mints `application_no` | S-gated fields filled |

## 2. State transitions per action button

```
                      ┌─────────────┐
   [Save draft]  ────►│    draft    │─────┐
                      └─────────────┘     │
                                          │
                      ┌─────────────┐     │
   [Save quotation] ─►│  quotation  │◄────┤
                      └──────┬──────┘     │
                             │            │
                             ▼            ▼
                      ┌─────────────┐
   [Submit] ─────────►│  submitted  │
                      └─────────────┘
```

- **บันทึกฉบับร่าง** → verb `draftCreated`. Auto-fires on Step 1
  autosave (see §7). Idempotent: subsequent draft-saves are PATCH not
  POST. No `quote_no` / `application_no` consumed.
- **บันทึกใบเสนอราคา** → verb `quotationMinted`. Draft (if any)
  promotes; brand-new record short-path is `POST /quotes` directly.
- **ส่งพิจารณา** → verb `convertedToApplication` (from Quotation) or
  `submittedFromDraft` (from Draft directly). Backend routes through
  `PolicyEventController` (B1 §5) so the guard fires.

Cancellation from any of the three is a fourth quiet path: verb
`cancelled` fired via the drawer's row action menu, not from within the
wizard. The wizard's Cancel button just closes it; the record persists
in Draft.

## 3. Duration chip engine

Chip data comes from `05-live-data.md §5` validated histogram:

| product kind | chips (in order) | default |
|---|---|---|
| motor | [`1 year`] | `1 year` |
| CTPL (พรบ) | [`1 year`] | `1 year` |
| travel | [`3 days`, `5 days`, `7 days`, `14 days`, `30 days`] | `7 days` |
| fire | [`1 year`, `3 years`, `5 years`] | `1 year` |
| health | [`1 year`] | `1 year` |
| pa | [`1 year`] | `1 year` |
| life | [`1 year`, custom-year input] | `1 year` |
| misc | (no chips, custom date input only) | — |

Mechanics:
- Chip click → sets `expiryDate = effectiveDate + duration - 1 day` at
  `23:59:59 Asia/Bangkok`. Server treats the date as inclusive; the
  time-of-day component is not persisted (columns are DATE, not DATETIME
  — confirmed in `02-policy-schema.md`). The 23:59:59 is a UI
  guarantee for the operator's reading of the value.
- Auto-recompute when `effectiveDate` changes AND a chip is currently
  selected.
- Chip clears on manual `expiryDate` typing (touched-flag).
- Chip persists across steps 3-4 for the read-only summary display in
  Step 5.
- Chip presets are wizard-only client config, NOT stored per policy.
  The stored value is the resolved `expiry_date`. If future
  requirements need the chip echoed back (e.g., "renew for same
  duration"), derive it at read time from `expiry_date - effective_date`.

## 4. Autofill from prior policies (customer + asset reuse)

Backend endpoint (new): **`GET /customers/{id}/prior-assets?kind=motor`**

Returns a de-duplicated list of prior asset blocks belonging to that
customer for that kind. Dedupe key comes from
`riskSchema.dedupe_keys` (B4 §5). For motor: `license_no + chassis_no`.

Response shape:

```json
{
  "kind": "motor",
  "assets": [
    {
      "dedupe_key": "ฆง8xxx-CHS12345",
      "last_used_policy_no": "706-…",
      "last_used_at": "2024-07-23",
      "fields": {
        "vehicle_brand": "TOYOTA",
        "vehicle_model": "HILUX",
        "license_no": "ฆง8xxx",
        "chassis_no": "CHS12345",
        "engine_no": "ENG67890",
        "register_year": "2020",
        "no_passenger": 4
      }
    }
  ]
}
```

UI: when Step 3 renders for a motor product AND `customerId` set AND
`license_no` empty AND `assets.length > 0`, show a dropdown above the
vehicle section:

> **ใช้ข้อมูลจากกรมธรรม์ก่อนหน้า** ▾  ← TOYOTA HILUX (ฆง8xxx) · เคยใช้กับ 706-…

Pick → fills every field in the block (both top-level columns and
`risk_data.motor.*`). Fields become touched, so subsequent Step 3
edits work normally.

Applies to any schema with a `dedupe_keys` declaration — motor now,
travel (traveler_passport) and health (insured_person_id_card)
naturally follow when their schemas are authored.

Renewal-source prefill (existing wizard L470-541) stays — that's a
different flow (pick a specific prior policy and copy the whole record,
including party + product + risk). Prior-asset reuse is narrower (same
customer, same kind, pick one asset).

## 5. Validation gates per step

Enforcement location: each step exposes a `blockers()` computed. The
wizard's action bar disables buttons based on the merged blocker set.

### Draft-safe (D) — no requirements

Every field is D by default. `POST /policies/draft` accepts an empty
payload — writer shim only persists what's provided.

### Quotation-required (Q) — blocks บันทึกใบเสนอราคา

- Step 1: `customerId`, `writingAgentId`
- Step 2: `insureType`, `carrierId`, `productId`, `effectiveDate`,
  `expiryDate`
- Step 3: nothing (risk fields are S, not Q — quotation can be issued
  without full risk detail; carrier needs the risk on Submit)
- Step 4: nothing
- Step 5: N/A

### Submit-required (S) — blocks ส่งพิจารณา

All Q above, plus:

- Step 3: every schema field where `required=true`. Per B4 §1 that
  includes (motor: license_no; travel: destination, start, end;
  fire: insured_name, insured_address; health: insured_person_name,
  insured_person_id_card, insured_person_birth_date; life: same as
  health + `beneficiaries.length >= 1` + `sum(beneficiary.share) = 100`)
- Step 4: `netPremium > 0` OR `totalPremiumPaid > 0` (either the
  net or total must be non-zero — existing wizard's L611 blocker)

### Error surfacing

Same pattern as customer form (touched-flag + attempted-submit — see
`CustomerCreateModal.vue:241-259`). Inline red text under fields; a
summary banner at the top of the failing step; auto-jump to earliest
failing step on button click (existing L830-846 pattern).

## 6. Resume flow

### List page

`PolicyListV2.vue` gains a **"ฉบับร่าง (Drafts)"** filter/tab in the
existing filter row. Behind the scenes: `?status=draft&scope=me`
scoped to `writing_agent_id = current_user.agent_id`.

Draft row renders differently:
- No policy_no / application_no columns (nothing to show)
- Actions: **Resume** (opens wizard) · **ลบ** (DELETE, guarded to
  status=draft only)

### URL scheme

| URL | Behavior |
|---|---|
| `/insurehub/policies/new` | Fresh wizard, `draftId` = none |
| `/insurehub/policies/new?draftId=123` | Load draft 123, jump to
  first incomplete step |
| `/insurehub/policies/new?refId=456` | New renewal-source prefill from
  policy 456 (existing renewalSource logic wraps this) |

### Step resumption

`resumeFromStep` computed = highest step where any field is filled.
Wizard opens on that step but preserves left-of state (steps stay
navigable via the step indicator).

### Local persistence (offline safety)

Autosave debounced 800ms writes to backend `PATCH /policies/{id}/draft`.
On network failure, wizard degrades to `localStorage[draftId-<uuid>]`
snapshot and shows a yellow banner "ยังไม่ได้บันทึกออนไลน์". Snapshot
POSTs on next successful call. This mirrors the "draft-safe" contract:
partial fills survive.

## 7. Save-draft mechanics

### New endpoints

**`POST /policies/draft`** — creates a `policies` row with
`status='draft'`. **Does NOT** call `nextQuoteNo()` /
`nextApplicationNo()` (unlike existing `POST /quotes` per `06-quotes.md`
§5). Emits `PolicyEvent { verb: 'draftCreated' }` (B1 §5).

Payload: any subset of `PolicyRequest` fields. Everything nullable at
this stage. Response: 201 with `PolicyResource` shape.

**`PATCH /policies/{id}/draft`** — updates draft in place. 409 if
`status != 'draft'`. Same permissive validation. Autosave hits this.

**`POST /policies/{id}/promote-to-quotation`** — mints `quote_no`,
flips status to `quotation`. Emits `quotationMinted`. Guard: from
`draft` only.

**`POST /policies/{id}/promote-to-submitted`** — mints `application_no`,
flips status to `submitted`. Emits `submittedFromDraft` (from `draft`)
or `convertedToApplication` (from `quotation`, reusing existing
endpoint pattern).

**`DELETE /policies/{id}`** — soft-deletes. 409 if `status != 'draft'`
(non-draft policies get cancelled, not deleted).

### Retention

Draft rows older than 30 days auto-delete via daily scheduler
(`TransitionPoliciesDaily` command from B1 §7 gains a `deleteOldDrafts`
sub-task). Prevents the drafts tab from filling with abandoned records.

## 8. i18n key additions

**Do NOT rename existing keys.** Add new keys under `policyCreate.*`
(existing namespace at `i18n/th.ts:1515-1642` — see `03-wizard-current.md`
§9).

### Step labels — extend existing

Existing keys:
- `policyCreate.step.1` = ข้อมูลหลัก
- `policyCreate.step.2` = ความคุ้มครองและเบี้ย
- `policyCreate.step.3` = รายละเอียด

Existing 3 keys **repurposed** for the new 5-step layout:

| new step | i18n key | Thai | English |
|---|---|---|---|
| 1 | `policyCreate.step.1` (existing, retext) | ผู้เอาประกัน | Party |
| 2 | `policyCreate.step.2` (existing, retext) | สินค้า + ความคุ้มครอง | Product & coverage |
| 3 | `policyCreate.step.3` (existing, retext) | รายละเอียด | Risk details |
| 4 | `policyCreate.step.4` (new) | เบี้ย + การชำระ | Premium & payment |
| 5 | `policyCreate.step.5` (new) | ตรวจสอบและบันทึก | Review & save |

The step keys are RETEXTED, not renamed. Existing string translations
for "ข้อมูลหลัก" appear only in the wizard header — safe to update.

### Action buttons

```
policyCreate.action.saveDraft         บันทึกฉบับร่าง                 Save as draft
policyCreate.action.saveQuotation     บันทึกใบเสนอราคา               Save as quotation
policyCreate.action.submitToCarrier   ส่งพิจารณา                     Submit to carrier
policyCreate.action.savingDraft       กำลังบันทึกอัตโนมัติ…           Auto-saving…
policyCreate.action.draftSaved        บันทึกฉบับร่างเรียบร้อย        Draft saved
```

### Duration chips

```
policyCreate.duration.days_3          3 วัน                          3 days
policyCreate.duration.days_5          5 วัน                          5 days
policyCreate.duration.days_7          7 วัน                          7 days
policyCreate.duration.days_14         14 วัน                         14 days
policyCreate.duration.days_30         30 วัน                         30 days
policyCreate.duration.years_1         1 ปี                           1 year
policyCreate.duration.years_3         3 ปี                           3 years
policyCreate.duration.years_5         5 ปี                           5 years
policyCreate.duration.custom          กำหนดเอง                       Custom
policyCreate.duration.expiryHint      คำนวณจากวันเริ่มคุ้มครอง + ระยะเวลา  Auto-computed
```

### Prior-asset autofill

```
policyCreate.reuseFromPrior.label      ใช้ข้อมูลจากกรมธรรม์ก่อนหน้า   Reuse from prior policy
policyCreate.reuseFromPrior.motor      รถยนต์เดิม                     Prior vehicle
policyCreate.reuseFromPrior.travel     ผู้เดินทางเดิม                 Prior traveler
policyCreate.reuseFromPrior.health     ผู้เอาประกันเดิม              Prior insured person
policyCreate.reuseFromPrior.empty      (ไม่พบข้อมูลก่อนหน้า)          (No prior asset found)
policyCreate.reuseFromPrior.lastUsed   เคยใช้กับ {ref} · {date}       Used on {ref} · {date}
```

### Resume / draft state

```
policyCreate.resumeDraft.title         กลับมาทำต่อจากฉบับร่าง         Resume draft
policyCreate.resumeDraft.hint          ระบบบันทึกอัตโนมัติล่าสุด: {ts}  Last auto-saved {ts}
policyCreate.resumeDraft.discard       ทิ้งฉบับร่างนี้                Discard this draft
policyCreate.resumeDraft.offline       ยังไม่ได้บันทึกออนไลน์         Not yet saved online
policyCreate.drafts.tab                ฉบับร่าง                      Drafts
policyCreate.drafts.mine               ของฉัน                        Mine
policyCreate.drafts.retentionHint      ฉบับร่างจะถูกลบอัตโนมัติหลัง 30 วัน  Drafts auto-delete after 30 days
```

### Reuse existing keys — no additions needed

All field labels from Step 1-4 already exist under `policyCreate.*`
per `03-wizard-current.md` §9. Kind-specific risk labels come from
`riskSchema` (B4 §6 — labels-in-schema strategy). No new i18n needed
for the dynamic Step 3.

### English mirror

Every new Thai key gets an English counterpart in `en.ts` under the
same path. Table above has the English column pre-populated.

## 9. Existing wizard code — KEEP / REBUILD / REPLACE

Refined from `03-wizard-current.md` §10, with post-B1/B2/B4/B5 context:

| Verdict | Item | Notes |
|---|---|---|
| **KEEP verbatim** | BroadcastChannel bridge (L92-183) | Cross-tab customer/product create — no reason to touch. |
| **KEEP verbatim** | Renewal-source prefill (L470-541) | Different flow from prior-asset autofill (§4). |
| **KEEP verbatim** | Premium recalc watchers (L556-568) | Move-not-rewrite constraint. |
| **KEEP verbatim** | Commission-amount recalc (L574-581) | Move-not-rewrite constraint. |
| **KEEP verbatim** | Customer / agent / product typeahead patterns | Extract into shared `<EntityPicker>` while rebuilding — pattern lives in ≥3 places (wizard, PolicyEdit parties, quote edit). |
| **KEEP + wrap** | `DateInput` usage (Step 2 dates + Step 4 installment dates) | Already fixed for dd/mm/yyyy. |
| **KEEP + wrap** | `FormField` label/error/hint (all steps) | Reuse verbatim. |
| **REBUILD** | Step 3 branching logic (L625-1400ish) | Replace with `<RiskFieldRenderer>` reading `product.productType.riskSchema` per B4 §3. Every hardcoded motor/property/travel/life/health block dies here. |
| **REBUILD** | expiry-date auto-fill (L673-681) | Replace with duration chip engine (§3). Chip presets replace the "+1y-1d" hardcode. |
| **REBUILD** | Status auto-pick by kind (L646-652) | Replace with 3-button action bar at Step 5 (§1). Status is never operator-picked. |
| **REPLACE** | Status `<select>` (L1550-1558) | Deleted entirely. State transitions are verbs, not values. |
| **REPLACE** | Submit function (L826-846) | Splits into `saveDraft()` / `saveQuotation()` / `submitToCarrier()` per §7 endpoints. Error-jump logic (L830-846) reused for the fail path. |
| **NEW** | Autosave debouncer (Step 1 → auto-`POST /policies/draft` on customerId pick) | 800ms debounce. See §7. |
| **NEW** | Duration chip component | Reusable `<DurationChip :presets :modelValue>` — presets from §3. |
| **NEW** | `<RiskFieldRenderer>` component | Per B4 §3. Consumes schema, emits `risk_data`. |
| **NEW** | Prior-asset autofill dropdown | Per §4. Renders inline in Step 3 above the section. |
| **NEW** | Draft resume flow | List-page filter + URL `?draftId=` handling + `resumeFromStep` computed. |
| **NEW** | Offline snapshot | `localStorage` fallback on network failure. |

## 10. Open questions

1. **`insureType` derivation** — the existing 3-button toggle
   (life/non-life/tax) filters carriers. With `product.productType.kind`
   available, do we drop the toggle and let the operator pick a product
   directly? Or keep the toggle for scannability (fewer carriers per
   click)? Recommend keep the toggle as a filter but auto-select it
   from `kind` if the operator picks product first.

2. **Draft ownership** — drafts scoped to `writing_agent_id`. What
   about admin drafts (no agent)? Proposal: drafts also visible to
   Platform Admin regardless of scope. Confirm.

3. **Quotation → Draft downgrade** — allowed by B1 §2 matrix? No — B1
   forbids terminal-to-non-terminal, and quotation isn't terminal but
   still one-way. If operator wants to "unquote", the workflow is:
   cancel quotation, start a new draft. Confirm this is acceptable
   UX; alternative is a `demote-to-draft` verb.

4. **Prior-asset autofill for travel** — traveler_passport is PII.
   Should the endpoint mask/redact non-owner passports across
   customers? Proposal: only return assets owned by the same
   `customer_id`. Verify.

5. **Save-quotation short-path from mid-wizard** — must all Q-gated
   fields be filled to enable the button, or can we save whatever's
   there and let the backend `POST /quotes` validate? Recommend
   backend-validates so the button is available whenever Draft-safe
   fields are filled, but the button click may return a 422 that
   surfaces via existing error-jump logic. Confirm.
