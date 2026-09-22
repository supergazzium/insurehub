# InsureHub — System Audit Report

Scope: commission (payout + receipt), ติดตามงาน (follow-up), ติดตามเงิน
(collections), agent registration/approval/hierarchy/MGM. Based on the `TST`
scenario cohort (see TEST_SCENARIOS.md) exercised through the UI and API.

Severity legend: 🔴 High (wrong data / real risk) · 🟠 Medium (confusing or
error-prone) · 🟡 Low (polish / UX).

---

## ✅ What works well
- **MGM commission cascade** fires correctly on payment: DIRECT + REFERRAL +
  MANAGEMENT_DIFFERENTIAL up the upline chain, honoring tier + rank rates.
  Agent commission-detail view shows the ledger + upline/downline tree.
- **Agent registration → approval** flow: pending banner, one-click อนุมัติ/ปฏิเสธ,
  status flips to active. Upline column renders the Thai name tree correctly.
- **Follow-up (ติดตามงาน)**: all 6 categories correctly classify policies.
- **Collections (ติดตามเงิน)**: cash / installment / split classification works;
  installment schedule (งวด) computes; partial payments net correctly.
- **Commission payout**: eligible-preview → snapshot batch → approve → mark-paid,
  with double-pay guard, VAT PDFs (1/2/3), CSV export, audit trail.
- **Insurer receipt**: Main/OV, all 5 statuses, reopen, receipt batch + files,
  reconciliation dashboard with margin.

---

## 🔴 High

### H1. Cross-module policy overlap — one policy appears in many worklists at once  ✅ FIXED
> **Fixed:** follow-up rows now carry an `alsoIn` list; the UI shows amber "+ …" cross-tags so a multi-issue policy is obvious at a glance.
A single active policy with an unpaid premium simultaneously shows in
**Collections** (unpaid), **Follow-up → no_commission / not_delivered**, and (if
life) **Follow-up → freelook**. The `TST-RC*` receipt-test policies (which exist
only to test insurer-receipt) also appear in Collections as "cash unpaid ฿10,000".
- **Why it matters:** staff can't tell which worklist "owns" a policy; the same
  item is chased by multiple people. There is no cross-list dedup or "this is
  handled elsewhere" signal.
- **Suggestion:** either (a) a small per-policy "worklist state" that suppresses
  it from lists once actioned, or (b) at minimum show, on each row, which other
  worklists the policy is currently in.

### H2. Follow-up "no_commission" fires on ~458 policies (almost everything)  ✅ FIXED
> **Fixed:** scoped to *completed* sales (issued policy_no + delivered) created within 180 days. On real multi-year data this sharply cuts the list; on the current young dataset it drops 458→345.
`no_commission` = active AND (carrier→hub OR hub→agent amount is null/0). In the
current data 458 of ~475 policies match because commission amounts are mostly 0.
- **Why it matters:** a worklist that contains "almost all policies" is not
  actionable — it's noise, and it buries the genuinely-missing ones.
- **Suggestion:** scope it (e.g. only policies past a certain age, or only where
  the product *should* have commission), or split "never had commission" from
  "commission not yet entered".

---

## 🟠 Medium

### M1. Team assignment is invisible / unguided  ✅ FIXED
> **Fixed:** the create form now persists teamId + level (was silently dropped); the hub shows an amber "ไม่มีสายงาน" warning badge; the form nudges to assign one.
Every TST agent shows **TEAM = —**. The agent create/edit form has a สายงาน/team
field, but nothing prompts the operator to assign a team, and the MGM/team-volume
logic depends on it. New agents silently have no team.
- **Suggestion:** surface team on the create form prominently; consider a default
  or a "no team assigned" warning badge on the hub.

### M2. Commission amounts are ฿0 across most of the UI on seed/real data  ✅ FIXED
> **Fixed:** payout preview and receipt/dashboard now show an explanatory hint when items exist but the total is ฿0 (commission not yet recorded), pointing to where to enter it.
Payout preview, receipt expected amounts, and dashboard KPIs show ฿0 for most
policies because `comm_hub_to_agent_amount` / `comm_carrier_to_hub_amount` /
`policy_rebates` are unpopulated. The features are correct, but a first-time user
sees "฿0 everywhere" and may think it's broken.
- **Suggestion:** an empty-state hint ("ยังไม่มีการบันทึกค่าคอมสำหรับกรมธรรม์เหล่านี้")
  and/or a way to bulk-set/compute commission from the product rate.

