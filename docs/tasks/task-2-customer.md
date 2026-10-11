# งาน 2 — ลูกค้า (แก้ตามที่ประชุม Customer)

ที่มา: ClickUp `z8qu5d0qux` (ซับทาสก์ 2.1–2.6) จากไฟล์ "สรุปประชุม – Customer" · ทำเมื่อ 2026-10-03 · เทียบกับโค้ดก่อนเริ่มงาน 2 (snapshot `baseline2`)

## 1. สรุป

| ซับทาสก์ | ผลลัพธ์ |
|---|---|
| 2.1 สร้างลูกค้าจากหน้าสร้างกรมธรรม์ | ค้นไม่เจอ → dropdown แสดง "ไม่พบข้อมูล" + ปุ่ม "สร้างลูกค้าใหม่ "<คำค้น>"" → เปิด modal ที่เติมชื่อ/นามสกุล (หรือเลขบัตร / เบอร์) จากคำค้นให้ → บันทึกแล้วลูกค้าถูกเลือกในกรมธรรม์ทันที และใช้ตัวแทนผู้ดูแลเป็นตัวแทนเริ่มต้น มีลิงก์ "เปิดหน้าสร้างลูกค้าแบบเต็ม" (แท็บใหม่) ด้วย |
| 2.2 เพศ แทนจังหวัด | หน้ารายการเอาคอลัมน์/การเรียงจังหวัดออก เพิ่มคอลัมน์เพศ (เรียงได้) + ตัวกรองเพศ (ชาย/หญิง/ไม่ระบุ) · ฟอร์มสร้างลูกค้าเลือกคำนำหน้าแล้วตั้งเพศให้อัตโนมัติ · คำสั่ง `customers:backfill-gender` เดาเพศลูกค้าเก่าจากคำนำหน้า |
| 2.3 เบอร์โทรสำรอง | "โทรศัพท์บ้าน" → **"เบอร์โทรสำรอง"** รับได้ทั้งมือถือและเบอร์บ้าน (ต่างชาติรับรูปแบบสากล) · backend ตัดขีด/ช่องว่างออกก่อนตรวจ · ค้นหาลูกค้าด้วยเบอร์สำรองได้ · รายงาน/ติดตามเงิน/งานค้าง ใช้เบอร์สำรองเมื่อไม่มีเบอร์มือถือ |
| 2.4 การมอบหมาย | วันที่เป็น dd/mm/yyyy · ตัวเลขกรมธรรม์แยก "คุ้มครองอยู่ / ระหว่างดำเนินการ / สิ้นสุดแล้ว / ทั้งหมด" พร้อมคำอธิบาย · ปุ่ม **"เปลี่ยนตัวแทนผู้ดูแล"** (เลือกตัวแทน + เหตุผล) · ประวัติการมอบหมายแสดงรหัส+ชื่อตัวแทนและผู้ทำรายการ · หัวข้อ "ผู้ติดต่อแทน (ผู้ประสานงาน)" พร้อมคำอธิบาย |
| 2.5 ดูก่อน → แก้ไข → ยืนยัน | หน้าลูกค้าเปิดมาเป็น **โหมดดู** (เลือก/คัดลอกข้อความได้ มีปุ่มคัดลอกที่เลขบัตร/เบอร์/อีเมล) → กด "แก้ไข" (เห็นเฉพาะผู้มีสิทธิ์ `customers.update`) → แถบ "แก้ไขแล้ว N รายการ" → "บันทึก" → **dialog สรุปค่าเดิม → ค่าใหม่** → ยืนยัน → PATCH ครั้งเดียว · เตือนเมื่อออกจากหน้าโดยยังไม่บันทึก · ส่วน **"ประวัติการแก้ไข"** (ใคร/เมื่อไร/อะไรเปลี่ยน ข้อมูลส่วนบุคคลถูกปิดบัง) |
| 2.6 ยอดขาย + เกรด | การ์ด "ยอดขายของลูกค้า" (เบี้ยสะสม / เบี้ยปีนี้ / จำนวนกรมธรรม์ที่ออกแล้ว / เกรด) · หน้ารายการมีคอลัมน์ยอดเบี้ยสะสม + badge เกรด เรียงและกรองตามเกรดได้ · ตั้งค่าหน่วยงาน → แท็บ **"เกรดลูกค้า"** แก้ชื่อเกรด/เกณฑ์ หรือเว้นว่างเพื่อปิดเกรด |

