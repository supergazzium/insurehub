# งาน 4 — กรมธรรม์: สลักหลัง (เลขลำดับ $1 $2 $3 + ป้าย + ประวัติ)

ที่มา: ClickUp `z8qu5d0quz` (ซับทาสก์ 4.1 `z8qu5d0qvy`, 4.2 `z8qu5d0qvz`) · ทำเมื่อ 2026-10-03 · เทียบกับโค้ดก่อนเริ่มงาน 4 (snapshot `baseline3`)

> "ถ้ากรมธรรม์มีการสลักหลัง ช่องที่บอกปีจะเปลี่ยนเป็น $1 $2 $3 ไปเรื่อยๆ"

## 1. สรุป

| ซับทาสก์ | ผลลัพธ์ |
|---|---|
| 4.1 เลขลำดับสลักหลังเก็บใน DB | `policy_events.endorsement_seq` + `policies.endorsement_count` · จองเลขใน transaction + `lockForUpdate` (`EndorsementRecorder`) · backfill รายการเดิมตามลำดับเวลา · ไม่มีการลบจริงอีก ใช้ "ยกเลิกรายการ" แทน เลขไม่ถูกใช้ซ้ำ |
| 4.2 ป้าย + ประวัติเรียงลำดับ | ป้าย `1# $n k/N` ใช้เลขล่าสุดที่ยังไม่ยกเลิก (หัวกรมธรรม์, รายการ, งวด, การชำระ, ติดตามเงิน, คิวต่ออายุ) · ตารางประวัติ `$1..$n` ทุกประเภท พร้อมประเภท/ผู้บันทึก/สถานะยกเลิก · modal แสดง "$n · สลักหลังครั้งที่ n" และเลือกประเภทได้ |

**การตัดสินใจทางธุรกิจ (ยืนยันกับผู้ใช้แล้ว):**
- ทุกประเภทนับเลข: เบี้ยเพิ่ม / เปลี่ยนความคุ้มครอง / เปลี่ยนวันที่คุ้มครอง / ยกเลิกและออกใหม่ / แก้ไขข้อมูลอื่น
- ต่ออายุแล้วเริ่มนับใหม่ (ปีใหม่ = กรมธรรม์ใหม่ เริ่ม `$1`)
- ไม่ลบจริง → ยกเลิกพร้อมเหตุผล แถวยังอยู่แบบขีดฆ่า เลขไม่ใช้ซ้ำ ครั้งถัดไปได้เลขใหม่ ถ้าเป็นเบี้ยเพิ่ม เบี้ยกลับไปตามรายการเบี้ยเพิ่มล่าสุดที่ยังใช้อยู่
- ประเภทอื่นบันทึกได้จากฟอร์มเดิม: วันที่มีผล + เหตุผล + รายละเอียด (ไม่เปลี่ยนเบี้ย)

## 2. ปัญหาที่พบก่อนแก้ (ถ่ายด้วย claude-in-chrome)

| # | ปัญหา | สาเหตุ |
|---|---|---|
| B1 | กรมธรรม์ `TSTRNS3` ป้ายหัวบอก `1# $2` แต่ส่วน "สลักหลัง" ขึ้น "ยังไม่มีรายการสลักหลัง" | ป้ายนับทุก `endorsement.*` แต่ตารางกรองเฉพาะ `premium_change` |
| B2 | ฟอร์มสลักหลังทำได้แค่ "เบี้ยเพิ่ม" ไม่บอกว่าเป็นครั้งที่เท่าไร | API แบบทั่วไปมีแต่ไม่มีหน้าจอเรียก · ไม่มีเลขลำดับ |
| B3 | สลักหลัง 2 ครั้ง (`$2`) → ลบครั้งแรก → ป้ายเลื่อนเป็น `$1` รายการที่เคยเป็นครั้งที่ 2 กลายเป็นครั้งที่ 1 แถวที่ลบหายไปจากประวัติ ส่วน "เบี้ยเดิม" ของแถวที่เหลือยังอ้างถึงรายการที่หายไป | `$n` = `COUNT(*)` สด · `destroyPremiumChange` ลบแถวจริง |

