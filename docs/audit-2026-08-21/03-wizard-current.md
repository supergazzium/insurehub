# Wizard, Full Editor & Detail Drawer — Current State

Audit of the 3-step policy create modal, the full editor at `policyEdit.*`,
the detail drawer, and the attachments modal. Line refs pinned so the
5-step wizard rewrite has a concrete "before" it can point at.

## Summary Table

| Surface | File | Role | Verdict for 5-step rewrite |
|---|---|---|---|
| Create wizard | `frontend/src/pages/policies/PolicyCreateWizard.vue` (1617 LOC) | 3-step modal; asks for `policyNo` in Step 1 (lifecycle collision) | **REPLACE** |
| Wizard invocation | `PolicyListV2.vue:15, 686` | Only caller — one prop `open`, `@created` reloads list | KEEP contract |
| Full editor | `PolicyEdit.vue` (1084 LOC) | Section-by-section PATCH; renders `policyEdit.section.*` blocks | **KEEP** (post-issue authoring lives here) |
| Detail drawer | `PolicyDetailDrawer.vue` (482 LOC) | Read + inline edit via `EditableField` | KEEP; wire new state badges |
| Attachments modal | `PolicyListV2.vue:688-730` | List + open (Bearer-fetch → blob → new tab) | KEEP wholesale |
| i18n | `frontend/src/i18n/th.ts:1515-1642` (create), `1644-1736` (edit) | Extendable namespaces | **EXTEND** — do not rename existing keys |

---

## 1. Modal Location + Invocation

- File: `frontend/src/pages/policies/PolicyCreateWizard.vue` (1617 LOC).
- Sole caller: `PolicyListV2.vue:15` imports it; `PolicyListV2.vue:686`
  mounts it. Props: `:open="showCreate"`. Emits: `@close`, `@created` (row).
- On `@created` the list runs `page = 1; load()` (line 686) — the new wizard
  must preserve this contract or update the caller.
- No other invocation exists in the repo (`grep -rn PolicyCreateWizard`
  returns just those two lines).

## 2. Step-by-Step Field Map

### Step 1 — ข้อมูลหลัก (identity & product)

| Label (Thai) | Form key | Type | Validation | Payload key → column | Notes |
|---|---|---|---|---|---|
| ใหม่ / ต่ออายุ | `newOrRenew` | 2-button toggle | none | `newOrRenew` → `new_or_renew` | Default `'new'` |
| ต่ออายุจากกรมธรรม์เดิม | `refAppToId` | Autocomplete (typeahead `renewalSearch`) | Only visible when `newOrRenew === 'renew'` | `refAppToId` → `ref_app_to_id` | Prefills customer/product/agent/coverage/motor/property/beneficiaries on pick (line 470-541) |
| ลูกค้า | `customerId` | Typeahead (`customerSearch`, 250 ms debounce) | required for Next | `customerId` → `customer_id` | "New" opens `customers?new=1` in new tab; BroadcastChannel returns picked row (line 100-126) |
| ประเภทการประกัน | `insureType` | 3-button toggle `life`/`non-life`/`tax` | required for Next | *not sent* (derived) | Filters carriers dropdown |
| บริษัทประกัน | `carrierId` | `<select>` (loaded on `insureType` change, ≤100 rows) | required for Next | `carrierId` → `carrier_id` | Empties on `insureType` change |
| สินค้า | `productId` | Typeahead over per-carrier product list (≤200) | required for Next | `productId` → `product_id` | Reveals `product.productKind` label; drives Step 3 branch |
| ตัวแทนที่ทำสัญญา | `writingAgentId` | Typeahead (debounced) → pill | required for Next | `writingAgentId` → `writing_agent_id` | |
| เลขที่ใบสมัคร | `applicationNo` | text (`.trim`) | optional | `applicationNo` → `application_no` | |
| **เลขที่กรมธรรม์** | `policyNo` | text (`.trim`) | optional | `policyNo` → `policy_no` | **LIFECYCLE COLLISION** — cannot exist pre-approval |
| เลขที่ Notion | `notionNo` | text (`.trim`) | optional | `notionNo` → `notion_no` | |

Step 1 blockers computed at line 598-606 (`blockersStep1`).

### Step 2 — ความคุ้มครองและเบี้ย (coverage / dates / premium / installment)

Coverage & dates:

