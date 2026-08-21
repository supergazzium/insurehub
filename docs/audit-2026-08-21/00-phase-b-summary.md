# Phase B — Design docs consolidated summary

5 design docs land in this directory. Read them for detail; this file is
the decision brief for green-lighting Phase C implementation.

| Doc | Topic | Ships in PR(s) |
|---|---|---|
| B1-state-machine.md | 10-code enum, 15 transitions, 5-PR rollout | PR-1..5 |
| B2-schema-plan.md | `product_types.kind`+`risk_schema` + `policies.risk_data` + shim + gated drop | PR-A..G |
| B3-wizard-ia.md | 5-step wizard, chip engine, save-draft, prior-asset autofill | PR-Wiz-1..3 |
| B4-risk-schema.md | JSON contract + 6 worked schemas + renderer spec | PR-A + PR-Schema-Seed |
| B5-issue-modal.md | Post-issue modal for Approved→Issued transition | PR-Issue |

## Cross-doc coherence check

The five docs align cleanly. Highlights:

- **State code** for Quotation: B1 renames `quote → quotation`. B3 uses
  `quotation` throughout. B2/B4/B5 don't reference the code. ✔
- **`risk_data` writer path**: B2 shim writes both top-level and JSON.
  B3 Step 3 emits payload via the same shim — no bypass. B4's
  `storage: column | risk_data` hint maps 1:1 to what the shim expects.
  ✔
- **Product-type payload**: B2 recommends optional `productType` sub-object
  on ProductResource. B3 assumes it's present for Step 3 renderer. B4
  documents the exact fields consumed. Ship B2's optional field as
  *required* to avoid a second fetch. ✔
- **Locking**: B1 §6 field-lock map matches B5 §6 lock list. B2's
  writer shim honours locks via existing `LOCKED_AFTER_ISSUED`
  discipline. ✔
- **Actions**: B3 Step 5 has three buttons; B1 §5 has verbs for each
  (`draftCreated` / `quotationMinted` / `submittedFromDraft` /
  `convertedToApplication`). ✔
- **Duration data → chips**: B3 §3 chip presets match `05-live-data.md`
  histogram exactly. ✔

## Phase B open questions — consolidated

Grouped by urgency. **P0** = must resolve before Phase C starts.
**P1** = resolve during Phase C implementation. **P2** = can defer to
post-launch.

### P0 — resolve before Phase C

| # | Doc | Question | Recommendation |
|---|---|---|---|
| 1 | B1 §10 Q1 | Is `approved` a real Thai carrier state, or vestigial? If underwriters never verbally approve before assigning a policy_no, `approved` becomes unused and wizard jumps `submitted → issued`. | **Ship it as-is (real state).** Even if unused day 1, the modeled state gates the field-lock map cleanly (financial fields freeze at `approved`, dates at `issued`). Cost of an unused state = 0 rows. Cost of retrofitting later = data migration. |
| 2 | B3 §10 Q2 | Draft ownership — are drafts visible to Platform Admin regardless of `writing_agent_id`? | **Yes.** Admin needs visibility for support/cleanup. Add `?scope=all` to the drafts list, admin-only. |
| 3 | B3 §10 Q3 | Allow `quotation → draft` demote? | **No.** B1 matrix already blocks. Operator cancels quotation and starts a new draft. Documented in the release notes. |
| 4 | B5 §10 Q1 | Soft-duplicate `policy_no` — warn or hard-block? | **Warn.** Historical migration relaxed the unique constraint deliberately (multiple tenants, mistaken duplicates fixed post-hoc). 409 + confirm-and-proceed matches operator reality. |
| 5 | B2 §10 Q1 | `sum_assured` under `life` or `health` (or both)? | **Both** at write time, keyed on `productType.kind`. Reader picks by kind. Documented in B4 §2 examples. |

### P1 — during implementation

| # | Doc | Question |
|---|---|---|
| 6 | B1 §10 Q2 | Free-look `issued → cancelled` gated by kind? (life/health yes, motor no) — resolve when RejectPolicyModal ships. |
| 7 | B1 §10 Q4 | Carrier-webhook actor scaffold now, or defer to specific integration? — defer; leave a stub method. |
| 8 | B1 §10 Q5 | Re-tighten `(tenant_id, policy_no)` unique for Issued+ rows via partial/functional index? — verify MySQL 8 partial-index support; if OK, ship as a follow-up PR. |
| 9 | B3 §10 Q5 | Save-as-Quotation button availability logic (client Q-gate vs backend-validates)? — backend-validates; button available whenever Draft-safe fills present. |
| 10 | B5 §10 Q2 | Auto-fill `period_paid_end` account for `premium_mode` (monthly vs annual)? — yes; the default should be `effective_date + (period_paid_years / mode_multiplier)`. |
| 11 | B4 (implicit) | ProductResource passthrough `productType` — required or optional? — required (per Phase B summary). |

### P2 — post-launch

| # | Doc | Question |
|---|---|---|
| 12 | B1 §10 Q3 | Lapse scheduler — needs `grace_days` on products/product_types. Defer. |
| 13 | B3 §10 Q1 | Drop `insureType` toggle once product picker filters directly? Ship as-is for now. |
| 14 | B3 §10 Q4 | Traveler PII masking across customers in prior-asset endpoint. Ship customer-scoped only. |
| 15 | B5 §10 Q3 | Certificate mandatory per product_type.kind (config-driven)? — nice-to-have. |
| 16 | B5 §10 Q4 | Batch issue flow (CSV upload from carrier). Backlog. |
| 17 | B5 §10 Q5 | PolicyEvent history section in drawer. Backlog. |
| 18 | B2 §10 Q2 | Retire `vehicle_on_non_motor` bool after backfill? Confirm during shim soak. |
| 19 | B2 §10 Q4 | Retire `ProductKind::derive()` fallback? After 6+ months of shim, yes. |
| 20 | B2 §10 Q5 | Pre-drop mysqldump retention — 90 days proposed. Confirm ops policy. |

