# งาน 5 — ต่ออายุ Renew: filter, รายละเอียด, ใบเตือน และ note ติดตาม

ที่มา: ClickUp `z8qu5d0qv0` (ซับทาสก์ 5.1 `z8qu5d0qw0`, 5.2 `z8qu5d0qw1`, 5.3 `z8qu5d0qw2`, 5.4 `z8qu5d0qw3`, 5.5 `z8qu5d0qw4`) · ทำเมื่อ 2026-10-04 · เทียบกับโค้ดก่อนเริ่มงาน 5 (snapshot `baseline4`)

> filter รหัสตัวแทน + filter ผลิตภัณฑ์ตาม category · แสดงชื่อผลิตภัณฑ์ใต้ทะเบียน (สีเทา) · ดูรายละเอียดกรมธรรม์ปีที่แล้ว · แนบใบเตือนจากบริษัท → กรอกราคา → สร้างใบเตือนแบบ InsureHub · ช่อง note ผลการติดตาม

## 1. สรุป

| ซับทาสก์ | ผลลัพธ์ |
|---|---|
| 5.1 filter ผลิตภัณฑ์ตาม category | ตัวกรองเดิมเป็นรายการฮาร์ดโค้ดเทียบ `products.type` (มีแค่ life/motor/non_life) → เปลี่ยนเป็น **หมวดผลิตภัณฑ์ → หมวดย่อย** จากตาราง `product_taxonomy` เทียบชื่อหมวดของสินค้า · มีตัวเลือก "ยังไม่ระบุหมวดย่อย" · หน้าผลิตภัณฑ์มีแถบรายงานสินค้าที่ยังไม่ผูกหมวดย่อย (400/895) + ปุ่มกรองเฉพาะรายการเหล่านั้น · filter ตัวแทนส่งไปถึง PDF แล้ว |
| 5.2 ชื่อผลิตภัณฑ์ใต้ทะเบียน | คอลัมน์ "ทะเบียน / ผลิตภัณฑ์": ทะเบียนตัวหนา + ชื่อสินค้าสีเทา (ไม่มีทะเบียนแสดงชื่อสินค้า + หมวดย่อยสีเทา) ทั้งตาราง บอร์ด CSV และ PDF |
| 5.3 รายละเอียดปีที่แล้ว + เทียบใบเตือน | การ์ด "กรมธรรม์เดิม (ปีที่กำลังต่ออายุ)": บริษัท สินค้า+หมวด ทะเบียน/รถ ระยะเวลา ทุน การชำระ เบี้ยสุทธิ/อากร/VAT/รวม ส่วนลด ประวัติสลักหลัง `$n` ไฟล์กรมธรรม์ · การ์ด "เทียบกับใบเตือนต่ออายุ" แสดงปีนี้ ↔ ใบเตือน + ส่วนต่างและ % (เบี้ยเพิ่ม/ส่วนลดลด = แดง, ทุนเพิ่ม = เขียว) |
| 5.4 note ติดตาม | ตาราง `renewal_followups`: ช่องทาง ผล (ติดต่อไม่ได้/สนใจ/ขอคิดก่อน/ไม่ต่อ) เหตุผล note นัดครั้งถัดไป · ประวัติแบบ thread · คิวต่ออายุมีคอลัมน์ติดตามล่าสุด + chip/การ์ด "ถึงกำหนดติดตาม (n)" · "ไม่ต่อ" ปิดเป็นลูกค้าปฏิเสธทันทีและเปิดติดตามใหม่ได้ |
| 5.5 ใบเตือน InsureHub (UAT) | ตาราง `renewal_notices` เลขที่ `RN{ปี พ.ศ.}-{00001}` เรียงต่อ tenant · PDF ใหม่ "ใบเตือนต่ออายุกรมธรรม์" ใช้ข้อมูลบริษัทจริงจากตั้งค่าหน่วยงาน แยก net/อากร/VAT มีวันครบกำหนดชำระ ช่องทางชำระเงิน และเทียบปีปัจจุบัน · **รอฝ่ายปฏิบัติการ UAT ตาม [`uat-checklist.md`](task-5-renewal/uat-checklist.md)** |

