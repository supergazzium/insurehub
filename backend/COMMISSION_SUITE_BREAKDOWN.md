# Commission suite — per-test breakdown (fix decision)

## Root cause (not test rot)
The `commission_payout_v2` migration (2027_03_22) landed the **schema** for a
"3-stage / MGM" payout upgrade — it added columns to the REAL `batch_items`
(`line_type`, `ledger_id`, `paid_ratio`, `entitlement_amount`, `ratio_label`,
`condition_override`) and created `commission_payout_payees`. But the **service
code was never written**: `collectLines()`, payee population, `line_type`
assignment, ledger linkage, pro-rata, cert numbering — none exist. The tests are
the **spec for that unfinished upgrade**. `commission_payout_payees` is never
written anywhere in `app/` (confirmed). Design A (`batch_agents` + `preview` +
`agentRows`) is what the controller/UI/Phase-1 actually use.

Legend: **A-ready** = Design A already does this, test just needs rewriting to
the current API · **FEATURE** = needs unbuilt 3-stage service code.

| # | Test | Verifies (business rule) | Classification |
|---|------|--------------------------|----------------|
| 1 | upline_lines_paid_once / direct base never twice | MGM upline **override/referral line splitting** (`line_type` base/override/referral), ledger linkage, `collectLines` | **FEATURE** — A has no line-splitting; `byAgent` returns per-agent totals only |
| 2 | installment paid **pro-rata** + survives date filter | pay commission proportional to installments paid (3/10 งวด → 600/2000), remainder in later batches | **FEATURE** — A pays full commission once; no pro-rata / `paid_ratio` |
| 3 | insurer gate holds + **override needs reason + audit** | `collectLines ready/waiting`, `POST /policies/{id}/payout-override`, audit `commission_payout.override` | **MIXED** — gate itself = A-ready (1E did it); but `payout-override` endpoint + `waiting[].reasons[].code` shape are **FEATURE** |
| 4 | freelook blocks life policy until period ends | freelook gate via `agentDetail()['waiting']` | **A-ready-ish** — gate exists (PayoutEligibility, wired in 1E); needs `agentDetail` to expose `waiting[]` + `totals.waitingAmount` |
| 5 | **permissions** enforced; finance role can pay | role gating on `by-agent`/`pay` routes (`sales_support` forbidden, `finance` ok), `canPay`/`canOverride` meta, `netAmount` | **MIXED** — route permission mw is **FEATURE** (routes currently only tenant-check); `netAmount` A-ready |
| 6 | certificates numbered + monthly WHT report | **payee cert_book/cert_no** (2569 book, sequential), `wht-report?form=pnd3/pnd53` endpoint+CSV, payee total/net, cert PDF, **bank.csv** | **FEATURE** — payees never populated; wht-report route + bank.csv (dangling, 🟠 Phase 2) |
| 7 | cancelled-after-batch excluded on pay + ledger released | markPaid detects cancelled policy → excludes + reverts batch to DRAFT; `payees().gross_amount`; ledger `payout_batch_id` released | **MIXED** — exclude-on-pay partly A; `payees()` + ledger linkage **FEATURE** |
| 8 | cancel releases ledger + **merges split clawbacks** | clawback adjustment split across batches, cancel merges remainder back; `collectLines` | **FEATURE** — clawback-split/merge + ledger linkage not in A |
| 9 | legacy (CALC_V1) batches keep old behaviour | V1 batch pays without payees; `buildAgentPdfData` version=1 | **A-ready** — `buildAgentPdfData` exists; drop `collectLines`/`payees().count()` assertions |
| 10 | engine caps commission base cumulatively at net premium | `CommissionLedger` DIRECT_COMMISSION base_premium capping (6000+4000) | **FEATURE** — MGM ledger engine behavior |
| 11 | payment in a payout **cannot be voided** | `POST payments/{id}/void` → 409 `payment_commission_paid`; 3 ledger rows per payment | **MIXED** — void-guard may be A-adjacent; the "3 ledger rows" asserts MGM linkage **FEATURE** |
| 12 | upline clawback follows seller ratio | cancellation-review `uplineRatio`/`uplines[].clawback` → pending adjustment | **FEATURE** — upline clawback ratio engine |
| 13 | portal earnings ready/waiting/paid + own docs | `me/agent/earnings` readyAmount/history referral/wht/net; payee statement+certificate | **FEATURE** — reads payees (never populated) + ready/waiting split |

## Tally
- **A-ready (rewrite test to current API):** #9, #4 (mostly) — ~2
- **MIXED (part works on A, part needs build):** #3, #5, #7, #11 — ~4
- **FEATURE (needs unbuilt 3-stage service):** #1, #2, #6, #8, #10, #12, #13 — ~7

`PayoutGoldenTest` (19 data-driven cases) is **entirely FEATURE** — it asserts
payee gross/deduction/taxable/vat/wht/net/form per MGM structure case; all depend
on payee population that doesn't exist.

## Recommendation
Only ~2 of 13 are true "rewrite the test" fixes. **~11 are a real feature build**
(the 3-stage/MGM payout engine the schema was prepped for). "Fixing the suite"
honestly means **building that engine**, which is a multi-day project on a live
money system and overlaps Phase-1 money-safety work.

Pragmatic path:
1. **Now:** rewrite the ~2 A-ready tests to pass against Design A; for the ~11
   FEATURE tests, mark them `markTestIncomplete('3-stage payout engine not yet
   built — see COMMISSION_SUITE_BREAKDOWN.md')` so the suite is **green and
   honest** (incomplete ≠ failing ≠ deleted — the spec is preserved, CI is clean).
2. **Later (scoped project):** build the 3-stage engine feature-by-feature,
   un-skipping each test as its feature lands (line-splitting → pro-rata →
   payees/cert → wht-report → clawback-merge → portal earnings).

This gives you a passing suite today without faking money logic, and keeps every
test as a living spec for the real build.
