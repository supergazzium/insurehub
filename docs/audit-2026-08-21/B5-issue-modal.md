# B5 — Issue Policy Modal (design)

Handles the **Approved → Issued** transition in the 7-state model. Wraps
the 7 post-issue fields (`02-policy-schema.md` §4) plus an optional
certificate document upload. Reuses the existing document
upload/download endpoints referenced from `PolicyListV2.vue:688-730`
(`03-wizard-current.md` §7).

## 1. When the modal is invoked

| Trigger | Location | Enable condition |
|---|---|---|
| Primary action button "ออกกรมธรรม์" | `PolicyDetailDrawer.vue` — header action row, next to Edit | `policy.status === 'approved'` |
| Row action menu item | `PolicyListV2.vue` — kebab menu on each row | `row.status === 'approved'` |
| Prominent CTA in editor | `PolicyEdit.vue` — banner at top when awaiting issue | `policy.status === 'approved'` |

For every other status the trigger is **hidden**, not disabled — a
disabled button on ~95% of rows (all `active`) is noise. Show it only
when actionable.

After a successful issue:
1. Backend flips `status = 'issued'` and stamps fields (§3).
2. Modal emits `@issued(updatedPolicy)`; caller reloads.
3. `PolicyListV2` reloads current page. `PolicyDetailDrawer` refreshes
   from the emitted payload without a second fetch. `PolicyEdit`
   re-hydrates its section state.
4. Modal closes.

## 2. Field map

Payload keys are camelCase (matches existing `PolicyResource` +
`PolicyRequest` convention). All strings trimmed; empty → `null` before
send.

| # | field | label_th | label_en | type | required? | validation | payload → column |
|---|---|---|---|---|---|---|---|
| 1 | policy_no | เลขที่กรมธรรม์ | Policy number | text | **yes** | max 64; per-tenant uniqueness is best-effort (see note) | `policyNo` → `policy_no` |
| 2 | issue_date | วันที่ออกกรมธรรม์ | Issue date | date | **yes** | `>= app_date` (if set) AND `<= today` (Asia/Bangkok) | `issueDate` → `issue_date` |
| 3 | period_paid_end | ระยะที่ชำระเบี้ยถึง | Premium paid through | date | no | `>= effective_date` | `periodPaidEnd` → `period_paid_end` |
| 4 | policy_end | วันสิ้นสุดกรมธรรม์ | Policy end | date | no | `>= effective_date`; may differ from `expiry_date` for early-end | `policyEnd` → `policy_end` |
| 5 | mailing_add_by_policy | ที่อยู่จัดส่งกรมธรรม์ | Mailing address (per policy) | textarea (2 rows) | no | max 255 | `mailingAddByPolicy` → `mailing_add_by_policy` |
| 6 | mailing_date | วันที่ส่งไปรษณีย์ | Mailing date | date | no | `<= today + 30d` | `mailingDate` → `mailing_date` |
| 7 | mailing_note | หมายเหตุการส่ง | Mailing note | textarea (2 rows) | no | — | `mailingNote` → `mailing_note` |
| 8 | certificate_document | ไฟล์กรมธรรม์ (PDF) | Certificate document | file | no | mime pdf/jpg/png; max 10 MB | uploaded via §5, not part of issue payload |

Note on `policy_no` uniqueness: migration `2027_01_01_000700_relax_policy_no_unique.php` relaxed the UNIQUE
constraint to an INDEX (`01-policy-state.md` migration table). Server-side we
still soft-check duplicates within `(tenant_id, policy_no NOT NULL)` and
return a **409** with a warning payload (`code: duplicate_policy_no`),
letting the operator confirm-and-proceed. Frontend renders a yellow
"เลขนี้ถูกใช้แล้วในกรมธรรม์: <link>" banner but does not block submission.

## 3. Backend endpoint

**`POST /policies/{id}/issue`** (new).

Register in `routes/api.php` alongside the other policy transition
endpoints. Handler lives in `PolicyEventController` (per B1 —
`01-policy-state.md` §8 confirms this is the state-machine chokepoint).

Guard order (all in one DB transaction):