**ปัญหาเพิ่มเติมที่พบระหว่างทำ (แก้แล้ว):**
- ไม่มีการล็อกตอนบันทึกสลักหลัง → ถ้าบันทึกพร้อมกันจะได้เลขซ้ำ (แก้ด้วย `lockForUpdate` + unique `(policy_id, endorsement_seq)`)
- wizard อ่าน validation error แบบ axios (`err.response.data.errors`) ซึ่งไม่มีใน API client ของโปรเจกต์ → error จากเซิร์ฟเวอร์ไม่เคยแสดงใน modal (แก้ให้อ่าน `ApiError.body.errors`)
- ช่องวันที่ใน modal เป็น `<input type="date">` ของ browser (แสดงตาม locale เครื่อง) → เปลี่ยนเป็น `DateInput` แบบเดียวกับทั้งแอป (dd/mm/yyyy)
- **`DateInput` (แก้ในงาน 2) ส่งค่าเป็น `dd/mm/yyyy` แทน `yyyy-mm-dd`**: vue-datepicker v14 ถ้า `formats.input` เป็นฟังก์ชันจะใช้ฟังก์ชันนั้นสร้างค่า model ด้วยและไม่สนใจ `model-type` → แก้เป็น pattern string `'dd/MM/yyyy'` ตอนนี้แสดง `01/10/2026` และเก็บ `2026-10-01` (ตรวจใน Chrome แล้ว) · ช่วงที่เป็นบั๊กไม่มีการบันทึกวันที่ผ่านหน้าจอใน dev DB

## 3. การไหลของข้อมูล

```
ทำสลักหลัง (modal: ประเภท + "$n · ครั้งที่ n" จาก meta.nextSeq)
   ├─ เบี้ยเพิ่ม  → POST /policies/{id}/endorsements/premium
   └─ ประเภทอื่น → POST /policies/{id}/endorsements {type, reason, effectiveDate, detail}
        ▼
EndorsementRecorder::record  (DB::transaction)
   lockForUpdate policies → seq = endorsement_count + 1 → update count
   → (เบี้ยเพิ่ม) แก้ annual/main premium + coverage → PolicyObserver วางงวดที่ยังไม่จ่ายใหม่
   → policy_events {type, endorsement_seq: seq, payload, by_user_id}
        ▼
PolicyPeriodLabel::endorsementNo = MAX(endorsement_seq) ที่ voided_at IS NULL (as-of วันที่ของงวด/การชำระ)
   → periodLabel ทุกจุด: หัวกรมธรรม์ / รายการ / งวด / การชำระ / ติดตามเงิน / คิวต่ออายุ + PDF

ยกเลิกรายการ → POST /policies/{id}/endorsements/{e}/void {reason}   ← policies.update
   voided_at / voided_by / void_reason → (เบี้ยเพิ่ม) resyncPremium: after ของเบี้ยเพิ่มล่าสุดที่ยังใช้อยู่
   หรือ before ของรายการแรกถ้ายกเลิกหมด · endorsementPremiumTotal ไม่นับรายการที่ยกเลิก

ต่ออายุ → RenewalBlueprint: endorsement_count = 0 → ปีใหม่เริ่ม $1
```

## 4. API ที่เพิ่ม/เปลี่ยน

| Endpoint | การเปลี่ยนแปลง |
|---|---|
| `GET /policies/{id}/endorsements` | เรียงตาม `seq` · แถวมี `seq, type, occurredAt, byUserId, byUserName, payload, voidedAt, voidedByName, voidReason` · `meta.nextSeq` |
| `POST /policies/{id}/endorsements` | ได้เลขลำดับ · รับ `detail` · ห้ามบนร่าง (422) · คืนแถวแบบเดียวกับ index |
| `POST /policies/{id}/endorsements/premium` | ได้เลขลำดับ (lock) · คืนแถวแบบเดียวกับ index |
| `PATCH /policies/{id}/endorsements/premium/{e}` | ต้องมีสิทธิ์ `policies.update` · ห้ามแก้รายการที่ยกเลิก (422 `endorsement_voided`) · เลขไม่เปลี่ยน |
| `PATCH /policies/{id}/endorsements/{e}` **(ใหม่)** | แก้เหตุผล/วันที่/รายละเอียดของประเภททั่วไป · `policies.update` |
| `POST /policies/{id}/endorsements/{e}/void` **(ใหม่)** | ยกเลิกรายการพร้อมเหตุผล · `policies.update` |
| ~~`DELETE /policies/{id}/endorsements/premium/{e}`~~ | **เอาออก** (ไม่มีการลบจริงอีก) |

