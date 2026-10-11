# งาน 6 — Database โครงสร้างตัวแทน (10 ระดับ + InsureHub อนุมัติเลื่อนระดับ)

ที่มา: ClickUp `z8qu5d0qv1` (ซับทาสก์ 6.1 `z8qu5d0qw5`, 6.2 `z8qu5d0qw6`) · ทำเมื่อ 2026-10-04 · เทียบกับโค้ดก่อนเริ่มงาน 6 (snapshot `baseline5`)

> โครงสร้างตัวแทนมี 10 LVL · แต่ละ LVL ขึ้นอยู่กับผลงานทีมรวม · InsureHub เป็นผู้อนุมัติขึ้นระดับ
> ที่ประชุม Agent 27/09: "ลองปรับระดับดู แต่มันไม่ link อะไรกับข้างล่าง … ไม่อยากให้น้องปรับเองได้ … ต้องมี noti ไปขออนุมัติ"

## 1. สรุป

| ซับทาสก์ | ผลลัพธ์ |
|---|---|
| 6.1 ทบทวนเกณฑ์ 10 ระดับ | กฎยืนยันครบ 8 ข้อ → [`task-6-agent-rank/rank-rules.md`](task-6-agent-rank/rank-rules.md) (ฉบับส่งธุรกิจ sign-off พร้อม 4 ประเด็นที่ควรยืนยัน) · หน้า "เกณฑ์ 10 ระดับ" ดู/แก้ได้ในระบบพร้อมประวัติ · เอา `monthly_avg_target` ออก |
| 6.2 เทสต์ + ปรับตามกฎ | เทสต์ใหม่ 12 ตัว (ยอดทีม, หน้าต่าง 3 เดือน, ลบ/แก้การชำระ, เสนอ → อนุมัติ → `rank_id` + audit, ใบอนุญาต/Lv6, reject, สิทธิ์ 403, คำขอปรับเอง, แก้เกณฑ์, reconcile) · แก้บั๊กที่เทสต์เจอ 5 จุด · สิทธิ์ใหม่ `agents.rank_approve` · ปิดการแก้ระดับโดยตรง |

**กฎที่ยืนยันกับผู้ใช้:**
1. ใช้เป้าเดิม (0/0/300K/600K/1.5M/3M/6M/12M/24M/48M) + แก้ได้ในหน้าตั้งค่าโดยผู้มีสิทธิ์ พร้อมประวัติ
2. เอาเป้าเฉลี่ยรายเดือนออก ใช้ยอดสะสม 3 เดือนอย่างเดียว
3. ยอดทีมแบบเดิม — ตัวเอง + ลูกทีมทุกชั้นที่ระดับต่ำกว่า
4. ไม่มีการลดระดับอัตโนมัติ
5. ปรับระดับเอง → ส่งคำขอ (ขึ้น/ลง + เหตุผล) ให้ InsureHub อนุมัติ
6. สิทธิ์ใหม่ "อนุมัติเลื่อนระดับ" แยกจากอนุมัติตัวแทน · ส่งคำขอใช้สิทธิ์ "แก้ไขตัวแทน"
7. ใบอนุญาต Lv7+ = ใบชีวิตหรือวินาศที่ยังไม่หมดอายุ (คำนวณเอง ตรวจซ้ำตอนอนุมัติ)
8. ตัวแทนที่ยังไม่มีระดับ + ตัวแทนใหม่ → Lv1

**ที่ตีความเพิ่ม (ระบุไว้ใน rank-rules ข้อ 6 ให้ธุรกิจยืนยัน):** เป้า 0 ของ Lv2 = ต้องมียอดขาย > 0 (ไม่อย่างนั้นทุกคนจะมีคำขอ Lv2 ทันที) · ระดับที่ถูกปฏิเสธไม่เสนอซ้ำในเดือนเดียวกัน · คำขอระบบเสนอค้างได้ 1 รายการต่อคน

## 2. ปัญหาที่พบก่อนแก้ (ถ่ายด้วย claude-in-chrome)

