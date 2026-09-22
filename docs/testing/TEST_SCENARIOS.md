# Test Scenarios — TST cohort

Seeded by `php artisan db:seed --class=TestScenarioSeeder` (idempotent). All rows
prefixed `TST`. Existing production data untouched.

## A. Agent hierarchy (Thai names, MGM tree)
| Code | Name | Level | Parent | VAT | Status |
|---|---|---|---|---|---|
| TST-A01 | สมชาย ใจดีมั่นคง | Lv5 | — | type 1 (no VAT) | approved |
| TST-A02 | สมหญิง รักการขาย | Lv3 | A01 | type 2 (exclude) | approved |
| TST-A03 | อนุชา พากเพียรยิ่ง | Lv1 | A02 | type 3 (include) | approved (the seller) |
| TST-A04 | วิภาดา ตั้งมั่นเสมอ | Lv2 | A01 | type 1 | approved |
| TST-A05 | ธนพล รอการอนุมัติ | Lv1 | A01 | type 1 | **pending** (test approval) |

## B. Products
- TST-P-MOTOR (motor, non-life carrier)
- TST-P-NONLIFE (fire, non-life carrier)
- TST-P-LIFE (whole life, life carrier)

## C. MGM commission (fires engine via payment)
- TST01-mgm-motor → payment TST01-PAY → DIRECT + REFERRAL + DIFFERENTIAL up A03→A02→A01
- TST02-mgm-life → payment TST02-PAY
- Result: 8 commission_ledger rows (DIRECT ฿4,800 / REFERRAL ฿360 / DIFFERENTIAL ฿1,800)

## D. ติดตามงาน (follow-up) — one per category
| Policy | Category |
|---|---|
| TST-FU1-approval | สถานะรออนุมัติ (submitted) |
| TST-FU2-nopolicyno | ยังไม่บันทึกเลขกรมธรรม์ |
| TST-FU3-notdelivered | ยังไม่จัดส่ง |
| TST-FU4-freelook | Freelook (life, no end date) |
| TST-FU5-nocomm | ยังไม่บันทึกค่าคอม |
| TST-FU6-cancelled | ยกเลิก |

## E. ติดตามเงิน (collections)
- TST-COL1-cash-unpaid — cash, no payment
- TST-COL2-cash-partial — cash, ฿4,000 of ฿10,000 paid
- TST-COL3-inst-overdue — installment mode A, 6 งวด, first due 3 months ago + 1 reminder logged

## F. Commission payout
- TST-A03 eligible in a payout batch (from the MGM policies)

## G. Insurer receipt — receivables in every status
- TST-RC1-pending / RC2-matched / RC3-mismatch / RC4-received / RC5-nocomm (MAIN leg)
- Receipt batch TST-REC-0001

## Cleanup
Delete rows where code/application_no/reference/batch_no LIKE 'TST%'.