ฐานข้อมูล: migration `2027_03_17_000100_add_endorsement_sequence`
- `policies.endorsement_count` (smallint, default 0)
- `policy_events.endorsement_seq`, `voided_at`, `voided_by_user_id`, `void_reason` + unique `(policy_id, endorsement_seq)`
- backfill ในตัว: dev DB ได้ `TSTRNS3` = $1, $2 และ `A2605270007` = $1

**สิทธิ์:** การบันทึกสลักหลังยังเปิดเหมือนเดิม · การแก้ไขและยกเลิกต้องมี `policies.update` (admin/super_admin ผ่านอยู่แล้ว role อื่นต้องเปิดที่ `/admin/roles`)

## 5. ฝั่งหน้าจอ

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `pages/policies/PolicyEndorsementModal.vue` | ตัวเลือกประเภท 5 แบบ · badge "$n · สลักหลังครั้งที่ n" · ประเภททั่วไปมีแค่วันที่มีผล/เหตุผล/รายละเอียด · ใช้ `DateInput` |
| `pages/policies/PolicyApplicationWizard.vue` | ตารางประวัติทุกประเภทเรียง `$1..$n` + ประเภท + ผู้บันทึก · แถวยกเลิกขีดฆ่า + badge + เหตุผล · ปุ่ม "ทำสลักหลัง $n" · dialog ยกเลิกรายการ (ไม่ใช้ `window.confirm`) · ปุ่มแก้/ยกเลิกตามสิทธิ์ · อ่าน error จาก `ApiError` |
| `api/endorsements.ts` | type ใหม่ + `updateEndorsement`, `voidEndorsement`, `ENDORSEMENT_TYPES` · เอา `deletePremiumEndorsement` ออก |
| `components/DateInput.vue` | `formats.input` เป็น pattern string (แก้บั๊กค่า model) |
| `i18n/th.ts`, `i18n/en.ts` | `endorsement.types.*`, `void*`, `col.seq/type/recordedBy`, `detail`, `generalSubtitle` |
| `components/PolicyPeriodBadge.vue` | ไม่ต้องแก้ (แยก `$n` ได้อยู่แล้ว) |

## 6. การทดสอบ

```bash
DB_CONNECTION=mysql DB_DATABASE=insurehub_testing DB_USERNAME=root DB_PASSWORD= php artisan test
npm run build
```

`tests/Feature/Policy/EndorsementSequenceTest.php` — 8 เทสต์:
- ทุกประเภทได้เลขถัดไป (1,2,3) · `endorsement_count = 3` · ป้ายหัวกรมธรรม์และหน้ารายการเป็น `1# $3 1/1` · มีชื่อผู้บันทึก
- ยกเลิก `$3` → ป้ายกลับ `$2` · ครั้งใหม่ได้ `$4` · ประวัติยังมี 4 แถว (แถว 3 ยกเลิก) · `meta.nextSeq = 5`
- ยกเลิกเบี้ยเพิ่ม → เบี้ยกลับเป็นของรายการก่อนหน้า / ค่าเดิมทั้ง `annual_premium` และ `main_premium` · `endorsementPremiumTotal` ไม่นับรายการยกเลิก
- แก้ไขไม่เปลี่ยนเลข · แก้/ยกเลิกรายการที่ยกเลิกแล้ว → 422 · ยกเลิกต้องมีเหตุผล
- role ที่ไม่มี `policies.update` แก้/ยกเลิกไม่ได้ (403) แต่บันทึกได้ · ให้สิทธิ์แล้วทำได้
- ร่างกรมธรรม์บันทึกสลักหลังไม่ได้ (422) · ข้าม tenant/กรมธรรม์ → 404
- ต่ออายุแล้วเริ่มนับใหม่ (`2# 1/1` → `2# $1 1/1`)
- ป้ายของงวดใช้เลขที่มีผล ณ วันครบกำหนด และไม่นับรายการยกเลิก

