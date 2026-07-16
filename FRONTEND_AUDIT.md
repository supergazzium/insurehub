# Frontend Data-Coverage Audit

Snapshot of every page and what data source it uses vs what data actually
exists in the Laravel DB. Used to plan the "systematic display" work.

## Data available in Laravel (single tenant, tenant_id=1)

| Table | Rows | Endpoint returning it |
|---|---:|---|
| carriers | 45 | `GET /api/v1/carriers` (paginated, joined) |
| products | 900 | `GET /api/v1/products` (paginated, joined) |
| product_commission_rate_installments | 1,990 | 🆕 needs endpoint |
| agents | 395 | `GET /api/v1/agents` (paginated, joined) |
| customers | 11,003 | `GET /api/v1/customers` (paginated, joined) |
| policies | 19,815 | `GET /api/v1/policies` (paginated, joined) |
| policy_riders | 3,181 | eager-loaded on `GET /policies/{id}` |
| policy_beneficiaries | 6,239 | eager-loaded on `GET /policies/{id}` |
| policy_payments | 18,687 | eager-loaded on `GET /policies/{id}` |
| policy_rebates | 19,741 | eager-loaded on `GET /policies/{id}` |
| applications_import_failures | 304 | `GET /api/v1/import-failures` |

Plus the analytics endpoints in `/api/v1/reports/*` (Phase 4).

## Page-by-page audit

### ✅ Live-data pages (already server-paginated + joined)

| Page | Route | Store | Status |
|---|---|---|---|
| PolicyListV2.vue | `/policies` | policies | ✅ Server-paginated. Missing: click-to-open detail drawer |
| CustomerListV2.vue | `/customers` | customers | ✅ Server-paginated. Missing: detail drawer |
| AgentListV2.vue | `/agents` | agents | ✅ Server-paginated. Missing: detail drawer |
| ProductManagementV2.vue | `/products` | products | ✅ Server-paginated. Missing: detail (commission rates) |
| CarrierManagementV2.vue | `/carriers` | carriers | ✅ Server-paginated. Missing: detail (products under it) |
| Dashboard.vue | `/` | reports | ✅ Live KPIs from `/reports/dashboard-kpis` |
| ExpiringSoon.vue | `/policies/expiring` | (direct fetch) | ✅ Live |
| CommissionLedger.vue | `/commissions/ledger` | (direct fetch) | ✅ Live |
| RebateReconciliation.vue | `/commissions/rebates` | (direct fetch) | ✅ Live |
| ImportFailures.vue | `/settings/import-failures` | (direct fetch) | ✅ Live |

### ⚠️ Uses live data but with gaps

| Page | Route | Gap |
|---|---|---|
| AgentHierarchy.vue | `/agents/hierarchy` | Calls `store.load()` → shim now returns only first 100 agents. Needs full 395 load. |
| AgentRecruitment.vue | `/agents/recruitment` | Same — shim returns first 100. |
| CommissionEngine.vue | `/commissions/engine` | Depends on `policyStore.policies` full-load. Currently gets first 100 only. |
| CustomerReferral.vue | `/customers/referrals` | Depends on `customerStore.customers` full-load. Same story. |

### 🚫 Demo-data pages (hardcoded, ignore imported data)

| Page | Route | Fix |
|---|---|---|
| ContractManagement.vue | `/contracts` | No contracts imported yet. Leave as placeholder; add later when contracts backfill is planned. |
| TenantSettings.vue | `/settings` | Tenant is real (`insurehub-legacy`); needs API wiring. Small — do later. |
| CarrierManagement.vue (old) | (not routed) | Replaced by V2. Dead. |
| ProductManagement.vue (old) | (not routed) | Replaced by V2. Dead. |
| PolicyList.vue (old) | (not routed) | Replaced by V2. Dead. |
| CustomerList.vue (old) | (not routed) | Replaced by V2. Dead. |
| AgentList.vue (old) | (not routed) | Replaced by V2. Dead. |

### 🚫 Auth / support pages — out of scope for the data-coverage push

Login, Register, ForgotPassword, ResetPassword, MfaSetup, AcceptInvitation,
AuthModule, AgentSupport, AgentOperationSupport — these are the app's own
UX, not imported-data displays.

## What to build now (priority order)

Focus on making **all imported data actually visible** through the 5 list pages
that already work. Add detail drawers so users can drill into a row and see
its children (riders, payments, rebate, KYC docs, licenses, commission rates).

1. **Policy detail drawer** — biggest payoff; unlocks riders / beneficiaries /
   payments / rebate / motor / property / cancellation / mailing / data-quality
   blocks that the PolicyResource already returns but nothing renders.
2. **Customer detail drawer** — profile + KYC docs + assignment history +
   list of this customer's policies (fetched by `?customerId=`).
3. **Agent detail drawer** — profile + license status + upline chain +
   downline count + policies written by this agent.
4. **Product detail drawer** — full spec + commission rates table (needs new
   `/products/{id}/commission-rates` endpoint).
5. **Carrier detail drawer** — full profile + list of products under it
   (fetched by `?carrierId=`).
6. **Fix `AgentHierarchy.vue`** — the shim returning first-100 breaks the tree;
   swap to `fetchAgentList({ perPage: 500 })` inline.
7. **CommissionEngine + CustomerReferral** — same shim issue; defer since they
   are complex pages that need their own refactor.

Steps 1-5 render every imported column of every entity that has one, in a
consistent drawer layout. That's the "systematic" part.