**การตัดสินใจทางธุรกิจ (ยืนยันกับผู้ใช้แล้ว):**
- เพศของลูกค้าเก่า: เดาจากคำนำหน้า (เดาไม่ได้ปล่อยว่าง)
- เปลี่ยนตัวแทนผู้ดูแล: มีผลกับงานใหม่เท่านั้น กรมธรรม์เดิมยังเป็นของตัวแทนที่ทำสัญญา ผู้ดูแลคนใหม่เป็นค่าเริ่มต้นตอนสร้างกรมธรรม์/ต่ออายุครั้งถัดไป
- สิทธิ์แก้ข้อมูลลูกค้า: ตาม RBAC ของแต่ละ role (`customers.update`) ไม่มีขั้นอนุมัติ
- ยอดขาย: เบี้ยต่อปี (`annual_premium`) ของกรมธรรม์สถานะ `issued / active / expired / lapsed` · เกรดเริ่มต้น VIP ≥ 100,000 / Gold ≥ 50,000 / Silver ≥ 20,000 แก้ได้ต่อ tenant

## 2. ปัญหาที่พบก่อนแก้ (ถ่ายภาพจากระบบจริง)

| # | ปัญหา | สาเหตุ |
|---|---|---|
| B1 | ค้นลูกค้าในหน้าสร้างกรมธรรม์ไม่เจอ → ขึ้นแค่ "ไม่พบข้อมูล" ไม่มีทางสร้างลูกค้าต่อ | `EntityPicker` มีปุ่ม "+ New" อยู่แล้วแต่ wizard ไม่ได้เปิดใช้ · `CustomerCreateModal` ทำไว้แต่ไม่มีที่ไหนเรียก |
| B2 | รายการลูกค้ามีคอลัมน์จังหวัด ไม่มีเพศ ไม่มียอดขาย | — |
| B3 | ช่อง "โทรศัพท์บ้าน" ใส่เบอร์มือถือไม่ได้ ("รูปแบบเบอร์บ้านไม่ถูกต้อง") · backend ไม่รับเบอร์ที่มีขีด | `validateLandline` / regex ฝั่ง backend |
| B3a | **หน้า `/customers/new` เปิดไม่ขึ้นเลย** (จอว่าง, console: `Cannot access 'form' before initialization`) | `watch(() => form.customerType)` อยู่ก่อน `const form = reactive(...)` → TDZ ตอน setup · `CustomerCreateModal` มีบั๊กเดียวกัน (ซึ่งจะทำให้ wizard พังทันทีที่ฝัง modal) |
| B4 | ส่วนการมอบหมาย: "กรมธรรม์ที่ใช้งาน 3 / ทั้งหมด 4" ไม่บอกว่าอีก 1 คืออะไร · วันที่เป็น ISO ดิบ · ไม่มีปุ่มเปลี่ยนผู้ดูแล (มี API แต่ไม่มีหน้าจอ) · ประวัติแสดงเป็น id ตัวเลข | นับแค่ `status = active` · API `POST customers/{id}/assignments` ไม่มีที่เรียก |
| B5 | คลิกช่องไหนก็เข้าแก้ทันที และ blur/Enter = บันทึกทันที ไม่มีการยืนยัน ไม่มีประวัติ | `EditableField` / `AddressPicker` PATCH เองทุกครั้ง |
| B6 | หน้าลูกค้าไม่มียอดขายสะสม/เกรด | ไม่มีการรวมยอด และไม่มีที่เก็บเกณฑ์ |