## Phase C PR queue — sequenced

Merging B1's PR-1..5 + B2's PR-A..G + B3's Wiz + B5 into a single deploy
sequence. Each PR is independently deployable and reversible.

| # | PR | Depends on | Ships | Rollback safety |
|---|---|---|---|---|
| **C-1** | Migration + backfill: `policy_statuses` gains 10 rows (from B1 §9 PR-1) | none | Enum data | drop new rows |
| **C-2** | Backfill command: `policies:backfill-statuses --dry-run` then live (B1 §9 PR-2) | C-1 | Data | idempotent; down = rerun with old map |
| **C-3** | Migration: `product_types.kind` + `risk_schema` + 26-row kind backfill (B2 §1) | none | Schema | drop columns |
| **C-4** | Migration: `policies.risk_data` + Model cast + reader/writer shim + PolicyResource emits `risk` sub-object with legacy blocks kept (B2 §2, §3, §6) | C-3 | Schema + shim | drop column; legacy path still reads columns |
| **C-5** | Backfill command: `policies:backfill-risk-data --dry-run` then live (B2 §4) | C-4 | Data | idempotent; down = NULL out risk_data |
| **C-6** | Backend enum + transition guards in `PolicyEventController` + retire `reinstated`, retarget `convertedToApplication` (B1 §5) | C-2 | Behavior | legacy alias for one release |
| **C-7** | Frontend: `utils/policyStatus.ts` + kill hardcoded strings + list/drawer/edit badge refactor + i18n `policies.status.*` block (B1 §9 PR-4) | C-6 | UX | old badges are hardcoded; can revert file-by-file |
| **C-8** | `IssuePolicyModal.vue` + `POST /policies/{id}/issue` endpoint + `policyIssue.*` i18n (B5) | C-6, C-7 | Feature | modal hidden behind status check |
| **C-9** | Admin `AdminProductTypes.vue` gains `kind` select + "Edit risk schema…" JSON editor (B2 §1 + B4 §8) | C-3 | Admin UI | additive UI |
| **C-10** | Seed 6 risk_schemas (from B4 §2 worked examples) via `ProductTypeSeeder` update | C-3, C-9 | Data | rerun with prior seed |
| **C-11** | Backend endpoints: `POST /policies/draft`, `PATCH /policies/{id}/draft`, promote endpoints, `DELETE /policies/{id}` guard (B3 §7) | C-6 | API | endpoints net-new; no legacy caller |
| **C-12** | Backend endpoint: `GET /customers/{id}/prior-assets` (B3 §4) | C-5 | API | net-new |
| **C-13** | `<DurationChip>`, `<RiskFieldRenderer>`, `<EntityPicker>` shared components (B3 §9 NEW) | C-4, C-10 | UI primitives | net-new |
| **C-14** | New 5-step wizard `PolicyApplicationWizard.vue` replaces `PolicyCreateWizard.vue` (B3 §1-9) | C-11, C-12, C-13 | Feature | keep old wizard around one release as `/policies/new-legacy` for rollback |
| **C-15** | Drafts tab + resume flow + retention cron (B3 §6, §7) | C-14 | Feature | additive |
| **C-16** | `TransitionPoliciesDaily` command + schedule (B1 §7) | C-6 | Feature | disable schedule to rollback |
| **C-17** | Soak period 2-4 weeks — watch `risk_shim.log` fallback counter | C-5 | — | — |
| **C-18** | Set `POLICY_RISK_SHIM_COMPLETE=true` + Migration: drop 29 retired risk columns (B2 §5) | C-17 | Schema | pre-flight mysqldump; rollback = restore |
| **C-19** | Cleanup: delete legacy `motor`/`property` blocks from PolicyResource, delete fallback typing, delete shim helper's column branch (B2 §9 PR-G) | C-18 | Code cleanup | additive delete; git revert |
| **C-20** | Legacy wizard removal + `/quotes` route audit + drop retired enum aliases (B1 §9 PR-5) | C-14, C-19 | Cleanup | git revert |

Optimistic ordering — many can parallelize. Critical path: C-1 → C-2 →
C-6 → C-14 → C-17 → C-18 → C-19 → C-20. Everything else fans out from
the critical path.

## What we're NOT doing (yet)

- **Premium math changes** — B3 §9 KEEPs L556-581 watchers verbatim.
  Not touching commission math.
- **Products page redesign** — B2 confirms zero edits to
  `ProductManagementV2.vue` / `ProductCreateModal.vue` /
  `ProductDetailDrawer.vue`.
- **Reject/Cancel modals** — B5 §7 notes RejectPolicyModal is adjacent.
  A separate `CancelPolicyModal.vue` handles refund workflow. Both
  ship after C-14 lands.
- **Renewals** — the existing `refAppToId` prefill (wizard L470-541) is
  KEPT. Formal renewal UI is post-launch backlog.
- **Endorsements** — the existing endpoint at `PolicyEdit.vue:1011`
  handles field edits after Issued. Untouched by this refactor.

## Ready to start Phase C when

All 5 P0 questions answered:
1. Approved as real state (recommend yes)
2. Admin sees all drafts (recommend yes)
3. No quotation→draft demote (recommend confirmed)
4. Warn on duplicate policy_no (recommend confirmed)
5. sum_assured under both life+health (recommend confirmed)

If you say "yes to all P0 recommendations", I'll start Phase C with C-1
and C-3 (both are additive-only schema PRs with zero risk to existing
data). They can ship in parallel.