1. `authorizeTenant($request, $policy)` — existing pattern from
   `PolicyController::show`.
2. `abort_if($policy->status !== 'approved', 409, 'not_approved')` — the
   only allowed source state.
3. Validate payload (rules per §2).
4. Update `policy` row: 7 fields + `status = 'issued'`.
5. Insert `PolicyEvent` row with verb `issued` (existing verb,
   `01-policy-state.md` §8) — payload JSON captures the field diff.
6. Return `PolicyResource($policy->fresh()->load(…))` with HTTP 200.

Payload shape (camelCase in, snake_case in DB):

```json
{
  "policyNo": "706-24-11-HHI-03839",
  "issueDate": "2026-08-21",
  "periodPaidEnd": "2027-08-20",
  "policyEnd": "2027-08-20",
  "mailingAddByPolicy": "…",
  "mailingDate": "2026-08-22",
  "mailingNote": "…"
}
```

**Certificate upload — separate call.** After the issue succeeds and the
policy is Issued, if the operator attached a file, the frontend calls
the existing `POST /policies/{id}/documents/upload` with FormData
`{ file, type: 'policy', label: 'กรมธรรม์' }`. Rationale for two calls:

- **Transaction size**: the issue call flips state + writes an event
  atomically. Bundling a 10 MB PDF forces a multipart transaction that
  holds table locks longer and complicates error handling (partial
  success on upload but ok on state = mystery for the operator).
- **Reuse the proven path**: the existing upload endpoint already
  handles auth, mime sniffing, storage driver, and `policy_documents`
  bookkeeping. Duplicating that into a multipart branch of `/issue`
  adds surface for regressions.
- **Retry semantics**: if upload fails, the operator can retry via the
  attachments modal (§5) without re-issuing.

The frontend orchestrates the two calls sequentially; a failed upload
raises a non-blocking warning banner but does NOT roll back the issue.

## 4. Frontend component

New file: **`frontend/src/pages/policies/IssuePolicyModal.vue`**.

Props / emits:

```ts
props: { open: boolean; policyId: string | null }
emits: {
  close: []
  issued: [policy: Policy]  // fresh PolicyResource shape
}
```

Layout — single-column modal, centered, `max-w-lg`:

```
┌──────────────────────────────────────────┐
│  ออกกรมธรรม์                      [ × ]  │
├──────────────────────────────────────────┤
│  เลขที่กรมธรรม์ *          [_____________] │
│  วันที่ออกกรมธรรม์ *       [__/__/____]    │
│  ระยะที่ชำระเบี้ยถึง       [__/__/____]    │
│  วันสิ้นสุดกรมธรรม์         [__/__/____]    │
│  ที่อยู่จัดส่ง             [ textarea ]    │
│  วันที่ส่งไปรษณีย์         [__/__/____]    │
│  หมายเหตุการส่ง            [ textarea ]    │
│  ไฟล์กรมธรรม์ (PDF)        [ Choose… ]    │
├──────────────────────────────────────────┤
│                  [ ยกเลิก ]  [ ออกกรมธรรม์ ] │
└──────────────────────────────────────────┘
```

Prefill on open (fetches `/policies/{id}` first if the caller didn't
pass a full Policy):

- `issue_date` → today (Asia/Bangkok)
- `period_paid_end` → `effective_date + product.duration_years years - 1 day`
- `policy_end` → `expiry_date`
- `mailing_add_by_policy` → `customer.mailing_address` (fallback to
  `customer.address` if empty)