**การตัดสินใจทางธุรกิจ (ยืนยันกับผู้ใช้แล้ว):**
- 5.1 กรองจากชื่อหมวด/หมวดย่อยของสินค้า (ตัวเลือกจาก `product_taxonomy`) + รายงานสินค้าที่ยังไม่ผูก · ไม่ย้ายโครงสร้างสินค้าไปใช้ FK
- 5.4 ผล "ไม่ต่อ" → สถานะ "ลูกค้าปฏิเสธ" ทันที + ปุ่ม "เปิดติดตามใหม่"
- 5.4 เหตุผลไม่ต่อเป็นรายการคงที่ + "อื่นๆ" (ต้องพิมพ์รายละเอียด)
- 5.5 ช่องทางชำระเงินตั้งค่าที่หน่วยงาน (ตั้งค่าหน่วยงาน → ข้อมูลหน่วยงาน)

## 2. ปัญหาที่พบก่อนแก้ (ถ่ายด้วย claude-in-chrome)

| # | ปัญหา | สาเหตุ |
|---|---|---|
| B1 | เลือกประเภท "Motor" ได้ 169 แถวที่มีอัคคีภัย/เบ็ดเตล็ดปน · เลือก Non-Motor / PA / Rider ได้ 0 แถว | ตัวเลือกฮาร์ดโค้ดเทียบ `products.type` ซึ่งข้อมูลจริงมีแค่ `life/motor/non_life` |
| B2 | แถว/บอร์ด/CSV/PDF ไม่มีชื่อสินค้าและทะเบียน · PDF ไม่กรองตามตัวแทน และ chip แสดง id ดิบ · CSV ตัดที่ 500 แถว | API ส่ง `productName`/`motorLicenseNo` แต่หน้าจอไม่ใช้ · ไม่ส่ง `writingAgentId` ให้ PDF |
| B3 | หน้ารายละเอียดต่ออายุมีแค่ชื่อสินค้า/บริษัท/ทุน/เบี้ยรวม · timeline มีแต่ชื่อ event | ไม่มีส่วนแสดงกรมธรรม์เดิมและไม่มีการเทียบกับใบเตือน |
| B4 | "บันทึกการติดต่อ" เป็น note 500 ตัวอักษร ไม่มีผล/นัดครั้งถัดไป · "ลูกค้าปฏิเสธ" พิมพ์เหตุผลอิสระ ย้อนกลับไม่ได้ | ไม่มีตารางติดตาม · สถานะ declined เป็นทางเดียว |
| B5 | ฟอร์มเตรียมใบเตือน ทุนประกัน prefill 0 · PDF หัว "ใบเสนอราคา" ข้อมูลบริษัทฮาร์ดโค้ด (`02-000-0000`) ไม่แยก net/อากร/VAT ไม่มีวันครบกำหนด/ช่องทางชำระ เลขที่สุ่มไม่เก็บ | ตัวเลขเก็บแค่ใน event payload · `useQuotationPdf` ใช้ค่าคงที่ |

**ปัญหาเพิ่มเติมที่พบระหว่างทำ (แก้แล้ว):**
- แท็บ "ข้อมูลหน่วยงาน" ในตั้งค่าหน่วยงานเป็น mock (ไม่โหลด/ไม่บันทึกจริง) ทั้งที่ `GET/PATCH /tenant` รองรับครบ → โหลด/บันทึกจริง + ช่อง "ช่องทางชำระเงิน"
- วันเวลาที่แสดงปนกัน: ส่วนวันที่ตัดจาก string UTC แต่ชั่วโมงเป็นเวลาเครื่อง (แอปเก็บเวลาเป็น UTC) → ช่วง 00:00–07:00 วันที่ผิดไป 1 วัน · เพิ่ม `fmtDateTime` (แปลงเป็นเวลาเครื่องทั้งหมด) ใช้ในหน้าต่ออายุ **และหน้าแก้ไขลูกค้า (งาน 2)**
- PDF ใบเตือนรอบแรกล้นเป็น 2 หน้า → ปรับระยะ + `renderNodeToPdfBlob(node, fitTolerance)` ย่อให้พอดี 1 หน้าเมื่อเกินไม่เกิน 25%

## 3. การไหลของข้อมูล