| Label | Form key | Type | Validation | Payload → column |
|---|---|---|---|---|
| วันที่สมัคร | `appDate` | `DateInput` | optional | `appDate` → `app_date` |
| ความคุ้มครอง (บาท) | `coverage` | number `min=0` | optional (line 705 sends 0) | `coverage` → `coverage` |
| วันเริ่มคุ้มครอง | `effectiveDate` | `DateInput` `:max="expiryDate"` | required | `effectiveDate` → `effective_date` |
| **วันสิ้นสุดความคุ้มครอง** | `expiryDate` | `DateInput` `:min="effectiveDate"` | required | `expiryDate` → `expiry_date` | **Manual — no duration chips.** Auto-fills to eff + 1y − 1d only when untouched (line 673-681) |
| ปีกรมธรรม์ | `policyYear` | number `min=1` | optional | `policyYear` → `policy_year` |
| ปี พ.ร.บ. | `actYear` | number `min=1` | optional | `actYear` → `act_year` |

Premium (auto-recalc on `netPremium` change; line 556-568):

| Label | Form key | Payload → column |
|---|---|---|
| เบี้ยสุทธิ | `netPremium` | `netPremium` → `net_premium` |
| เบี้ยหลัก | `mainPremium` (touched-tracked) | `mainPremium` → `main_premium` |
| อากรแสตมป์ | `dutyStamp` | `dutyStamp` → `duty_stamp` |
| ภาษีมูลค่าเพิ่ม | `vat` | `vat` → `vat` |
| รวมเบี้ยที่ต้องชำระ | `totalPremiumPaid` | `totalPremiumPaid` → `total_premium_paid` |
| ยอดหัก ณ ที่จ่าย | `whtAmt` | `whtAmt` → `wht_amt` |
| ยอดสุทธิที่ลูกค้าชำระ | `netCustomerPaid` | `netCustomerPaid` → `net_customer_paid` |

Installment:

| Label | Form key | Payload → column |
|---|---|---|
| โหมดชำระเบี้ย | `premiumMode` (`monthly`/`quarterly`/`semiannual`/`annual`/`single`) | `premiumMode` → `premium_mode` |
| งวดการผ่อน | `installmentTerm` | `installmentTerm` → `installment_term` |
| ยอดงวดแรก | `firstDueInst` | `firstDueInst` → `first_due_inst` |
| วันครบงวดแรก | `firstDueInstDate` | `firstDueInstDate` → `first_due_inst_date` |
| ยอดงวดถัดไป | `nextDueInst` | `nextDueInst` → `next_due_inst` |
| วันครบงวดสุดท้าย | `lastDueInstDate` | `lastDueInstDate` → `last_due_inst_date` |

Step 2 blockers at line 607-613: `effectiveDate`, `expiryDate`, and at least one of `netPremium`/`annualPremium` > 0.

### Step 3 — รายละเอียด (product-kind-branched)

Branch key: `kind = productPicked.value?.productKind ?? 'other'` (line 625).
Supported: `motor` / `property` / `travel` / `life` / `health` / `other`.

| Kind | Block header | Fields (form key → column) |
|---|---|---|
| **motor** | รายละเอียดรถยนต์ | `motorVehicleBrand`→`motor_vehicle_brand`, `motorVehicleModel`→`motor_vehicle_model`, `motorLicenseNo`→`motor_license_no`, `motorRegisterYear`→`motor_register_year`, `motorEngineNo`→`motor_engine_no`, `motorChassisNo`→`motor_chassis_no`, `motorTypeDriver`→`motor_type_driver`, `motorTypeVehicle`→`motor_type_vehicle`, `motorNoPassenger`→`motor_no_passenger`, `motorNotes`→`motor_notes` |
| **property** | รายละเอียดทรัพย์สิน | `propertyInsuredName`, `propertyInsuredAddress`, `propertyPhone`, `propertyBuildingCov`, `propertyFurnitureCov`, `propertyStockCov`, `propertyOtherCov`, `propertyOtherDetail`, `propertyNotes` |
| **travel** | รายละเอียดการเดินทาง | `tripDestination`, `tripStart`, `tripEnd`, `travelerCount`, `travelerPassport` |
| **life** | ผู้เอาประกัน + ผู้รับผลประโยชน์ + Riders | Shares life/health block (`insuredPersonName`, `insuredPersonIdCard`, `insuredPersonBirthDate`, `sumAssured`, `premiumPayingTerm`, `healthDeclaration`); adds multi-row `beneficiaries` (0-4, share sum = 100 gate at line 619) and 5-slot fixed `riders[]` |
| **health** | ผู้เอาประกัน + single beneficiary | Same person block; adds `healthBeneficiaryName`, `healthBeneficiaryRelation` |
| **other** | — | Falls through to commission + status + notes only |

