# P1 — Ledger-driven payout core: design for review

Status: **DESIGN ONLY — no service code changed.** Review before implementing.
The business confirmed it needs upline-override + referral-split + 50-ทวิ +
PND reports (the 33 `markTestIncomplete` tests). This doc specifies the P1
rewrite of `CommissionPayoutService` so you can vet the money logic first.

## Why P1 is needed (correctness, not just a feature)
The current live service pays each policy's commission as ONE lump to the
**selling agent only**. It does NOT pay uplines their override or the referrer
their referral fee — even though `MgmCommissionEngine` already *accrues* those
amounts into `commission_ledgers`. So today uplines are effectively under-paid
from the payout side. P1 makes the payout consume what the engine accrued.

## What already exists (reuse, do not rebuild)
`MgmCommissionEngine` (live, on every PolicyPayment) writes snapshotted
`commission_ledgers` rows. For a chain seller(L1) → mid(L3) → top(L5), one sale:

| ledger payout_type | beneficiary | amount | maps to item line_type |
|---|---|---|---|
| DIRECT_COMMISSION | seller | 2000 | `base` |
| MANAGEMENT_DIFFERENTIAL | mid | 1000 | `override` |
| MANAGEMENT_DIFFERENTIAL | top | 200 | `override` |
| REFERRAL_FEE | (seller's 1st upline = mid) | (rate-based) | `referral` |

## The exact oracle (test #1) and what it dictates
```
byAgent.amount:  seller 2000 · mid 1000 · top 200   (grouped by BENEFICIARY)
batch items (by line_type): [base, override, override, referral]   (4 items)
ledgers WHERE payout_batch_id = batch.id:  COUNT = 3
DIRECT_COMMISSION.payout_batch_id:  NULL   ← stays unstamped
```
**Decoded rule (money-critical — do NOT guess, this is the derived spec):**
- All 4 payable ledger lines become batch items carrying `line_type` + `ledger_id`.
- `payout_batch_id` is stamped on the **override + referral** ledgers (3 of them),
  but **NOT on the DIRECT_COMMISSION ledger** — "the direct base is never paid
  twice". DIRECT is settled through its own existing path (the Design-A
  per-policy flow / policy_rebates), so stamping it into the batch would
  double-count it.
- `byAgent` groups item amounts by `beneficiary_agent_id`.

> OPEN QUESTION FOR REVIEW: confirm the above DIRECT-stays-unstamped semantics
> match the real business rule (it's inferred from one test). If DIRECT *should*
> also be batch-paid going forward, the Design-A per-policy path must be retired
> in the same change — bigger blast radius. **Need your confirmation before coding.**

## Method-by-method rewrite (CommissionPayoutService)
1. **`collectLines($tenantId, $from=null, $to=null)` (NEW)** — query
   `commission_ledgers` where `status=unsettled AND payout_batch_id IS NULL AND
   payout_type IN PAYABLE_TYPES`, join policy for date filter, partition each
   line via existing `PayoutEligibility::evaluate()` → `['ready'=>[], 'waiting'=>[]]`.
   Each line: `{policyId, ledgerId, lineType, beneficiaryAgentId, sourceAgentId,
   amount, override}`.
2. **`createBatch` (REWRITE, v2 path)** — from `collectLines()['ready']`:
   one `batch_item` per ledger line with `line_type`/`ledger_id`/`source_agent_id`;
   roll up `batch_agents` grouped by `beneficiary_agent_id`; stamp
   `ledger.payout_batch_id` on override+referral lines (not DIRECT). Keep ALL
   Phase-1 guards: `vatFor()` VAT visibility, `needs_info` bank guard, maker-checker,
   and ฿0 exclusion (a 0-amount override line is dropped, mirroring current).
3. **`byAgent`/`agentDetail` (REWRITE)** — group by beneficiary from ledger lines;
   add `netAmount` (test #5) + `waitingAmount`/`ratioLabel` (P4 later).
4. **`markPaid` (EXTEND)** — set consumed ledgers `status=settled, paid_at`;
   keep existing policy_rebate + batch-agent paid writes.
5. **`calc_version` guard** — v1 batches keep the Design-A path verbatim; only new
   batches use v2. Paid V1 batches and their amounts stay frozen.

## Phase-1 money-safety — preserved explicitly
Every guard from the merged money-safety PR stays in the v2 path:
VAT `vat_amount`/`vat_expected_net`/discrepancy flag, `needs_info` bank block in
`markPaid`, ฿0 exclusion, maker-checker (`approveBatch` approver≠creator),
PayoutEligibility gate, cancel/reverse clearing paid fields, PO reference code.

## Risk & sequencing
- P1 is the one load-bearing, highest-risk unit (rewrites live payment math).
- Dual-path via `calc_version` means zero change to already-paid batches.
- The golden CSV (`tests/fixtures/payout-golden.csv`, 19 cases) is the exact-amount
  oracle for P2; P1's oracle is tests #1/#10/#11 + `byAgent` amounts.
- STOP after P1 for review before P2 (payee materialization).

## Decisions needed from reviewer before coding P1
1. **DIRECT-stays-unstamped**: confirm the seller's direct commission continues to
   pay through the existing per-policy path (NOT the batch), so the batch only adds
   the previously-unpaid upline override + referral. (Test #1 implies this.)
2. **Dual-path vs full cutover**: keep Design-A for v1 batches behind `calc_version`
   (recommended, safest), or cut fully over to ledger-driven for all new batches
   and retire Design-A per-policy in one go?