```
คิวต่ออายุ (ExpiringSoon)
   หมวดผลิตภัณฑ์ / หมวดย่อย (product_taxonomy) + ตัวแทน + chip "ถึงกำหนดติดตาม"
        ▼
GET /reports/expiring-soon?productCategory=&productSubCategory=&writingAgentId=&followupDue=1
   pr.category = ? / pr.sub_category = ? (__none = ว่างหรือไม่อยู่ใน taxonomy)
   join follow-up ล่าสุด (lf) → lastFollowup · followupDue: lf.next_followup_at <= วันนี้ และยังไม่ต่ออายุ (ไม่ขึ้นกับช่วงวันหมดอายุ)
   summary.followupDueCount · PDF ใช้ query เดียวกัน (chip แสดงชื่อแทน id)

บันทึกการติดตาม (RenewalDetail → แผง "บันทึกการติดตาม")
   POST /policies/{id}/renewal-followups {channel, result, reasonCode?, reasonNote?, note?, nextFollowupAt?}
        ├─ renewal_followups (1 แถว)
        ├─ policy_events: renewalContacted {followupId, channel, result}
        └─ result = not_renewing → policy_events: renewalDeclined {reasonCode, reasonLabel, followupId}
   POST /policies/{id}/renewal/reopen → renewalReopened
   stage = declined เฉพาะเมื่อ id ของ renewalDeclined ล่าสุด > id ของ renewalReopened ล่าสุด

จัดทำใบเตือน (แผง "จัดทำใบเตือนต่ออายุ InsureHub")
   เบี้ยรวม → แยก net/อากร/VAT อัตโนมัติตามสูตร (แก้เองได้) · ส่วนลด · ทุน (prefill จากกรมธรรม์) · วันครบกำหนด (= วันหมดอายุ) · ใช้ได้ถึง · หมายเหตุ
        ▼
POST /policies/{id}/renewal-notices  → lock แถว tenant → RN{พ.ศ.}-{ลำดับ} · ตรวจ net+อากร+VAT ≈ เบี้ยรวม
        ▼
renderRenewalNoticePdf (GET /tenant: โลโก้ ชื่อ ที่อยู่ โทร เลขผู้เสียภาษี ใบอนุญาต คปภ. ช่องทางชำระ)
        ▼
POST /policies/{id}/documents/upload {type: renewal_quote_insurehub, renewalNoticeId}
   renewal_notices.document_id = เอกสาร · event renewalQuotePrepared.quote {net, duty, vat, discount, dueDate, noticeNo}
        ▼
การ์ด "เทียบกับใบเตือนต่ออายุ" (ใบเตือนล่าสุด ↔ กรมธรรม์ปีนี้)
RenewalBlueprint::latestQuote → ร่างปีถัดไปใช้ net/อากร/VAT ของใบเตือน (ไม่ต้องคำนวณเบี้ยใหม่)
```

## 4. API / ฐานข้อมูล

| Endpoint | การเปลี่ยนแปลง |
|---|---|
| `GET /reports/expiring-soon` | ตัวกรอง `productCategory`, `productSubCategory` (`__none`), `followupDue` · แถวเพิ่ม `productCategory`, `productSubCategory`, `motorVehicle`, `lastFollowup {at, channel, result, reasonCode, note, nextAt, byName}` · `summary.followupDueCount` · ยังรับ `productType` แบบเดิม |
| `GET /reports/expiring-soon/pdf` | รับตัวกรองชุดเดียวกัน (รวม `writingAgentId`) · คอลัมน์ "ทะเบียน / ผลิตภัณฑ์" + "ติดตามล่าสุด" · chip แสดงชื่อ |
| `GET/POST /policies/{id}/renewal-followups` **(ใหม่)** | thread ใหม่สุดก่อน + ชื่อผู้บันทึก · `meta.declined`, `meta.reasons` · validation: ไม่ต่อต้องมีเหตุผล, `other` ต้องมีรายละเอียด, นัด ≥ วันนี้ และห้ามนัดเมื่อไม่ต่อ |
| `POST /policies/{id}/renewal/reopen` **(ใหม่)** | เปิดติดตามใหม่ · ถ้ายังไม่ถูกปฏิเสธ → 422 `renewal_not_declined` |
| `GET/POST /policies/{id}/renewal-notices` **(ใหม่)** | ออกเลขที่ใบเตือน · `dueDate` ค่าเริ่มต้น = วันหมดอายุ · ผูกเอกสารใบเสนอราคาบริษัทล่าสุดอัตโนมัติ |
| `POST /policies/{id}/documents/upload` | รับ `renewalNoticeId` (ไม่พบ → 422) |
| `GET /policies/{id}/renewal-draft` | `meta.quote` มี net/อากร/VAT/ส่วนลด/เลขที่ · `needsPremiumFormula = false` เมื่อใบเตือนมี net |
| `GET /products?taxonomy=unmatched`, `GET /products/taxonomy-gaps` **(ใหม่)** | สินค้าที่หมวด/หมวดย่อยไม่ตรงแถวใดใน taxonomy + สรุปจำนวนตาม (หมวด, หมวดย่อย) |
| `GET /product-taxonomy` | ส่ง `id` เพิ่ม |
| `GET/PATCH /tenant` | ฟิลด์ `paymentChannels` |
| `GET /policies/{id}` | `productCode`, `productName`, `productCategory`, `productSubCategory`, `carrierCode`, `carrierName` |