| # | ปัญหา | สาเหตุ |
|---|---|---|
| B1 | ฟอร์มตัวแทนบอก "ระดับ 5 (Lv5)" แต่ความคืบหน้าบอก "ระดับ 0 → 1" · เลือก Lv7 แล้วบันทึก → "The selected level is invalid." | dropdown ใช้ `agents.level` (ค่า default `l5`) แต่ความคืบหน้าใช้ `rank_id` (ว่างทั้ง 393 คน) · `AgentRequest` รับแค่ l1–l5 และไม่ sync `rank_id` · แก้ระดับตรงได้ ไม่มีอนุมัติ/ประวัติ |
| B2 | บอร์ด "เลื่อนระดับ" ทุกคน "ระดับ 0 → 1, ฿0 / ฿0" · ไม่มีที่ดูเกณฑ์ | `rank_id` ว่าง (backfill เดิมรันตอนตาราง `ranks` ยังว่าง) · ไม่มีหน้าเกณฑ์ |
| B3 | กล่องรออนุมัติ: ปุ่มอนุมัติ/ปฏิเสธขึ้นให้ทุกคน · อนุมัติไม่ถามยืนยัน · ปฏิเสธใช้ `window.prompt` (กด Cancel ก็ยังปฏิเสธ) · ไม่รู้ว่าใครขอ ทำไม | API อนุมัติไม่ตรวจสิทธิ์เลย (ตัวแทนที่ล็อกอินก็อนุมัติได้) · ไม่มี audit |
| B4 | รายการตัวแทนกรองระดับได้แค่ L1–L5 · badge/i18n รองรับแค่ 5 ระดับ | ฮาร์ดโค้ดจากสมัย 5 ระดับ |

**บั๊กที่เทสต์ 6.2 เจอ (แก้แล้ว):**
- **เดือนเพี้ยนช่วงวันที่ 29–31:** `createFromFormat('Y-m', '2026-09')` ใช้วันที่ปัจจุบัน → วันที่ 31 ต.ค. แปลง "2026-09" เป็น 1 ต.ค. ยอดเดือนก่อนหน้าไปลงเดือนผิด → ใช้ `'!Y-m'` (ยืนยันด้วยเทสต์ `test_month_buckets_are_correct_on_the_last_day_of_a_long_month`)
- **ลบการชำระแล้วยอดไม่ลด** (observer มีแต่ `created`) → เพิ่ม `updated` (ยอด/วันที่/กรมธรรม์) และ `deleted`
- **ชำระย้อนหลังไม่ปรับยอดสะสมเดือนถัดไป** → คำนวณเดือนที่ชำระ + 2 เดือนถัดไป
- **reconcile คืนละครั้งทำแค่เดือนปัจจุบัน** → ทำ 3 เดือนล่าสุด (เก่า → ใหม่)
- **`has_license` ไม่มีหน้าจอ/API ตั้งค่า ทุกคน = ไม่มี** → คำนวณจากเลข + วันหมดอายุใบอนุญาตชีวิต/วินาศ (`App\Support\AgentLicense`)
- อนุมัติแล้วไม่คำนวณยอดทีมต้นสายใหม่ (กฎ "นับเฉพาะระดับต่ำกว่า" เปลี่ยนตามระดับ) → คำนวณเดือนปัจจุบันของตัวแทน + สายบนทันที
- `RankSeeder` เดิม `updateOrCreate` ทับเป้าทุกครั้งที่ seed → เปลี่ยนเป็น `firstOrCreate` (ไม่ทับค่าที่แก้ในหน้าเกณฑ์)
- badge "รออนุมัติ (n)" ไม่อัปเดตหลังอนุมัติ/ปฏิเสธ → รีโหลดหลังทุกการตัดสิน

## 3. การไหลของข้อมูล

```
บันทึก/แก้/ลบการชำระ (PolicyPaymentObserver created/updated/deleted)
   └─ VolumeAccumulator::recomputeFrom(ผู้ขาย, เดือนที่ชำระ) → เดือนนั้น + 2 เดือนถัดไป (ไม่เกินเดือนนี้)
        ตัวแทน + สายบนทุกชั้น: personal / team (ตัวเอง + ลูกทีมระดับต่ำกว่า, CTE) / rolling 3 เดือน
   └─ RankPromotionService::evaluateForChain → ยอดสะสม > 0 และ ≥ เป้า (ไม่มีใบอนุญาต → ≤ Lv6)
        ข้ามถ้า: ไม่สูงกว่าระดับปัจจุบัน / ถูกปฏิเสธในเดือนนี้ / มีคำขอค้างที่ระดับ ≥ นี้
        สร้าง rank_promotions (auto, pending) · คำขอ auto ที่ต่ำกว่า → superseded

ขอปรับเอง: POST /agents/{id}/rank-requests {toLevel, note}  ← agents.update
   └─ ห้ามระดับเดิม / ต้องมีใบอนุญาตถ้าระดับบังคับ / มีคำขอค้างอยู่ → 422 · audit agent.rank_requested

อนุมัติ: POST /rank-promotions/{p}/approve  ← agents.rank_approve
   └─ lock agent → ตรวจใบอนุญาตซ้ำ (422 license_required) → auto: ขึ้นเท่านั้น / manual: ตั้งตามที่ขอ
   └─ agents.rank_id + level · คำขอค้างอื่นที่ไม่สูงกว่า → superseded · audit agent.rank_approved + agent.updated
   └─ คำนวณยอดเดือนนี้ของตัวแทน + สายบนใหม่
ไม่อนุมัติ: POST /rank-promotions/{p}/reject {note (จำเป็น)} ← agents.rank_approve · audit agent.rank_rejected

คืนละครั้ง 02:00: mgm:reconcile-volumes → rebuild 3 เดือนล่าสุด → evaluate ทุกคน
```