ผล: ชุดใหม่ + `RenewalFlowTest` **25/25 ผ่าน** · ทั้งชุด **91/92** (ตัวที่ไม่ผ่านคือ `CommissionSnapshotTest::test_rate_row_newest_effective_from_wins` ซึ่งไม่ผ่านมาตั้งแต่ก่อนเริ่มงาน) · `npm run build` ผ่าน

ทดสอบใน Chrome (กรมธรรม์ `A2605270007`): `$1` เบี้ยเพิ่ม → บันทึก "แก้ไขข้อมูลอื่น" ได้ `$2` → เบี้ยเพิ่ม 10,200 → 11,000 ได้ `$3` → ยกเลิก `$3` ป้ายกลับ `$2` เบี้ยกลับ 10,200 → บันทึกใหม่ได้ `$4` หน้ารายการแสดง `1# $4 1/1`

## 7. ภาพก่อน / หลัง

อยู่ที่ `docs/tasks/task-4-endorsement/screenshots/` (ถ่ายด้วย claude-in-chrome)

| เรื่อง | ก่อน | หลัง |
|---|---|---|
| ประวัติไม่ตรงกับป้าย | `before-B1a-endorsement-section-empty.png`, `before-B1b-header-label-says-2.png` | `after-A1a-s3-history-shows-all-types.png` |
| ฟอร์ม | `before-B2-modal-premium-only-no-sequence.png` | `after-A2a-modal-type-picker-seq-2.png` |
| ประวัติเรียงลำดับ | `before-B3b-two-endorsements-no-sequence.png` | `after-A1b-history-1-2-3-with-recorder.png` |
| ลบแล้วเลขเลื่อน | `before-B3a-label-2-before-delete.png` → `before-B3c-label-shifts-to-1-after-delete.png`, `before-B3d-history-row-gone-after-delete.png` | `after-A3a-void-dialog.png` → `after-A3b-voided-row-kept-next-is-4.png`, `after-A3c-label-back-to-2.png`, `after-A3d-policy-list-label-4.png` |
| ขั้นตอนทั้งหมด | — | GIF `after-A3-endorsement-sequence-flow.gif` |

## 8. ข้อมูลทดสอบใน dev DB

- `A2605270007` (policy 467): ภาพ "ก่อน" สร้างและลบสลักหลังเบี้ยเพิ่มผ่าน API (ข้อความไทยรอบแรกส่งผิด encoding จึงลบทิ้ง) เหลือ "ต่อเติมโรงจอดรถ" → backfill เป็น `$1` · ภาพ "หลัง" เพิ่ม `$2` แก้ไขข้อมูลอื่น, `$3` เบี้ยเพิ่ม (ยกเลิกแล้ว), `$4` เปลี่ยนความคุ้มครอง · เบี้ยปัจจุบัน 10,200 (เดิม 8,880 ก่อนเริ่มทดสอบ)
- `TSTRNS3`: `RenewalScenarioSeeder` เพิ่มเหตุผลและเลขลำดับให้สลักหลัง 2 รายการ

## 9. ข้อจำกัด / งานต่อ

- "as-of" ของป้ายงวดยังเทียบกับวันที่บันทึก (`occurred_at`) ไม่ใช่ "วันที่มีผล" ของสลักหลัง (เหมือนเดิมจากงาน 3)
- ประเภทเปลี่ยนวันที่/ความคุ้มครองบันทึกเป็นประวัติอย่างเดียว ยังไม่แก้ข้อมูลกรมธรรม์ให้อัตโนมัติ (ตามที่ตกลง)
- ยังไม่มีไฟล์แนบเอกสารสลักหลังต่อรายการ (มีประเภทเอกสาร `endorsement` ในเอกสารกรมธรรม์อยู่แล้ว)
