# Commission End-to-End Test Set (E2E cohort)

Seed: `php artisan db:seed --class=CommissionE2ESeeder`
(requires the TST agent line — run `TestScenarioSeeder` first if missing)

## The agent line (differential-ready — 3 levels, one สายงาน)
```
สมชาย ใจดีมั่นคง  (TST-A01, Lv5)   ← top
 └─ สมหญิง รักการขาย (TST-A02, Lv3)
     └─ อนุชา พากเพียรยิ่ง (TST-A03, Lv1)  ← the seller for both E2E policies
```

## 2 products + 2 policies (both sold by อนุชา Lv1 → full cascade)
| Product | Policy | เบี้ย | hub→agent rate | carrier→hub |
|---|---|---|---|---|
| E2E-P-MOTOR (motor) | E2E-POL-MOTOR | ฿20,000 | 10% | ฿4,000 |
| E2E-P-FIRE (non-life) | E2E-POL-FIRE | ฿30,000 | 12% | ฿6,600 |

## Commission produced (E2E-POL-MOTOR, ฿20,000)
| Type | Agent | Rate | Amount | Why |
|---|---|---|---|---|
| DIRECT | อนุชา Lv1 | 10% | ฿2,000 | seller base rate |
| REFERRAL | สมหญิง Lv3 | 1% | ฿200 | direct upline referral fee |
| DIFFERENTIAL | สมหญิง Lv3 | 4% | ฿800 | Lv3 mgmt 4% − Lv1 passed 0% |
| DIFFERENTIAL | สมชาย Lv5 | 1% | ฿200 | Lv5 mgmt 5% − Lv3 passed 4% |

(E2E-POL-FIRE is the same shape at 12%: DIRECT ฿3,600 / REFERRAL ฿300 / DIFF ฿1,200 + ฿300.)

## How to test each stage end-to-end
1. **See the cascade** — open อนุชา's agent detail → commission-detail, or query commission_ledgers.
2. **รับค่าคอม (บ.ประกัน→InsureHub)** — /commissions/receipts, pick Policy Year 1 + the E2E carrier → the E2E policies show carrier→hub expected (฿4,000 / ฿6,600); record received.
3. **ทำจ่าย (InsureHub→agent)** — /commissions/payouts → create a batch covering these dates → อนุชา's hub→agent amounts appear → approve → mark paid.

## Cleanup
Delete rows where code / application_no / reference LIKE 'E2E%'.