Always shown at end of Step 3:

- **Commission override** — `mainComRateInh`, `mainComAmtInh`, `mainComRateAg`, `mainComAmtAg` (amount auto-derives from rate × net; line 574-581).
- **Status `<select>`** — hardcoded options at line 1553-1558: `quote`/`application`(motor only)/`submitted`/`issued`/`active`. Motor default `application`, non-motor default `submitted` (line 650-653). **This is the enum the audit flags as semantically broken.**
- **notes** → `notes`.

## 3. Shared State

- One `reactive({...})` object named `form` (line 207-294), 60+ keys.
- No Pinia store used inside the wizard. No provide/inject. No child components — every input is inline.
- Watchers (line 556-681):
  - `netPremium` → recomputes `dutyStamp`, `vat`, `totalPremiumPaid`, `netCustomerPaid`; mirrors to `mainPremium`/`annualPremium` unless touched flags say otherwise.
  - `whtAmt` → recomputes `netCustomerPaid`.
  - `mainComRateInh` / `mainComRateAg` → recompute `mainComAmtInh` / `mainComAmtAg` from net.
  - `kind` (product-kind) → auto-picks initial `status` (`application` for motor, `submitted` for other) until `statusTouched`.
  - `customerPicked + kind (life/health)` → auto-fills insured person name + id_card from customer until `insuredPersonTouched`.
  - `effectiveDate` → auto-fills `expiryDate` to eff + 1y − 1d until `expiryTouched`.
- Inter-step validation: none. Each step's `blockers*` guard is local; Step 3 has no gates other than the life beneficiary sum (line 619-622).
- Reset on `open` (line 853-894): full `Object.assign(form, {…})` restore + touched-flag reset.

## 4. Payload Construction + Submit

- Endpoint: `POST /policies` via `api.post(...)` (line 826).
- Payload built in `computed payload` (line 689-817): base object always sent; motor / property / travel / life / health blocks merged conditionally on `kind.value`.
- All numeric IDs coerced via `Number(...)` (line 691-694). Strings routed through `trim()` (line 684-687) that returns `null` for empty.
- Rider filtering (line 764-776): `riders` only sent for `kind === 'life'` and only rows with non-empty name.
- Error handling (line 830-846):
  - `ApiError` — reads `e.body.message` and `e.body.errors`; auto-jumps to earliest step containing failing key (Step 1 identity keys, Step 3 for `motor*`/`property*`/`rider*`/`beneficiar*`/`mainCom*`, else Step 2).
  - Other errors — `e.message` into `error.value`.

## 5. Full Editor — `PolicyEdit.vue`

Path: `frontend/src/pages/policies/PolicyEdit.vue` (1084 LOC). Route:
mounted under `/insurehub/policies/:id/edit` (naming: `policyEdit.title`).

Sections (all `t('policyEdit.section.*')`):

| Section | File line | Read/write |
|---|---|---|
| Parties (read-only) | `469` | display `{customer, product, carrier, writingAgent}` |
| Identifiers | `485` — save handler `save('identifiers', …)` at line 318 | `policyNo`, `notionNo` |
| Dates | `511` | `effectiveDate`, `expiryDate`, `policyEnd`, `periodPaidEnd`, `mailingDate`, `appDate`, `policyYear`, `actYear`, `newOrRenew` |
| Premium | `570` | net/main/duty/vat/total, coverage, discount, credit card fee, WHT amt+status, front-end fee, annual premium |
| Payment | `619` | premiumMode, installmentTerm, typeOfPaid+note, financeCompany, subsidies, first/last due |
| Commission | `687` — plus `recompute` button (line 297-315) | rates/amounts + status |
| Notes | `755` | internal, mailing, status |
| Motor | `785` | brand, model, register year, license, chassis, engine, vehicle type, driver type, passenger count, notes |
| Riders | `844` | multi-row edit; save via `save('riders', …)` |
| Beneficiaries | `894` | multi-row; sum-100 rule |
| Documents | `949` | upload + delete + open |
| Endorsements | `1011` | append-only ledger |