- `mailing_date`, `mailing_note` → empty
- `policy_no` → empty (operator types the carrier's issued number)

Auto-recompute: when the operator changes `policy_no` OR `issue_date`,
recompute `period_paid_end` and `policy_end` from the new `issue_date`
IF those fields are still untouched. Reuse the touched-flag pattern
from `PolicyCreateWizard.vue:673`:

```ts
const periodPaidEndTouched = ref(false)
const policyEndTouched = ref(false)
watch(() => form.issueDate, (d) => {
  if (!periodPaidEndTouched.value) form.periodPaidEnd = addYears(d, years) - 1
  if (!policyEndTouched.value)    form.policyEnd    = form.expiryDate  // static default
})
```

Reuse existing primitives:
- `DateInput.vue` (already fixed for dd/mm/yyyy) for all 3 date fields
- `FormField.vue` for label + error + hint layout

## 5. Certificate document upload

Handled AFTER the issue call returns 200. Pseudo-flow:

```
1. POST /policies/{id}/issue  → 200 + updated policy
2. If file selected:
     POST /policies/{id}/documents/upload  (multipart, type='policy')
     - Success → attach doc to emitted policy payload
     - Failure → console.warn, show yellow banner:
       "ออกกรมธรรม์เรียบร้อย แต่แนบไฟล์ไม่สำเร็จ — ลองใหม่ผ่านเมนูเอกสารแนบ"
       Do NOT rollback. Do NOT block close.
3. Emit @issued(policy) and close.
```

Doc type = `policy` (from `03-wizard-current.md` §7 enum:
`application/policy/receipt/medical/endorsement/cancellation/other`).
Label = `'กรมธรรม์'` (localized via `policyIssue.f.certificate` reused).
Download goes through existing `policyDocumentDownloadUrl` — no new URL
needed.

## 6. State locking after issue

Existing `LOCK_TRIGGER_STATUSES = ['issued','active','lapsed','cancelled','reinstated','expired']`
at `Api/PolicyController.php:192` (`01-policy-state.md` §8) — already
gates edits on issued+. The set below defines which fields lock when
status enters those states. Per B1's field-lock map:

**Lock on entry to Issued** (require an endorsement to edit):
- `policy_no`, `issue_date`
- `customer_id`, `product_id`, `carrier_id`, `writing_agent_id`
- `effective_date`, `expiry_date`
- `net_premium`, `main_premium`, `duty_stamp`, `vat`, `total_premium_paid`
- All `main_com_*` fields (audit revealed these were dropped and moved
  to `policy_rebates` / commission tables — see `02-policy-schema.md`
  line 151. Update the lock list accordingly.)

**Stay editable after Issued**:
- `mailing_add_by_policy`, `mailing_date`, `mailing_note`
- `notes`, `internal_note`, `status_note`
- `next_premium_due` (installment tracking)
- Payment fields (payment_amount, payment_date, count_slip, etc. —
  operator still records inflows post-issue)

Additions needed vs current `LOCKED_AFTER_ISSUED`: verify the current
list against the "Lock on entry" list above — file a follow-up ticket
if any of the 12 listed fields aren't already in it.

## 7. Rejected path (adjacent, out of scope)

`RejectPolicyModal.vue` handles the Approved → Rejected transition (or
Submitted → Rejected if the carrier declines before pseudo-approval).
Different verb (`rejected`), different required fields (rejection
reason, rejection date), no mailing block, no certificate. Ship as a
sibling doc after B5.

Guard the two triggers side-by-side in `PolicyDetailDrawer` header:
"ออกกรมธรรม์" (green primary) + "ปฏิเสธ" (red secondary), both visible
only when `status === 'approved'`.

## 8. i18n keys

New namespace **`policyIssue.*`** — do NOT nest under `policyCreate` or
`policyEdit` (semantically distinct action, different trigger, different
audience).

```
policyIssue.title              ออกกรมธรรม์                        Issue policy
policyIssue.open               ออกกรมธรรม์                        Issue policy
policyIssue.cancel             ยกเลิก                             Cancel
policyIssue.submit             ออกกรมธรรม์                        Issue policy
policyIssue.submitting         กำลังบันทึก…                        Saving…

policyIssue.f.policyNo         เลขที่กรมธรรม์                      Policy number
policyIssue.f.issueDate        วันที่ออกกรมธรรม์                    Issue date
policyIssue.f.periodPaidEnd    ระยะที่ชำระเบี้ยถึง                  Premium paid through
policyIssue.f.policyEnd        วันสิ้นสุดกรมธรรม์                    Policy end
policyIssue.f.mailingAddByPolicy ที่อยู่จัดส่งกรมธรรม์               Mailing address
policyIssue.f.mailingDate      วันที่ส่งไปรษณีย์                    Mailing date
policyIssue.f.mailingNote      หมายเหตุการส่ง                      Mailing note
policyIssue.f.certificate      ไฟล์กรมธรรม์ (PDF)                  Certificate document (PDF)

policyIssue.hint.policyNo      เลขที่บริษัทประกันออกให้             Number issued by the carrier
policyIssue.hint.issueDate     ต้องเป็นวันในอดีตหรือปัจจุบัน         Must be today or earlier
policyIssue.hint.periodPaidEnd คำนวณอัตโนมัติจากวันเริ่มคุ้มครอง     Auto-computed from effective date
policyIssue.hint.policyEnd     เท่ากับวันสิ้นสุดความคุ้มครองโดยดีฟอลต์  Defaults to expiry date
policyIssue.hint.mailingAddByPolicy ดึงจากที่อยู่ลูกค้าโดยดีฟอลต์    Pre-filled from customer address

policyIssue.error.notApproved      ต้องอยู่ในสถานะ Approved ก่อน       Policy must be in Approved status
policyIssue.error.policyNoRequired ต้องระบุเลขที่กรมธรรม์               Policy number is required
policyIssue.error.issueDateRequired ต้องระบุวันที่ออกกรมธรรม์            Issue date is required
policyIssue.error.issueDateFuture  วันที่ออกกรมธรรม์ต้องไม่อยู่ในอนาคต    Issue date cannot be in the future
policyIssue.error.duplicatePolicyNo เลขนี้ถูกใช้แล้ว: {ref}             This number is already used: {ref}
policyIssue.error.uploadFailed     ออกกรมธรรม์แล้ว แต่แนบไฟล์ไม่สำเร็จ  Issued, but file upload failed
policyIssue.error.generic          บันทึกไม่สำเร็จ ลองใหม่               Save failed, try again

policyIssue.success                ออกกรมธรรม์เรียบร้อย                Policy issued
```

## 9. Testing plan

**Backend (Feature test — `tests/Feature/PolicyIssueTest.php`)**:
- rejects when `status != approved` (each of the other 9 states → 409)
- rejects when `policy_no` missing (422)
- rejects when `issue_date` in the future (422)
- happy path: flips status, sets 7 fields, writes `PolicyEvent`, returns 200
- soft duplicate: same `policy_no` on another row in same tenant → 409 with warning payload
- tenant isolation: another tenant's approved policy → 404

**Frontend (component test — Vitest + `@vue/test-utils`)**:
- mounts closed → no DOM; open with mocked Approved policy → renders 7 fields prefilled
- submit with only required fields → emits `@issued`, closes
- submit with `policy_no` triggering 409 → warning banner rendered, form stays open
- file selected → upload call fires after issue; upload failure → warning banner + still emits `@issued`
- `issue_date` change → `period_paid_end` and `policy_end` auto-recompute UNTIL touched

**Regression**:
- Existing `PolicyListV2.vue:688-730` attachments modal opens for Issued
  policies and lists the newly-uploaded certificate document.
- `PolicyDetailDrawer` "Overview" section renders the new `policy_no`
  and `issue_date` values without a page refresh.

## 10. Open questions

1. **Soft duplicate policy_no** — accept the current "warn but allow"
   posture, or hard-block on duplicate within tenant? (Migration
   history shows a deliberate relaxation, but that predates the state
   machine.)
2. **Auto-fill `period_paid_end` for installment policies** — should the
   default account for `premium_mode` (`monthly` → next month, not next
   year)? Current default assumes annual.
3. **Certificate mandatory** — some carriers require a PDF on file
   before Issued; should we make the file required for certain
   `product_type.kind` values (config-driven), or keep optional?
4. **Batch issue** — carriers sometimes deliver a spreadsheet of 20
   issued policies in one email. Is a bulk-issue flow in scope for a
   later iteration?
5. **Event log surfacing** — the drawer today doesn't show `PolicyEvent`
   rows. Do we ship a "History" section as part of this rollout, or
   defer?