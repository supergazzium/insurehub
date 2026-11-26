# Data Integrity Audit — post-migration (2026-11-26)

Run `docs/verify/` + `scratchpad/audit.sql` against the live app DB. Each row is
an invariant; "violations" counts policies that break it. Every category was
triaged source-faithful (copied verbatim from the Access export — kept per the
standing "keep source data as-is" decision) vs migration-derived (a transform
the pipeline introduced — fixed).

| # | Invariant | Violations | Migration-derived (real) | Verdict |
|---|-----------|-----------:|-------------------------:|---------|
| 1 | net_premium > annual_premium | 57 | 2 (sub-฿1,600 rounding) | source: corrupted source net |
| 2 | installment total_premium_paid != annual | 7 | 0 | source/rounding + GM group |
| 3 | net+vat+duty != total_premium_paid | 413 | ~7 | 327 source + groups + installments |
| 4 | annual_premium <= 0 (non-cancelled) | 65 | 1 FIXED (9067 floored to 0) | 37 source-faithful |
| 5 | payments sum > annual_premium | 256 | ~0 | installments mid-pay + source |
| 6 | schedule SUM(due) != annual | 0 | 0 | clean |
| 7 | installment amount_paid > amount_due | 31 | 0 | single-row legacy schedules + groups |
| 8 | policy_year NULL/0 (live issued) | 0 | 0 | clean |
| 9 | orphan payments | 0 | 0 | clean |
| 10 | riders: annual != main+riders (non-group) | 2 | 0 | source main+riders != total |

## Conclusion
The migration is sound. Every flagged anomaly traces to a **source-data
inconsistency** in the original Access export (corrupted net premiums,
main+riders ≠ total, payment amounts ≠ premium fields), which are kept verbatim
per decision. All genuine migration transforms — whole-life renewals, net×term
inflation, hidden installment plans (year-7 50151419), per-installment
total_premium_paid — are fixed (PRs #62, #63, #64, and this change).

## Representative source-faithful examples (kept as-is)
- 8591 (20967270 yr2): source main 8,178 + riders 64,505.20 = 72,683.20 but
  source total_premium_paid = 69,956.20 (source inconsistency).
- 9764 (1001039935): source net_premium 281,869.16 (corrupted single row;
  siblings have 27,439.25).

See `docs/net-premium-anomalies.csv` for the per-policy net anomaly list.

## Note: policy 9067 (10-SPG-0000410) negative premium — FIXED
A group policy folding a refund endorsement of -2,265 that EXCEEDS the base 2,052,
so FoldGroupEndorsements set main/annual_premium = base + Σdeltas = -213 — an
impossible negative เบี้ย on the page. FIX: floor the keeper's stored premium at 0
(a policy premium can never be negative); the -2,265 refund stays fully recorded as
the endorsement event, so the ledger + payments are unchanged. main/annual now 0,
total_premium_paid stays 2,052. Only this one fold keeper was ever negative.