Save pattern: `patchPolicySection(id, section, payload)` per section
(line 318-334). Locked-after-issued hint at line 452 (`policyEdit.lockedHint`).

## 6. Detail Drawer — `PolicyDetailDrawer.vue`

Path: `frontend/src/pages/policies/PolicyDetailDrawer.vue` (482 LOC).
Opens on row click via `PolicyListV2` `detailId` prop (line 685).

Sections (H3 headers, line refs from `sections` grep):

| Section | Line | Rendered when |
|---|---|---|
| Overview | 170-212 | always (customer, product, dates, mode) |
| Premium | 215-232 | always |
| Main-product commission | 235-243 | `policy.mainCommission` present |
| Installment | 246-262 | `policy.installment` present |
| Withholding tax | 265-271 | `policy.wht.status \|\| amount` |
| Riders | 274-304 | `policy.riders?.length` |
| Beneficiaries | 307-331 | `policy.beneficiaries?.length` |
| Motor | 334-352 | `policy.motor` present |
| Property | 355-367 | `policy.property` present |
| Payments | 370-398 | `policy.payments?.length` |
| Cancellation / refund | 401-413 | `policy.cancellation` present |
| Rebate ledger | 416-432 | `policy.rebate` present |
| Mailing | 435-442 | address/date/note set |
| Notes | 445-453 | always |

All editable rows use `<EditableField entity="policies" :id :field :value @update>` for click-to-PATCH.

## 7. Attachments Modal

- Lives inside `PolicyListV2.vue:688-730` (not a separate component).
- Trigger: line 621 — row's เอกสารแนบ button opens `openDocsModal(policyId, label)`.
- Loader: `openDocsModal` (line 34-48) calls `fetchPolicy(id)` (from `api/policies.ts:72`) and reads `data.documents`.
- Open flow (line 56-71): bearer-authed fetch of `policyDocumentDownloadUrl(policyId, docId)` (`api/policies.ts:122`, path `policies/:id/documents/:docId/download`) → blob → object URL → `window.open` → revoke after 30 s.
- Doc types (line 73-80): `application` / `policy` / `receipt` / `medical` / `endorsement` / `cancellation` / `other`.

## 8. Reusable Components

- `frontend/src/components/DateInput.vue` — VueDatepicker wrapper (dd/mm/yyyy display, `yyyy-MM-dd` model). Already fixed. **Reuse in new wizard.**
- `frontend/src/components/FormField.vue` — label + error-key + hint layout used everywhere in wizard. **Reuse.**
- `frontend/src/components/EditableField.vue` — click-to-edit PATCH primitive used in drawer. **Reuse when new detail sections show post-issue fields.**
- Autocomplete pattern (customer/agent/renewal/product) is inline in the wizard — refactor into a shared `<EntityPicker>` while rebuilding, since the same pattern also lives in PolicyEdit's parties section.
- Cross-tab creation bridge (`BroadcastChannel('insurehub')` for `customer:created`/`product:created`) at line 92-183 is a strong reusable pattern; keep it verbatim.

## 9. i18n Keys Inventory

`frontend/src/i18n/th.ts`:

