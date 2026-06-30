# InsureHub MySQL Schema Design

Target: **MySQL 8 / Laravel 13**.
Source of truth: **Vue 3 frontend** (Pinia stores + page-local interfaces).
Legacy data: **Access exports** (20 tables). The previous Strapi instance is
not migrated — this backend replaces it.

## Design rules

1. **Frontend shape wins.** Column names mirror the Pinia field names (camelCase in JSON, snake_case in DB). Frontend reads/writes nothing it can't find in a table.
2. **Multi-tenant from day one.** Every business table has a `tenant_id` FK to `tenants`.
3. **Money = `DECIMAL(15,2)` baht.** (Easy migration to integer satang later via a single column type change.)
4. **Dates = `DATE` / `TIMESTAMP`.** Never strings. Legacy string-dates (`FirstDue_instDate`, `Head Start Date`) get parsed during seed.
5. **Lookup tables stay.** Banks, religions, nationalities, occupations, prefixes, locations, policy statuses, payment methods, insure companies, motor catalogue — all preserved from Access. They become normal tables with their own surrogate `id`.
6. **Soft delete + timestamps everywhere** (`created_at`, `updated_at`, `deleted_at`).
7. **Access-only fields kept as nullable columns** so legacy import is lossless. The frontend ignores them.
8. **Event-sourced policy lifecycle.** `policies` has the current state; `policy_events`, `policy_payments`, `policy_documents`, `policy_riders`, `policy_beneficiaries` are children.
9. **Commission ledger is tall.** One row per (policy event × payer agent) — matches frontend `CommissionTransaction`.

## Tables (final list — 31 tables, grouped)

### Platform / auth
- `tenants` — org-level config (TenantSettings)
- `users` — staff/admin/agent login (extends Laravel default; adds `tenant_id`, role, agent link)
- `audit_entries` — frontend AuditEntry

### Lookup / parameters (seeded from Access)
- `banks` — BankName_Para
- `nationalities` — nation
- `religions` — religion
- `occupations` — Occupation
- `name_prefixes` — prefix_para
- `locations` — Location (Thai admin: province/amphoe/district/zip)
- `policy_statuses` — Policies_status_para
- `paid_statuses` — paidstatus_para
- `payment_methods` — Payment_method_para
- `payment_inscomp_statuses` — Payment_InsComp_Status_para
- `payment_inscomp_tos` — Payment_InsComp_to_para
- `motor_market_groups` — MotorMarketGrouppara
- `motor_vehicles` — Motor_para (Redbook, 32k rows)

### Business — people
- `agents` — Pinia `Agent` + Access `Agent_para`
- `recruitment_links` — Pinia `RecruitmentLink`
- `customers` — Pinia `Customer` + Access `Client`
- `customer_kyc_docs` — Customer.kycDocs[]
- `customer_assignment_history` — Customer.assignmentHistory[]
- `customer_referral_links` — Pinia `CustomerReferralLink`

### Business — carriers & products
- `carriers` — Pinia `Carrier` + Access `Insure_company`
- `carrier_contact_groups` — Pinia `CarrierContactGroup`
- `products` — Pinia `Product` + Access `Main_Product`
- `product_commission_rates` — per-year rates (Access AgCommission_1..11, InCommission_1..11, ComCommission_1..11)
- `contracts` — Pinia `Contract`
- `contract_schedule_rows` — Pinia `ScheduleRow`

### Business — policies
- `policies` — Pinia `Policy` + Access `Application`
- `policy_riders` — Pinia Rider[]
- `policy_beneficiaries` — Pinia Beneficiary[]
- `policy_events` — Pinia PolicyEvent[] (audit trail)
- `policy_payments` — Pinia PolicyPayment[]
- `policy_documents` — Pinia PolicyDocument[] + Access App_Doc_Control paths

### Business — commission
- `commission_transactions` — Pinia CommissionTransaction (tall ledger)
- `commission_runs` — Pinia CommissionRun
- `commission_run_transactions` — pivot (CommissionRun.transactionIds)
- `referral_bonus_configs` — Pinia ReferralBonusConfig (per-tenant)

### Business — email
- `email_templates` — Pinia EmailTemplate

## Conventions

- **PK:** `id BIGINT UNSIGNED AUTO_INCREMENT`
- **Tenant FK:** `tenant_id BIGINT UNSIGNED` first non-PK column. NOT NULL on business tables.
- **External business codes** (`customer_code`, `agent_code`, `carrier_code`, `product_code`, `policy_no`, `application_no`, `quote_no`) are `VARCHAR` UNIQUE within tenant.
- **Enums** use `VARCHAR(32)` + CHECK constraint where possible (MySQL allows native enums but they migrate poorly — varchar is safer).
- **JSON columns** for `payload` on events.
- **FKs:** `RESTRICT` on lookup-table parents (banks, statuses); `CASCADE` on policy children.
- **Indexes:** on every FK; composite `(tenant_id, status)` on policies; `(tenant_id, customer_code)` UNIQUE.
- **Collation:** `utf8mb4_unicode_ci` (Thai-safe).

## Frontend store ↔ table mapping (quick lookup)

| Pinia store / page entity | Primary table | Child tables |
|---|---|---|
| `stores/agents.ts: Agent` | `agents` | (linked: `recruitment_links`) |
| `stores/customers.ts: Customer` | `customers` | `customer_kyc_docs`, `customer_assignment_history`, `customer_referral_links` |
| `stores/policies.ts: Policy` | `policies` | `policy_riders`, `policy_beneficiaries`, `policy_events`, `policy_payments`, `policy_documents` |
| `stores/commissions.ts: CommissionTransaction` | `commission_transactions` | `commission_runs`, `commission_run_transactions`, `referral_bonus_configs` |
| `stores/carrierContacts.ts: CarrierContactGroup` | `carrier_contact_groups` | — |
| `stores/emailTemplates.ts: EmailTemplate` | `email_templates` | — |
| `pages/carriers: Carrier` | `carriers` | — |
| `pages/products: Product` | `products` | `product_commission_rates` |
| `pages/contracts: Contract` | `contracts` | `contract_schedule_rows` |
| `pages/settings: TenantSettings` | `tenants` | `audit_entries`, `referral_bonus_configs` |