**ปัญหาเพิ่มเติมที่พบระหว่างทำ (แก้แล้ว):**
- `PATCH /customers/{id}` คืนข้อมูลโดยไม่โหลด relation → หลังแก้ทุกครั้ง KYC และประวัติการมอบหมายหายจากหน้าจอ
- `CustomerRequest` ตั้ง `customerType = individual` เมื่อไม่ได้ส่งมา → แก้ลูกค้าต่างชาติทีละช่องถูกตรวจตามกฎคนไทย
- API ลูกค้าไม่ตรวจสิทธิ์ และลบลูกค้าที่ยังมีกรมธรรม์ได้
- ผู้ใช้ `super_admin` จาก seed ไม่มี `role_id` → ถ้าเปิด Gate จะถูกปฏิเสธ (แก้ให้ `Rbac` ใช้ค่า `role` แบบเดิมเป็น fallback + seed ตั้ง `role_id`)
- กด Enter เพื่อเลือกตัวเลือกใน dropdown ภายใน modal สร้างข้อมูล → modal ถูก **submit ทันที** (สร้างลูกค้าโดยยังกรอกไม่ครบ) เพราะ `CreateModal` ฟัง Enter ที่ `window` โดยไม่ดูว่า control ข้างในจัดการไปแล้ว → แก้ให้ข้าม event ที่ `defaultPrevented` และ Esc ใน dropdown ปิดแค่ dropdown
- `DateInput` แสดงวันที่เป็น `01/26/2034, 00:00` ทุกหน้า เพราะ `@vuepic/vue-datepicker` v14 เปลี่ยนชื่อ prop (`format` → `formats.input`, `enable-time-picker` → `time-config`, `clearable` → `input-attrs`, `input-class-name` → `ui.input`) — ค่าที่บันทึกยังถูกต้อง (`model-type` ยังใช้ได้) แก้แล้วแสดง dd/mm/yyyy · **หมายเหตุ:** รอบแรกใส่ `formats.input` เป็นฟังก์ชัน ซึ่งทำให้ library ส่งค่า model เป็น `dd/mm/yyyy` ด้วย — พบและแก้เป็น pattern string `'dd/MM/yyyy'` ในงาน 4 (ดู `task-4-endorsement.md`)

## 3. การไหลของข้อมูล

```
หน้าสร้างกรมธรรม์ ── ค้นลูกค้า (q) ──► GET /customers?q=  (ค้นรวม tel_phone / contact_phone)
      │ ไม่เจอ → "สร้างลูกค้าใหม่ "q""
      ▼
CustomerCreateModal(initial = splitCustomerQuery(q))  ── POST /customers ──► created row
      │ emit created / open-existing
      ▼
applyPickedCustomer(row) → form.customerId + label  ·  defaultAgentFromCustomer(row) → writingAgentId (ถ้ายังว่าง)

หน้าลูกค้า (โหมดดู) ── GET /customers/{id}  (policyCounts, salesSummary, assignmentHistory+agent)
      │ แก้ไข → useDraftEdit (draft แยกจากข้อมูลจริง)
      ▼ บันทึก → ConfirmChangesDialog (ค่าเดิม → ค่าใหม่)
PATCH /customers/{id}  { ...เฉพาะช่องที่เปลี่ยน, customerType }   ← Gate customers.update
      │ CustomerObserver::updated → AuditEntry customer.updated {changes:{col:{from,to}}} (PII ปิดบัง)
      ▼
GET /customers/{id}/audit  → "ประวัติการแก้ไข"

เปลี่ยนผู้ดูแล ── POST /customers/{id}/assignments {toAgentId, reason}  ← Gate customers.update
      │ เขียน customer_assignment_history + assigned_agent_id (audit ผ่าน observer)
      ▼ กรมธรรม์เดิมไม่ถูกแตะ
ต่ออายุครั้งถัดไป ── RenewalBlueprint: writing_agent_id = customer.assigned_agent_id (meta.agentFromCaretaker)
สร้างกรมธรรม์ใหม่ ── wizard ตั้งตัวแทนเริ่มต้นจาก assignedAgentId ของลูกค้า
```

## 4. API ที่เพิ่ม/เปลี่ยน