## 4. API / ฐานข้อมูล / สิทธิ์

| Endpoint | การเปลี่ยนแปลง |
|---|---|
| `GET /ranks` | ไม่มี `monthlyAvgTarget` แล้ว |
| `PATCH /ranks/{rank}` **(ใหม่)** | แก้ชื่อ/เป้า 3 เดือน/บังคับใบอนุญาต · `admin.settings` · ตรวจเป้าไม่ลดหลั่น, Lv1 = 0, ใบอนุญาตต่อเนื่อง · audit `rank.updated` (before/after) |
| `POST /agents/{agent}/rank-requests` **(ใหม่)** | คำขอปรับระดับ (ขึ้น/ลง + เหตุผล) · `agents.update` |
| `GET /rank-promotions` | กรอง `agentId` · แถวเพิ่ม `currentLevel, licenseValid, toLicenseRequired, requestedByName, requestNote, decidedByName` · สถานะ `superseded` |
| `POST /rank-promotions/{p}/approve` | `agents.rank_approve` (403) · 422 `{code: not_pending / license_required}` · คืนแถวเต็ม |
| `POST /rank-promotions/{p}/reject` | `agents.rank_approve` · `note` จำเป็น |
| `PATCH /agents/{id}` | ไม่รับ `level`/`rankId` แล้ว (ส่งมาก็ไม่เปลี่ยนระดับ) |
| `PATCH /agents/{id}/hierarchy` | ส่ง `level` → 422 ให้ใช้คำขอปรับระดับ (ย้ายสายงานใช้ได้เหมือนเดิม) |
| `GET /agents/{id}` | เพิ่ม `rankLevel`, `licenseValid` |
| `GET /agents/{id}/level-progress`, `/agents/level-progress-board` | ใบอนุญาตคำนวณจริง · เป้า 0 = 100% เมื่อมียอด |

Migration `2027_03_19_000100_agent_rank_rules`:
- `rank_promotions.requested_by_user_id` (FK users), `request_note` + index `(agent_id, status)`
- drop `ranks.monthly_avg_target`
- ตัวแทน `rank_id` ว่าง → Lv1 + `level = 'l1'` (dev: 393 คน)
- `AgentObserver::creating` ตั้ง Lv1 ให้ตัวแทนใหม่ · `rank_id` อยู่ในรายการ audit (เก็บค่าเก่า/ใหม่)

สิทธิ์ใหม่ `agents.rank_approve` "อนุมัติเลื่อนระดับ" (admin/super_admin ได้อัตโนมัติ · role อื่นเปิดที่ `/admin/roles`)

**Deploy:** `php artisan migrate` แล้ว `php artisan db:seed --class=RbacPermissionSeeder`