ทุก endpoint ใหม่อยู่ในกลุ่ม route เดียวกับปุ่มต่ออายุเดิม (ต้องล็อกอิน + แยก tenant)

ฐานข้อมูล:
- `2027_03_18_000100_create_renewal_followups` — `tenant_id, policy_id, channel, result, reason_code, reason_note, note, next_followup_at, created_by_user_id` + index `(tenant_id, policy_id)`, `(tenant_id, next_followup_at)`
- `2027_03_18_000200_create_renewal_notices` — `notice_no` (unique ต่อ tenant), `annual_premium, net_premium, duty_stamp, vat, discount, coverage, premium_mode, due_date, valid_until, note, carrier_document_id, document_id, created_by_user_id` + `tenants.payment_channels`

ค่าคงที่ (`App\Support\RenewalFollowUpOptions`):
- ช่องทาง: โทรศัพท์ / LINE / อีเมล / พบตัว / อื่นๆ
- ผล: ติดต่อไม่ได้ / สนใจต่ออายุ / ขอคิดก่อน / ไม่ต่อ
- เหตุผลไม่ต่อ: เบี้ยแพงเกินไป · ได้ข้อเสนอที่ดีกว่า · ย้ายไปบริษัทอื่น · ขายรถ/ทรัพย์สินแล้ว · ไม่ต้องการความคุ้มครองแล้ว · ติดต่อไม่ได้เป็นเวลานาน · ไม่พอใจบริการ · อื่นๆ

## 5. ฝั่งหน้าจอ

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `pages/policies/ExpiringSoon.vue` | select หมวด → หมวดย่อย · คอลัมน์ทะเบียน/ผลิตภัณฑ์ + ติดตามล่าสุด (นัดเลยกำหนดเป็นสีแดง) · chip + การ์ด "ถึงกำหนดติดตาม" · บอร์ดแสดงสินค้าและนัดถัดไป · CSV ดึงทุกหน้า + คอลัมน์ใหม่ · PDF ส่งตัวกรองครบ · localStorage key `renewal-pipeline:v2` |
| `pages/policies/RenewalDetail.vue` | การ์ดกรมธรรม์เดิม · การ์ดเทียบใบเตือน · แผงบันทึกการติดตาม (แทนแผงติดต่อ/ปฏิเสธเดิม) + ปุ่มนัด +1/+3/+7 วัน · thread ประวัติการติดตาม · แบนเนอร์ปฏิเสธ + "เปิดติดตามใหม่" · ฟอร์มใบเตือนใหม่ |
| `composables/useRenewalActions.ts` | follow-up / reopen / ใบเตือน (สร้าง → render → upload) · เอา contact/decline เดิมออก |
| `composables/useRenewalNoticePdf.ts` **(ใหม่)** | layout ใบเตือนต่ออายุ |
| `composables/useQuotationPdf.ts` | แยก `renderNodeToPdfBlob(node, fitTolerance)` ใช้ร่วมกัน |
| `composables/useRenewalQuote.ts` | **ลบ** (แทนด้วย `useRenewalNoticePdf`) |
| `utils/renewalFollowup.ts` **(ใหม่)** | ป้าย/สีของผลและช่องทาง · `todayIso`, `addDaysIso`, `isFollowupOverdue` |
| `util/dateFormat.ts` | `fmtDateTime` (เวลาเครื่อง) — ใช้ใน `RenewalDetail.vue` และ `CustomerEditPage.vue` |
| `pages/settings/TenantSettings.vue` | ข้อมูลหน่วยงานโหลด/บันทึกจริง + ช่องทางชำระเงิน |
| `pages/products/ProductManagementV2.vue` | แถบสินค้าที่ยังไม่ผูกหมวดย่อย + สรุปตามหมวด + chip กรอง |
| `api/reports.ts`, `api/products.ts`, `api/tenant.ts`, `api/policies.ts`, `stores/policies.ts` | type และฟังก์ชันใหม่ตาม API |

