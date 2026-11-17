# Migration Defects — from hard-copy page-by-page review (2026-11-17)

## STATUS (money batch — re-import verified)
- ✅ **D1** amount-due ignores endorsements — fold now resyncs main_premium to the
  final endorsed total + resets total_premium_paid to base + drops stale schedule.
  843: due=paid=฿4,074,796 (phantom ฿1.18M overpay gone). Remaining over/under on
  some policies are REAL payment≠premium discrepancies (left visible by decision).
- ✅ **D2** refund signs — importer flips 23 positive refunds (neg premium delta) to
  negative. Payments reconcile exactly to the D2-corrected source (฿234,491,794.27).
- ✅ **D4 (partial)** malformed endorsement date ('2205-…') crash — fold safeDate()
  clamps out-of-range years. (Default-date issue for in-range dates still open.)
- ✅ **D10** VAT carve-out — importer fills vat=total−net−stamp; installment backfill
  reconciles vat to the ANNUAL figures. net+stamp+vat=total on all but 13 edge
  cases (net>annual anomalies — left as-is, no invented negative tax).
- ✅ **D11** commission rate ÷100 scale — importer ×100 (0.0018→0.18=18%), derives
  rate from amount for the 414 ambiguous rows. 194 small-rate policies confirmed
  legit (rate×net=amount); 0 still mis-scaled.
- ⬜ D3, D5, D6, D7, D8, D9 + smaller — NOT yet done (next passes).

---

Source: user's manual review of every policy on staging. Record counts + ledger
arithmetic mostly correct; **money and date logic around them is wrong.** User
only viewed pages, changed nothing. Local DB restored to clean dump to match.

Priority: 🔴 money-correctness (payout/commission/WHT risk) · 🟠 data-integrity ·
🟡 display/logic · ⚪ cosmetic.

---

## 🔴 MONEY — fix first, affects payouts/commission/WHT

### D1. Amount-due ignores endorsements → fake overpaid / outstanding
- `PremiumDue::due()` returns base premium, not base + endorsements. Payments
  (which include endorsement top-ups) then look overpaid.
- `0222465843`: due ฿2,889,638 (base) vs paid ฿4,074,796 (correct final). The
  "overpaid ฿1,185,158" = sum of endorsements. **Same page's รวมเบี้ยที่ต้องชำระ
  already shows ฿4,074,796 — the page contradicts itself.**
- `624-01003-2`: due ฿570,342.89 but ledger ends ฿568,931; expired, shows
  ฿25,544 outstanding.
- `IA010628-25RBK` (เสถียรสเตนเลส/MTP): due ฿1,419,332.54 vs ledger ฿1,016,556.71;
  expired, shows ฿406,395.63 outstanding.
- `625-01003-1`: due ฿613,724 matches neither base (596,850) nor ledger (594,160).
- ROOT: the fold recorded endorsement events but migration never resynced
  main_premium to the final total; total_premium_paid holds the full payment sum;
  stale 1-row schedule forces the base-only branch. (Confirmed earlier this
  session.) BUT fixing due() is entangled with D2 (corrupted payment signs), so
  fix D2 first.

### D2. Refund payments imported as POSITIVE (minus sign lost) — DATA CORRUPTION
- `0222465905`: $18 (−1,370) & $21 (−427) imported as +1,370/+427.
- `625-01003-1`: $15 (−10,788) & $16 (−339) imported positive. paid ฿616,414 =
  ledger ฿594,160 + 2×฿11,127.
- `IA010628` (เสถียรสเตนเลส): $4 (−90.95) imported as +90.95.
- IMPACT: inflates paid totals, corrupts due/outstanding, would corrupt any
  payout reconciliation. **Highest-integrity bug.** Fix at importer (sign from
  the endorsement/refund type) + backfill existing rows.

### D10. VAT carved out of life premiums but VAT field = 0
- `12240006366`: net ฿19,760.76 = 21,144 ÷ 1.07 (VAT removed) but vat field 0.
- `509-1037134`: net ฿41,040 × 1.07 = ฿43,912.80.
- net + stamp + VAT ≠ total. **WHT + commission computed on a net that's too low.**
- `511-0094784`: yr1 net ฿3,735.26 vs yr2 ฿3,956.72 for the same ฿3,996.72 total.