## 5. ฝั่งหน้าจอ

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `pages/agents/AgentFormPage.vue` | ระดับเป็นแสดงผล + ปุ่ม "ขอปรับระดับ" (ตามสิทธิ์) → แผงเลือกระดับ + เหตุผล + เตือนใบอนุญาต · แสดงคำขอที่รออยู่ · ประวัติแสดงประเภท, ผู้ขอ+เหตุผล, ผู้ตัดสิน, เหตุผลไม่อนุมัติ, สถานะ "ถูกแทนที่" · ไม่ส่ง `level` ตอนบันทึก |
| `pages/agents/AgentApprovals.vue` | แถวคำขอแสดงระดับปัจจุบัน → เป้าหมาย, ขึ้น/ลด, ระบบเสนอ/ขอโดยใคร, เหตุผล, ยอด 3 เดือน, สถานะใบอนุญาต (บล็อกปุ่มถ้าไม่มี) · ยืนยันอนุมัติแบบ inline · ไม่อนุมัติต้องกรอกเหตุผล (เลิกใช้ `window.prompt`) · ไม่มีสิทธิ์ = ดูอย่างเดียว |
| `pages/agents/LevelProgressBoard.vue` | การ์ด "เกณฑ์ 10 ระดับ" + คำอธิบายกฎ · แก้ทีละแถวเมื่อมี `admin.settings` |
| `pages/agents/AgentsSubnav.vue` | badge รออนุมัติรีโหลดได้ (`refreshKey`) |
| `pages/agents/AgentListV2.vue`, `AgentHierarchy.vue`, `AgentTreeNode.vue` | กรอง/badge L1–L10 ผ่าน `utils/agentLevel.ts` |
| `utils/agentLevel.ts` **(ใหม่)** | สี badge/จุดระดับ, ป้ายสถานะคำขอ, ป้ายประเภทคำขอ |
| `api/agents.ts`, `stores/agents.ts`, `i18n/th.ts` | `createRankRequest`, `updateRank`, type ใหม่ · ลบ `setLevel`/`LEVEL_PCT` ที่เลี่ยงการอนุมัติ · `levelShort` l6–l10 |

## 6. การทดสอบ

```bash
DB_CONNECTION=mysql DB_DATABASE=insurehub_testing DB_USERNAME=root DB_PASSWORD= php artisan test
npm run build
```

`tests/Feature/Agent/RankPromotionTest.php` — 12 เทสต์:
- ยอดทีมนับลูกทีมระดับต่ำกว่า ไม่นับระดับเท่ากัน แต่นับลูกทีมของเขา
- หน้าต่าง 3 เดือนปฏิทิน + ชำระย้อนหลังปรับเดือนถัดไป
- วันที่ 31 ยอดเดือนก่อนลงเดือนถูก
- ลบ/แก้ยอด/แก้วันที่การชำระ → ยอดเปลี่ยน
- ถึงเป้า → pending (ข้ามระดับได้) · Lv2 → superseded เมื่อถึง Lv3 · อนุมัติ → `rank_id`/`level` + audit · ยอดทีมต้นสายคำนวณใหม่ · อนุมัติซ้ำ 422
- ไม่มีใบ/ใบหมดอายุ/มีแต่เลขไม่มีวันหมดอายุ → Lv6 · มีใบชีวิตใช้ได้ → Lv7 · ใบหมดอายุระหว่างรอ → 422 `license_required`
- ไม่อนุมัติต้องมีเหตุผล · ระดับไม่เปลี่ยน · ไม่เสนอซ้ำเดือนเดิม แต่เดือนใหม่เสนอได้
- ไม่มีสิทธิ์/role ตัวแทน → 403 · ข้าม tenant → 404 · ให้สิทธิ์แล้วอนุมัติได้
- คำขอปรับเอง: ไม่มีสิทธิ์ 403, ไม่มีเหตุผล/ระดับเดิม/Lv7 ไม่มีใบ/ซ้ำ → 422 · ระดับเปลี่ยนเมื่ออนุมัติเท่านั้น · ปรับลดได้ · PATCH ตรงไม่เปลี่ยนระดับ · hierarchy `level` 422
- อนุมัติระดับสูงกว่า → คำขอที่ต่ำกว่า superseded
- แก้เกณฑ์: ไม่มีสิทธิ์ 403, เป้าลดหลั่น/Lv1 > 0/ปิดใบอนุญาต Lv8 → 422, audit before/after, เป้าใหม่มีผลกับการเสนอ
- ตัวแทนใหม่ได้ Lv1 · ลบยอดทั้งหมดแล้ว reconcile ได้ค่าเท่ากับ realtime

ผล: ชุดใหม่ **12/12 ผ่าน** · ทั้งชุด **114/115** (ตัวที่ไม่ผ่านคือ `CommissionSnapshotTest::test_rate_row_newest_effective_from_wins` ซึ่งไม่ผ่านมาตั้งแต่ก่อนเริ่มงาน) · `npm run build` ผ่าน