## 6. การทดสอบ

```bash
DB_CONNECTION=mysql DB_DATABASE=insurehub_testing DB_USERNAME=root DB_PASSWORD= php artisan test
npm run build
```

`tests/Feature/Policy/RenewalPipelineTest.php` — 11 เทสต์:
- กรองหมวด/หมวดย่อย/`__none` ตาม taxonomy · แถวมีชื่อสินค้า ทะเบียน หมวด
- PDF รับตัวกรองตัวแทน/หมวด/ถึงกำหนด (200)
- follow-up เป็น thread ใหม่สุดก่อน + ชื่อผู้บันทึก · `lastFollowup` และ `followupDueCount` ในคิว
- `followupDue` ไม่ขึ้นกับช่วงวันหมดอายุ
- validation: ไม่ต่อไม่มีเหตุผล / `other` ไม่มีรายละเอียด / นัดย้อนหลัง / นัดตอนไม่ต่อ → 422
- ไม่ต่อ → stage `declined` + เหตุผล · reopen → stage กลับ · reopen ซ้ำ → 422
- แยก tenant (404)
- ใบเตือนเลขที่เรียง `RN{พ.ศ.}-00001`, `-00002` · net+อากร+VAT ไม่ตรงเบี้ยรวม → 422 · upload ผูกเอกสาร + event มี net/เลขที่ · ร่างปีถัดไปใช้ net/VAT จากใบเตือน · `renewalNoticeId` ที่ไม่มี → 422
- tenant `paymentChannels` บันทึก/อ่านกลับได้
- `taxonomy=unmatched` + `taxonomy-gaps`
- `GET /policies/{id}` มีชื่อสินค้า/บริษัท

ผล: ชุดใหม่ **11/11 ผ่าน** · ทั้งชุด **102/103** (ตัวที่ไม่ผ่านคือ `CommissionSnapshotTest::test_rate_row_newest_effective_from_wins` ซึ่งไม่ผ่านมาตั้งแต่ก่อนเริ่มงาน) · `npm run build` ผ่าน

ทดสอบใน Chrome:
- เลือกหมวด "การประกันรถโดยความสมัครใจ" → 53 แถว เป็นรถทั้งหมด พร้อมทะเบียน + ชื่อสินค้า
- `TSTRNS5` (policy 481): บันทึก "ติดต่อไม่ได้" นัด +1 → "ขอคิดก่อน" นัดวันนี้ → คิวขึ้น "ถึงกำหนดติดตาม (1)"
- ออกใบเตือน `RN2569-00002` เบี้ย 12,650 แยกอัตโนมัติ 11,774.43 / 48 / 827.57 → PDF 1 หน้า → การ์ดเทียบ +650 (+5.4%)
- `TSTRNS1` (policy 475): บันทึก "ไม่ต่อ — เบี้ยแพงเกินไป" → ป้าย "ลูกค้าปฏิเสธ" → "เปิดติดตามใหม่" → กลับเป็น "ติดต่อลูกค้าแล้ว"

## 7. ภาพก่อน / หลัง

อยู่ที่ `docs/tasks/task-5-renewal/screenshots/` (ถ่ายด้วย claude-in-chrome)