| Endpoint | การเปลี่ยนแปลง |
|---|---|
| `GET /customers` | ค้นรวม `tel_phone`, `contact_phone` · ตัวกรอง `gender=M|F|none`, `tier=vip|gold|silver|none` · sort `gender`, `totalPremium` (เอา `province` ออก) · แถวมี `telPhone`, `gender`, `totalPremium`, `tier {key,label}` |
| `GET /customers/{id}` | เพิ่ม `policyCounts {inForce, inProgress, ended, total}`, `salesSummary {totalPremium, premiumThisYear, policyCount, year, tier}` · `assignmentHistory[]` เพิ่ม `fromAgentCode/Name`, `toAgentCode/Name`, `byUserName` |
| `PATCH /customers/{id}` | ต้องมีสิทธิ์ `customers.update` · ไม่ส่ง `customerType` = ใช้ของเดิม · ตัดขีด/ช่องว่างในเบอร์ · `telPhone` รับมือถือ/เบอร์บ้าน · คืนข้อมูลพร้อม KYC + ประวัติ |
| `DELETE /customers/{id}` | ต้องมีสิทธิ์ `customers.delete` · ถ้ามีกรมธรรม์ → `409 {code: customer_has_policies, policyCount}` |
| `POST /customers/{id}/assignments` | ต้องมีสิทธิ์ `customers.update` · คืนข้อมูลพร้อม relation |
| `GET /customers/{id}/audit` **(ใหม่)** | `{data:[{id, occurredAt, actor, action, changes}]}` ล่าสุด 100 รายการ · `assigned_agent_id` แปลงเป็น "รหัส · ชื่อตัวแทน" |
| `GET/PATCH /tenant` | เพิ่ม `customerTiers [{key, label, min}]` (min = null คือปิดเกรด) |
| `GET /policies/{id}/renewal-draft` | ใช้ผู้ดูแลลูกค้าเป็น `writingAgentId` ถ้ามี · `meta.agentFromCaretaker` |

ฐานข้อมูล: migration `2027_03_16_000100_add_customer_tiers_to_tenants` (คอลัมน์ JSON `tenants.customer_tiers`)

## 5. สิทธิ์ (RBAC) — ขั้นตอน rollout

- เพิ่มสิทธิ์ใหม่ `customers.delete` ใน `PermissionCatalog` (ต้องรัน `php artisan db:seed --class=RbacPermissionSeeder` บนเครื่องที่ deploy)
- `super_admin` / `admin` ผ่านทุกสิทธิ์อยู่แล้ว
- **role อื่นต้องให้แอดมินเปิดสิทธิ์ที่ `/admin/roles`:** `customers.update` (แก้ข้อมูล + เปลี่ยนผู้ดูแล) และ `customers.delete` — ถ้าไม่เปิด role นั้นจะดูได้อย่างเดียว (ไม่เห็นปุ่มแก้ไข/ลบ และ API ตอบ 403)
- การดู/สร้าง/ค้นหาลูกค้า **ยังไม่ล็อก** เพื่อไม่ให้ role ที่ยังไม่ได้ตั้งสิทธิ์ใช้งานไม่ได้ — ควรล็อกหลังตั้งสิทธิ์ครบทุก role

## 6. เติมเพศลูกค้าเก่า

```bash
php artisan customers:backfill-gender            # dry-run แสดงจำนวน + คำนำหน้าที่เดาไม่ได้
php artisan customers:backfill-gender --apply    # บันทึกจริง (+ audit customer.gender_backfilled ต่อ tenant)
php artisan customers:backfill-gender --tenant=1 --apply
```

- เปลี่ยนเฉพาะแถวที่เพศว่าง/ค่าไม่ถูกต้อง ไม่ทับค่าที่มีอยู่ · แปลงค่าเก่า `male/female/ชาย/หญิง` เป็น `M/F` · ข้ามนิติบุคคล
- ชาย: นาย / เด็กชาย / ด.ช. / นพ. / Mr / Master / ยศทหาร-ตำรวจ · หญิง: นาง / นางสาว / น.ส. / เด็กหญิง / Mrs / Ms / Miss / ยศที่มีคำว่า "หญิง" · เดาไม่ได้: คุณ / ว่าง
- ผลบน dev DB: ชาย 141 · หญิง 161 · เดาไม่ได้ 5 (คุณ 4, ว่าง 1) · นิติบุคคล 52
- importer และ `CustomerSeeder` ใช้กฎเดียวกัน (`App\Support\GenderFromTitle`)