ทดสอบใน Chrome (ตัวแทน `AG200011`, id 10): ขอ Lv7 → ถูกบล็อกเพราะใบวินาศหมดอายุ 2021 · ขอ Lv3 → ระดับยังเป็น 1 + "รออนุมัติ" · กล่องอนุมัติเห็นผู้ขอ/เหตุผล · ไม่อนุมัติโดยไม่กรอกเหตุผล → ถูกเตือน · กรอกแล้วไม่อนุมัติ · ขอใหม่ → อนุมัติ → ระดับ 3 + ความคืบหน้า 3 → 4 (เป้า 600,000) + ประวัติ 2 รายการพร้อมผู้ตัดสิน/เหตุผล · แก้เป้า Lv4 เป็น 200,000 → ถูกปฏิเสธ · 650,000 → บอร์ดคำนวณใหม่ (แล้วคืนเป็น 600,000) · รายการตัวแทนกรอง L3 ได้

## 7. ภาพก่อน / หลัง

อยู่ที่ `docs/tasks/task-6-agent-rank/screenshots/`

| เรื่อง | ก่อน | หลัง |
|---|---|---|
| ระดับในฟอร์มตัวแทน | `before-B1a-form-level-says-lv5.png`, `before-B1b-progress-says-level-0.jpg`, `before-B1c-save-lv7-invalid.jpg` | `after-A1a-request-lv7-license-blocked.png`, `after-A1b-request-sent-level-unchanged.png` |
| กล่องอนุมัติ | `before-B3-approval-row-no-permission-check.png` | `after-A2a-inbox-request-with-reason.jpg`, `after-A2b-reject-needs-reason.png`, `after-A3a-approve-confirm.png` |
| หลังอนุมัติ | — | `after-A3b-level3-progress-history.png` |
| เกณฑ์ 10 ระดับ | `before-B2-progress-board-all-level-0.jpg` | `after-A4a-criteria-card.jpg`, `after-A4b-criteria-validation.png`, `after-A4c-criteria-saved-board-recalc.png` |
| กรองระดับ | `before-B4-list-filter-l1-l5-only.png` | `after-A5-list-filter-l1-l10.jpg` |
| ขั้นตอนทั้งหมด | — | GIF `after-task6-rank-request-approval.gif` |

## 8. ข้อมูลทดสอบใน dev DB

- ตัวแทน 393 คน → Lv1 (จาก migration)
- `AG200011` (id 10): คำขอ Lv3 ถูกไม่อนุมัติ 1 ครั้ง แล้วอนุมัติ → **ระดับ 3**
- `AG200022` (id 20): คำขอ Lv2 "ทดสอบ badge" → ไม่อนุมัติ (ระดับยังเป็น 1)
- เป้า Lv4 ถูกแก้เป็น 650,000 แล้วคืนเป็น 600,000 (มี audit `rank.updated` 2 แถว)
- ภาพ "ก่อน" ใช้แถว `rank_promotions` ชั่วคราว (ลบแล้ว)

## 9. ข้อจำกัด / งานต่อ

- ตาราง `ranks` เป็นของทั้งระบบ (ไม่แยก tenant) — แก้เกณฑ์มีผลทุก tenant
- importer ระบบเก่า (`InsureHubImporter`) ยังเขียน `agents.level` จากความลึกสายงาน และ insert ตรงไม่ผ่าน observer → ถ้า import ซ้ำ ตัวแทนใหม่จะไม่มี `rank_id` (ถือเป็นระดับ 0) ต้องตั้ง Lv1 ให้แถวที่ `rank_id` ว่างด้วยคำสั่งเดียวกับใน migration
- ยอดยังไม่หักเงินคืน/ยกเลิกกรมธรรม์ และใช้เบี้ยรวม (rank-rules ข้อ 6.4)
- ย้ายสายงาน (เปลี่ยนต้นสาย) ไม่คำนวณยอดทีมทันที รอรอบคืน
- ค่าคอมของการชำระที่ทำให้ถึงเป้ายังคิดที่ระดับเดิม (ระดับเปลี่ยนเมื่ออนุมัติ) — เป็นไปตามการออกแบบเดิม
- ยังไม่มีการแจ้งเตือน (noti) แบบ push — ใช้ badge "รออนุมัติ (n)" ในเมนูตัวแทน
- การอนุมัติ "ตัวแทนสมัครใหม่" ยังใช้ `window.prompt` (อยู่ในขอบเขตงาน 7 หน้าตัวแทน)
- พอร์ทัลตัวแทนยังไม่แสดงระดับ/ความคืบหน้าของตัวเอง