| เรื่อง | ก่อน | หลัง |
|---|---|---|
| 5.1 filter หมวด | `before-B1a-motor-filter-169-rows-no-product.png`, `before-B1b-non-motor-filter-0-rows.png` | `after-A1a-category-filter-motor-plate-product.jpg` |
| 5.1 สินค้าที่ยังไม่ผูกหมวด | — | `after-A1b-product-taxonomy-gaps.jpg` |
| 5.2 ทะเบียน / ผลิตภัณฑ์ | `before-B1a-...` (ไม่มีคอลัมน์) | `after-A1a-...`, `after-A2-followup-due-chip-last-followup.jpg` |
| 5.3 กรมธรรม์เดิม + เทียบ | `before-B3-detail-card-and-timeline.png` | `after-A3a-last-year-and-comparison.jpg`, `after-A3b-comparison-zoom.png` |
| 5.4 บันทึกการติดตาม | `before-B4a-contact-panel.png`, `before-B4b-decline-panel.png` | `after-A4a-followup-not-renewing-form.png`, `after-A4b-declined-banner-reopen.jpg`, `after-A4c-reopened-stage.png`, `after-A4d-followup-thread-timeline.jpg` |
| 5.4 ถึงกำหนดติดตามในคิว | — | `after-A2-followup-due-chip-last-followup.jpg` |
| 5.5 ตั้งค่าช่องทางชำระ | — | `after-A5a-tenant-payment-channels.png` |
| 5.5 ฟอร์มใบเตือน | `before-B5a-prepare-form-coverage-0.png` | `after-A5b-notice-form.png`, `after-A5c-notice-form-auto-split.png` |
| 5.5 PDF ใบเตือน | `before-B5b-notice-pdf-quotation-placeholder.jpg` | `after-A5d-notice-pdf-top.jpg`, `after-A5e-notice-pdf-bottom.jpg` |
| ขั้นตอนทั้งหมด | — | GIF `after-task5-followup-notice-flow.gif` |

## 8. ข้อมูลทดสอบใน dev DB

- ตั้งค่าหน่วยงาน (tenant ของ `admin@insurehub.co.th`): เลขผู้เสียภาษี `0105561234567`, ใบอนุญาต `นห.00123/2569`, โทร `02-555-0100`, ช่องทางชำระเงิน (บัญชีกสิกรไทย/พร้อมเพย์) — **ค่าทดสอบทั้งหมด ต้องแก้เป็นค่าจริงก่อนใช้งาน**
- `TSTRNS5` (policy 481): follow-up 2 รายการ · ใบเตือน `RN2569-00001` (สร้างก่อนแก้ layout PDF จึงยาว 2 หน้า) และ `RN2569-00002` (1 หน้า) พร้อมเอกสาร · stage = จัดทำใบเสนอราคาแล้ว
- `TSTRNS1` (policy 475): follow-up "ไม่ต่อ — เบี้ยแพงเกินไป" แล้วเปิดติดตามใหม่
- `RenewalScenarioSeeder`: สินค้า `TSTRN-P-MOTOR` ได้หมวด "การประกันรถโดยความสมัครใจ" / หมวดย่อย "ป1." / Motor

## 9. ข้อจำกัด / งานต่อ

- **5.5 ยังต้อง UAT** กับเคสจริง 5 แบบ (Motor 1 / 2+ / 3, Non-motor, Life) ตาม `task-5-renewal/uat-checklist.md` — งานหลักและ 5.5 คง IN PROGRESS
- สินค้า 400 จาก 895 ยังไม่ผูกหมวดย่อยตาม taxonomy (ส่วนใหญ่หมวดย่อยว่าง: รถภาคสมัครใจ 147, ประเภทสามัญ 122) → ขึ้นเฉพาะตอนกรองระดับหมวด หรือเลือก "ยังไม่ระบุหมวดย่อย" ต้องให้ทีมสินค้าเติมหมวดย่อย
- ประกันชีวิตไม่มีสูตรแยกภาษีแบบ "ไม่มีอากร/VAT" → ปิด "แยกอัตโนมัติ" แล้วใส่ net = เบี้ยรวม, อากร/VAT = 0 เอง
- ใบเตือนเป็น PDF ที่ render ฝั่งเบราว์เซอร์ (html2canvas) ข้อความใน PDF เป็นภาพ ค้นหา/คัดลอกไม่ได้
- ใบเตือนยังแก้ไข/ยกเลิกไม่ได้ ถ้าราคาผิดให้ออกใบใหม่ (เลขถัดไป) การ์ดเทียบใช้ใบล่าสุด
- ข้อความใหม่เป็นภาษาไทยในหน้าโดยตรงแบบเดียวกับหน้าต่ออายุเดิม (หน้าเหล่านี้ไม่ได้ใช้ i18n) ยังไม่มีคำแปลอังกฤษ
- ปุ่ม "บันทึกการติดต่อ" เดิม (`POST renewal/contacted`) ยังอยู่ใน API เพื่อความเข้ากันได้ แต่หน้าจอใช้ follow-up แทนแล้ว