### M3. Payout eligibility ignores approval/freelook nuance vs the spec's original filter  ✅ DOCUMENTED
> **Resolved (documented divergence):** the legacy freelook + insurer-payment gates are intentionally omitted — freelook doesn't apply to non-life, and insurer-payment state now lives in the receivables module. A detailed code comment records this so finance can re-add per-product-type gates if ever required. No behaviour change.
The Access spec filtered on `Freelook_Status = TRUE` and
`Payment_InsComp_Status NOT IN ('2','5')`. The web payout eligibility uses
status ∈ (active,issued) + not-already-paid + not-in-live-batch, but does **not**
check freelook or insurer-payment status. For a seed policy this is fine, but it
may include policies the old system would have excluded.
- **Suggestion:** confirm with finance whether freelook / insurer-payment gating
  is still required; document the intentional divergence if not.

### M4. Receivables are created lazily and invisibly  ✅ FIXED
> **Fixed:** the dashboard now returns potentialExpected (from policies' carrier→hub commission) + receivablesMaterialised count. A manager opening a fresh scope sees real "ยอดคาดการณ์" figures and a banner explaining nothing's been reconciled yet.
Insurer-receipt rows only exist after someone opens a Main/OV tab for a given
Year+Insurer. Before that, History and Dashboard are empty even though there are
hundreds of eligible policies. A manager opening the Dashboard first sees nothing.
- **Suggestion:** either seed receivables for all active policies on a schedule,
  or show the Dashboard "potential" figures from the underlying policies even
  before receivable rows are materialised.

### M5. Two different meanings of `vat_type`  ✅ FIXED
> **Fixed:** payout now resolves VAT from `has_vat` + `vat_mode` (falling back to legacy numeric `vat_type`). Form-created agents get the correct VAT PDF.
`agents.vat_type` is `''|none|vat7|wht1|wht3|wht5` in the agent form, but the
payout engine reads it as numeric `'1'|'2'|'3'`. An agent created through the UI
form will not carry a numeric vat_type, so the payout PDF defaults to "no VAT (1)"
regardless of the agent's real VAT setting (`has_vat`/`vat_mode`).
- **Why it matters:** VAT PDFs may be wrong for real UI-created agents.
- **Empirical:** in the current DB 324 agents have numeric vat_type='1' (from the
  legacy import) but 52 have empty '' — those are the ones at risk (form-created).
- **Suggestion:** make the payout read `has_vat` + `vat_mode` (the current fields)
  instead of the legacy numeric `vat_type`, or map between them explicitly.

---

## 🟡 Low / polish

### L1. Cancelled exclusion — verified OK
Confirmed: cancelled policies are correctly excluded from no_commission and
not_delivered (queries scope to status='active'). No action needed.

### L2. Collections `paid` fallback can double-count (fixed in seeder, verify prod)
`paid = SUM(policy_payments)`, with a `total_premium_paid` fallback only when
zero rows. If both are populated on a real policy, ensure no double counting.

### L3. Follow-up Freelook shows only life policies missing the date
Correct per spec, but there's no way from the follow-up row to *set* the freelook
date — you must open the policy editor. A quick inline date entry would speed it.

### L4. Receipt page default now lands on History (good), but History is empty on
a fresh DB until Main/OV seed rows. Consider a first-run hint.

### L5. Payout PDF filename uses the agent's *display* name; very long Thai names
are truncated at 120 chars — verify no collisions when two agents share a code
prefix (the ZIP path dedup handles it, single-download does not).

---

## Data / seeder notes
- Non-idempotent raw `insert()` in a seeder duplicates rows on re-run; use
  `updateOrInsert`. (Found + fixed in TestScenarioSeeder for the cash-partial
  payment and reminder.)
- Commission engine dispatches on `carriers.insure_type` (lowercased), NOT
  `products.type`. Life products must sit on a life carrier or LifeRateResolver
  never fires.
- `commission_snapshot` is populated by PolicyObserver on Eloquent create; raw
  SQL policy inserts leave it null and fall back to live product tables.