## 7. ฝั่งหน้าจอ

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `components/EntityPicker.vue` | emit `newClick(query)` · แสดง "ไม่พบข้อมูล "q"" + ไฮไลต์ปุ่มสร้างเมื่อไม่มีผล |
| `pages/customers/CustomerCreateModal.vue` | prop `initial` · ตั้งเพศจากคำนำหน้า · "เบอร์โทรสำรอง" · แก้ลำดับ watcher (B3a) |
| `pages/customers/CustomerCreatePage.vue` | เหมือน modal + อ่าน error จาก `ApiError.body` · แก้ลำดับ watcher (B3a) |
| `pages/policies/PolicyApplicationWizard.vue` | ฝัง modal · เลือกลูกค้าที่สร้าง/เปิด · ตัวแทนเริ่มต้นจากผู้ดูแล · ลิงก์หน้าสร้างแบบเต็ม |
| `pages/customers/CustomerListV2.vue` | คอลัมน์เพศ/ยอดเบี้ยสะสม/เกรด · ตัวกรองเพศ/เกรด · เบอร์สำรองใต้เบอร์หลัก |
| `pages/customers/CustomerEditPage.vue` | โหมดดู/แก้ไข/ยืนยัน · ปุ่มคัดลอก · การ์ดยอดขาย · แยกจำนวนกรมธรรม์ · เปลี่ยนผู้ดูแล · ประวัติการมอบหมาย/การแก้ไข · ปุ่มตามสิทธิ์ · เตือนออกจากหน้า |
| `components/EditableField.vue` | prop `controlled` (ช่องกรอกตลอด ไม่ PATCH เอง) + `errorText` · `readonly` แสดงเป็นข้อความที่เลือก/คัดลอกได้ |
| `components/AddressPicker.vue` | prop `deferred` (ส่งค่าให้ parent แทนการ PATCH) |
| `composables/useDraftEdit.ts` (ใหม่) | draft / diff / payload แบบซ้อนชั้น (`contactPerson.phone` → `{contactPerson:{phone}}`) |
| `components/ConfirmChangesDialog.vue`, `components/CopyButton.vue` (ใหม่) | dialog ค่าเดิม→ค่าใหม่ · ปุ่มคัดลอก |
| `pages/settings/TenantSettings.vue`, `api/tenant.ts` (ใหม่) | แท็บ "เกรดลูกค้า" |
| `components/CreateModal.vue`, `components/SearchSelect.vue` | Enter/Esc ใน dropdown ไม่ submit/ปิด modal |
| `components/DateInput.vue` | prop ตาม vue-datepicker v14 (dd/mm/yyyy) |

## 8. การทดสอบ

```bash
# backend (MySQL insurehub_testing)
DB_CONNECTION=mysql DB_DATABASE=insurehub_testing DB_USERNAME=root DB_PASSWORD= php artisan test
# frontend
npm run build      # vue-tsc + vite build
```

- ชุดใหม่: `tests/Unit/Customer/GenderFromTitleTest.php`, `tests/Unit/Customer/CustomerTierTest.php`, `tests/Feature/Customer/CustomerFlowTest.php` → **41/41 ผ่าน (114 assertions)**
  - ไม่มีสิทธิ์ → 403, ให้ `customers.update` → 200, admin/super_admin ที่ไม่มี `role_id` → 200
  - ลบลูกค้าที่มีกรมธรรม์ → 409 · ลบได้เมื่อไม่มีกรมธรรม์ (soft delete)
  - หลัง update ยังมี KYC + ประวัติ · ประวัติมีรหัสตัวแทน
  - แก้ลูกค้าต่างชาติทีละช่องตรวจตามกฎต่างชาติ · เบอร์มีขีดบันทึกได้ · เบอร์สำรองเป็นมือถือได้ · ค้นด้วยเบอร์สำรองเจอ
  - กรอง/เรียงตามเพศ · backfill เดาถูกและไม่ทับค่าเดิม
  - เปลี่ยนผู้ดูแลไม่แตะกรมธรรม์เดิม · ร่างต่ออายุใช้ผู้ดูแลคนใหม่
  - `policyCounts`, `salesSummary` + เกรดตามเกณฑ์ tenant · กรองเกรด
  - audit มีจริง ข้อมูลส่วนบุคคลถูกปิดบัง · audit แสดงรหัสตัวแทนแทน id