- `policyCreate.*` — lines **1515-1642**. Top-level keys: `title`, `cancel`, `back`, `next`, `create`, `saving`, `footerHint`, `step.{1,2,3}`, `newOrRenew`, `new`, `renew`, `renewalSource`, `renewalSourceHint`, `renewalSourcePlaceholder`, `prefilling`, `customer`, `customerPlaceholder`, `newCustomer`, `newCustomerHint`, `insureType`, `insureTypeHint`, `insureTypeOpt.{life,nonLife,tax}`, `carrier`, `carrierPlaceholder`, `carrierLoading`, `carrierEmpty`, `product`, `productPlaceholder`, `productLoading`, `productEmpty`, `productBlockedPlaceholder`, `productBlockedHint`, `newProduct`, `newProductHint`, `agent`, `agentPlaceholder`, `applicationNo`, `policyNo`, `notionNo`, `productKindLabel`, `kind.{motor,life,travel,property,health,other}`, `appDate`, `coverage`, `effectiveDate`, `expiryDate`, `policyYear`, `actYear`, `premiumBreakdown`, `autoRecalc`, `netPremium`, `mainPremium`, `dutyStamp`, `vat`, `totalPremiumPaid`, `whtAmt`, `netCustomerPaid`, `installment`, `premiumMode`, `installmentTerm`, `firstDueInst`, `firstDueInstDate`, `nextDueInst`, `lastDueInstDate`, `step3IntroFor`, `motor.{title,brand,model,licenseNo,registerYear,engineNo,chassisNo,driverType,vehicleType,passengerCount,notes}`, `property.{title,insuredName,insuredAddress,phone,buildingCov,furnitureCov,stockCov,otherCov,otherDetail}`, `beneficiaries.{title,name,relation,share,empty,total}`, `riders.{title,name,namePlaceholder,premium,rateInh,rateAg}`, `commission.{title,hint,rateInh,amtInh,rateAg,amtAg}`, `status`, `notes`, `addRow`.
- `policyEdit.*` — lines **1644-1736**. Top-level: `title`, `saveAll`, `save`, `saved`, `openFullEditor`, `lockedHint`, `section.{parties,identifiers,dates,premium,payment,notes,motor,riders,beneficiaries,documents,endorsements,commission}`, `premium.autoCalc`, `commission.{hint,rateInh,amtInh,rateAg,amtAg,recCheck,recCheckHint,recompute,recomputeHint,recomputeConfirm}`, `endorsements.{type,dateChange,coverageChange,cancelReissue,other,effectiveDate,effectiveFrom,reason,reasonPlaceholder,add,date,empty}`, `addRow`, `riders.{name,premium,notes,empty}`, `beneficiaries.{name,relation,share,total,overLimit,empty}`, `docs.{name,type,uploadedAt,upload,uploading,empty,confirmDelete}`, `f.*` (36 field labels covering customer/product/carrier/agent, policy numbers, dates, premium, WHT, installment, subsidies, notes, motor).

`policyCreate.kind.*` and `policyEdit.f.motor*` mirror the branch logic in
the wizard and the Motor section in the editor — the 5-step wizard can
reuse these directly. Missing keys that will need adding: any new
`stage` / `application` / `issue` / duration-chip / quotation labels.

`en.ts` (924 LOC) has parallel `policyCreate` / `policyEdit` blocks — same
shape; must be kept in sync when new keys land.

## 10. Recommendation — KEEP / REBUILT / REPLACE

1. **REPLACE** `PolicyCreateWizard.vue` wholesale. Split into `PolicyApplicationWizard.vue` (5 steps, pre-issue only) + `IssuePolicyModal.vue` (post-issue fields). Do NOT ship as an incremental edit — the state machine change requires a new step layout.
2. **KEEP** the invocation contract from `PolicyListV2.vue:686` (`:open`, `@close`, `@created`) — the new wizard drops in behind the same button.
3. **KEEP + EXTEND** `policyCreate.*` i18n keys; add `policyCreate.stage.*`, `policyCreate.duration.{3d,5mo,1y,3y,5y}`, `policyCreate.issue.*`. No renames.
4. **KEEP** `PolicyEdit.vue` unchanged; it already handles post-issue authoring section-by-section and matches the "1 policy record, gated fields" pattern the new state machine wants.
5. **REBUILT** — Step 3 branching logic → JSON-schema `risk_data` renderer. Steal the current motor / property / travel / life / health field lists as the seed content for `product_type.risk_schema` (§4 of `02-policy-schema.md`).
6. **REBUILT** — status `<select>` at `PolicyCreateWizard.vue:1550-1558`. Replace with a stage-badge (Draft / Quotation / Submitted / Approved / Issued / Active / Expired). The wizard itself should never let the operator set `status`; state transitions come from action buttons ("บันทึกฉบับร่าง", "ส่งพิจารณา", "ออกกรมธรรม์").
7. **KEEP** the auto-recalc premium/duty/VAT watchers verbatim (`PolicyCreateWizard.vue:556-581`). Do not rewrite commission math — the constraints doc calls this out explicitly.
8. **KEEP** the BroadcastChannel bridge (`92-183`) and the renewal prefill (`470-541`); both are strictly better than what a rewrite would produce.
9. **KEEP** `PolicyDetailDrawer.vue` structure; add a new "Application" section that shows Draft/Quotation-stage fields when the record has not yet been issued.
10. **KEEP** the attachments modal implementation in `PolicyListV2.vue:688-730`. When the "Issue Policy" modal lands, its "certificate attachments" section should reuse `policyDocumentDownloadUrl` and the same upload endpoint (`policies/:id/documents/upload`).