### D11. Commission amount ≠ rate × net
- `50170797` yr4: rate 0.12 gives ฿310.68; at 12% should be ฿3,728.16. Agent
  rate 0.08 gives ฿217.48. (Policy 843 is correct: 0.18 → 18% of net.)
- Likely the same too-low net from D10, or amount computed off the wrong base.

---

## 🟠 DATA-INTEGRITY — wrong values imported

### D3. Payments linked to wrong endorsement ($N)
- `0222465843`: base ฿2,889,638 tagged $25; 24 of 29 payments tagged $25; the
  −24,917 refund tagged $27 belongs to $25.
- `0222465905`: all 23 payments tagged $21 incl. the base.

### D4. Endorsement effective dates = default, not real
- Refunds dated on policy start: 843 $25 = 01/03/2026; 905 six refunds 01/12/2025;
  625 $7/$15/$16 = 25/08/2025.
- Others dated migration day 10/11/2026: 905 $5/$10/$22; 624 $8/$12/$13 (after
  624 expired 25/08/2025).
- Ledger ends up out of date order. 843 $1 (11/09/2025) & $2 (19/09/2025) fall
  before yr3 coverage start (01/03/2026) → look like yr2.

### D5. Empty ฿0.00 endorsements inflate the count
- 843 $29, 905 $23, 625 $14, 624 $12 are ฿0 changes. So 843 is base + 28 real
  changes, not 29.

### D6. Refunds labelled "เบี้ยเพิ่ม" (increase) in the type column
- 624 $7 shows +1,430 even though total drops 568,893 → 567,463.

### D7. Installment schedules imported as PAID with FUTURE dates → fake "ยอดค้าง ฿0"
- Rows marked ข้อมูลระบบเดิม dated AFTER today (17/11/2026):
  - `12240006366` yr3: 20/11/2026, 20/12/2026, 20/01/2027
  - `509-1037134` yr6: 19/11/2026, 19/02/2027, 19/05/2027
  - `50170797` yr4: 13/12/2026, 13/01/2027, 13/02/2027
- So "ชำระครบแล้ว ฿0" on these is NOT real — future installments pre-recorded as
  paid. (My earlier "verified fully-paid" was WRONG because of this.)

---

## 🟡 DISPLAY / LOGIC

### D8. Coverage periods wrong (overlaps / gaps / wrong spans)
- `12240006366`: yr2 20/02/2024→20/02/2026 (2yr, overlaps yr1); yr3 23/02/2026
  (3-day gap) → 20/02/2027.
- `511-0094784`: yr1 17/10/2025→17/04/2026 (6mo) but first payment 17/04/2025;
  yr2 06/05/2026→17/10/2027 (~18mo).
- `509-1036235`: yr7 starts 2026-06-29 but anniversary 06-09 → 20-day gap.
- `50170797`: yr4 starts 2026-02-26, overlaps yr3 by 15 days.
- `509-1037134`: yr6 ends 19/08/2027 instead of 24/08/2027.

### D9. Status wrong — expired policies show คุ้มครองอยู่
- All 7 years of `509-1036235` and all 4 of `50170797` show in-force, incl
  2020–2025 years that ended.
- `IA010628` rows ending 2026-04-01 show in-force.
- เสถียรสเตนเลส: list says คุ้มครองอยู่, detail says หมดอายุ (list vs detail disagree).

---

## ⚪ SMALLER
- Installment badge: list shows "1/12" on fully-paid years of 12240006366;
  detail shows "1/1".
- ลูกค้าปีที่ (customer year) jumps: 12240006366 shows 3,3,5 for policy yrs 1,2,3.
- Co-insurance `IA010628-25RBK` is NOT "3 customers × 2 carriers": it's 4
  customers, เสถียรสเตนเลส is MTP only, one TIP row (เอสซีพี พัฒนา) is ฿0/cancelled,
  เสถียรสเตนเลส sum-insured = 0. (My earlier description was wrong.)
- Refunds show as "โอนเงิน" not marked endorsement adjustment (905).

---

## ✅ CONFIRMED CORRECT (per user)
- 843: 1 result, 29 active payments, ledger before→after adds up.
- 509-1036235 → 7 results; 50170797 → 4 results.
- 12240006366 yr1: 12×฿1,979 = ฿23,748, nothing outstanding, ชำระครบแล้ว (clean).