- ทั้งชุด: **83/84** — ที่ไม่ผ่านคือ `CommissionSnapshotTest::test_rate_row_newest_effective_from_wins` ซึ่งไม่ผ่านตั้งแต่ก่อนเริ่มงาน (ไม่เกี่ยวกับงานนี้)
- `npm run build` ผ่าน (มีแค่คำเตือนขนาด chunk เดิม)

## 9. ภาพก่อน / หลัง

ภาพอยู่ที่ `docs/tasks/task-2-customer/screenshots/` (ถ่ายด้วย Chrome headless ผ่าน puppeteer-core เพราะ Claude-in-Chrome extension ไม่เชื่อมต่อในรอบนี้ · ภาพ "ก่อน" ถ่ายจากโค้ด `baseline2` ที่รันบนพอร์ตเดียวกัน)

| เรื่อง | ก่อน | หลัง |
|---|---|---|
| 2.1 ค้นลูกค้าไม่เจอในหน้ากรมธรรม์ | `before-B1-wizard-customer-not-found.png` | `after-A1a-wizard-not-found-create.png` → `after-A1b-inline-create-modal-prefilled.png` → `after-A1c-new-customer-selected-in-wizard.png` · GIF `after-A1-inline-customer-create.gif` |
| 2.2 รายการลูกค้า | `before-B2-customer-list.png` | `after-A2a-customer-list-gender-premium-tier.png`, `after-A2b-customer-list-filter-female.png` |
| 2.3 เบอร์โทรสำรอง | `before-B3-tel-phone-rejects-mobile.png`, `before-B3a-create-page-crash.png` | `after-A3-backup-phone-accepts-mobile.png`, `after-A3b-create-page-backup-phone.png` |
| 2.4 การมอบหมาย | `before-B4-assignment-section.png` | `after-A4a-assignment-breakdown.png`, `after-A4b-change-agent-modal.png`, `after-A4c-assignment-after-change.png`, `after-A4d-assignment-history.png` |
| 2.5 ดู → แก้ → ยืนยัน | `before-B5-click-to-edit-saves-immediately.png` | `after-A5a-view-mode-default.png`, `after-A5b-edit-mode.png`, `after-A5b2-edit-mode-dates.png`, `after-A5b3-edit-mode-sticky-save-bar.png`, `after-A5c-confirm-changes-dialog.png`, `after-A5d-saved-back-to-view.png`, `after-A5e-view-mode-copy-buttons.png`, `after-A5f-audit-history.png` · GIF `after-A5-view-edit-confirm.gif` |
| 2.6 ยอดขาย + เกรด | `before-B6-customer-page-top.png` | `after-A6a-sales-summary-tier.png`, `after-A6b-customer-list-filter-gold.png`, `after-A6c-tenant-tier-settings.png` |

## 10. ข้อมูลทดสอบที่เกิดใน dev DB

- ลูกค้า `C9901452` นางสาว สมหญิง ทดสอบใหม่ (สร้างจาก modal ในหน้ากรมธรรม์) + ร่างกรมธรรม์ `A2610030004` ที่ wizard autosave
- ลูกค้า `C9901333` (id 241): มอบหมายให้ `AG200011` เหตุผล "ผู้ดูแลเดิมลาออก…", ชื่อเล่น "วิทย์", เบอร์สำรอง 0812223344
- ลูกค้า `C9901450`, `C9901451` ถูกสร้างจากบั๊ก Enter-submit (ข้อ 2) แล้ว soft delete ทิ้ง

## 11. ข้อจำกัด / งานต่อ

- ยังไม่ล็อกสิทธิ์ดู/สร้างลูกค้า (ดูข้อ 5)
- ลูกค้านิติบุคคลใน dev DB หลายรายไม่มี `juristic_name` (แสดง "—") เป็นข้อมูลจาก import เดิม
- ไม่มีบัญชีผู้ใช้ role อื่นใน dev DB จึงทดสอบ "ไม่เห็นปุ่มแก้ไข" ผ่าน feature test แทนภาพหน้าจอ
- ยอดขายนับจาก `annual_premium` (เบี้ยเต็มปี) ไม่ได้นับตามเงินที่รับจริง ถ้าต้องการยอดตามเงินรับจริงต้องเปลี่ยนแหล่งข้อมูลเป็นตารางชำระเงิน
