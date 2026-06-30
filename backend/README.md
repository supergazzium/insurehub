# InsureHub Backend (Laravel 13 + MySQL 8)

Laravel API + database for the Vue 3 InsureHub frontend (in `../frontend/`).
The schema mirrors the Pinia stores in `frontend/src/stores/*.ts` and the
page-local entity interfaces. Legacy data from the MS Access database is
imported via seeders. The previous Strapi instance is **not** carried over —
this backend replaces it.

See `database/SCHEMA_DESIGN.md` for the design rationale and
`../RESEARCH_DB_MAPPING.md` for the Access ↔ frontend field mapping.

## Quick start

```bash
# from this directory
composer install
cp .env.example .env             # only needed on a fresh checkout
php artisan key:generate

# MySQL must be running locally; the seeders expect a writable user
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS insurehub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate:fresh --seed
```

Default credentials seeded by `TenantSeeder`:

| Email | Password | Role |
|---|---|---|
| `admin@insurehub.co.th` | `insurehub` | `super_admin` |

## What gets seeded

Run `php artisan db:seed` (or `--seed` on a fresh migrate). Source files live
in `database/seed-data/` (CSVs exported from the Access workbook).

| Seeder | Source | Rows after seed |
|---|---|---:|
| LookupTablesSeeder | `bankname_para.csv` | banks: 11 |
| | `nation.csv` | nationalities: 242 |
| | `religion.csv` | religions: 11 |
| | `occupation.csv` | occupations: 48 |
| | `prefix_para.csv` | name_prefixes: 244 |
| | `location.csv` | locations: 7,460 |
| | `policies_status_para.csv` | policy_statuses: 11 |
| | `paidstatus_para.csv` | paid_statuses: 16 |
| | `payment_method_para.csv` | payment_methods: 3 |
| | `payment_inscomp_status_para.csv` | payment_inscomp_statuses: 3 |
| | `payment_inscomp_to_para.csv` | payment_inscomp_tos: 2 |
| | `motormarketgrouppara.csv` | motor_market_groups: 10 |
| | `motor_para.csv` | motor_vehicles: 32,664 |
| TenantSeeder | hard-coded | tenants: 1, users: 1 |
| CarrierSeeder | `insure_company.csv` | carriers: 45 |
| ProductSeeder | `main_product.csv` | products: 894 + product_commission_rates: 894 |
| AgentSeeder | `agent_para.csv` | agents: 392 (41 with MLM parent link) |
| CustomerSeeder | `client.csv` | customers: 357 |

## Re-generating seed CSVs

The CSVs under `database/seed-data/` are checked in. To rebuild them from the
original sources:

```bash
# Python 3.11+ with pandas + openpyxl required (Python 3.14 currently breaks on macOS due to expat).
/opt/homebrew/bin/python3.11 database/seed-data/_export_access.py
```

`_export_access.py` reads the latest version (highest `(n)` suffix) of every
XLSX in `../Access Database/Database/`.

## Schema → frontend store mapping

The schema is designed to make the Pinia stores readable/writable without any
field translation in the application layer.

| Pinia store / entity | Primary table | Children |
|---|---|---|
| `stores/agents.ts → Agent` | `agents` | `recruitment_links` |
| `stores/customers.ts → Customer` | `customers` | `customer_kyc_docs`, `customer_assignment_history`, `customer_referral_links` |
| `stores/policies.ts → Policy` | `policies` | `policy_riders`, `policy_beneficiaries`, `policy_events`, `policy_payments`, `policy_documents` |
| `stores/commissions.ts → CommissionTransaction` | `commission_transactions` | `commission_runs`, `commission_run_transactions`, `referral_bonus_configs` |
| `stores/carrierContacts.ts → CarrierContactGroup` | `carrier_contact_groups` | — |
| `stores/emailTemplates.ts → EmailTemplate` | `email_templates` | — |
| `pages/carriers/CarrierManagement.vue → Carrier` | `carriers` | — |
| `pages/products/ProductManagement.vue → Product` | `products` | `product_commission_rates` |
| `pages/contracts/ContractManagement.vue → Contract` | `contracts` | `contract_schedule_rows` |
| `pages/settings/TenantSettings.vue → reactive profile` | `tenants` | `audit_entries`, `referral_bonus_configs` |

## What is NOT seeded yet

The seeders import lookup data + carriers + products + agents + customers —
everything the frontend reads at first paint. The following are intentionally
left for a later session because they need product-specific decisions:

1. **Policies (Access `Application.xlsx`, 515 rows × 140 columns).** Fields
   like `Policy_Status`, `Type_of_paid`, `VAT_TYPE` need a Thai → frontend
   enum mapping that's worth doing once with the team rather than guessed.
   Also the 5 fixed rider slots + 4 fixed beneficiary slots need a
   denormalisation pass into `policy_riders` / `policy_beneficiaries`. Until
   then `policies`, `policy_riders`, `policy_beneficiaries`, `policy_events`,
   `policy_payments`, `policy_documents`, and `commission_transactions` stay
   empty.
2. **App_Doc_Control.xlsx (3,853 rows of file paths).** The original
   `O_path_*` paths point to a Windows network share (`Z:\IHOS ACCESS\...`);
   the `E_path_*` paths point to per-user local desktops. We need to know
   which (if either) is still authoritative before importing into
   `policy_documents`.

These are tracked in `../RESEARCH_DB_MAPPING.md` § 6 (open questions).

## API endpoints

All endpoints are mounted at **`/api/v1`** and respond with JSON. Auth is
**Sanctum personal-access tokens** — `POST /api/v1/auth/login` returns a
`token` string; subsequent requests send `Authorization: Bearer <token>`.
Requests and responses both use **camelCase** field names; the resource layer
translates from the snake_case DB columns.

List responses use Laravel's default paginator shape:

```json
{ "data": [...], "links": {...}, "meta": { "current_page": 1, "per_page": 25, "total": 392 } }
```

Single-record responses use `{ "data": {...} }`. Validation errors use the
default Laravel shape: `{ "message": "...", "errors": { "field": ["..."] } }`.

### Auth

| Method | Path | Body | Purpose |
|---|---|---|---|
| `POST` | `/auth/login` | `{ email, password, deviceName? }` | Issue a bearer token |
| `POST` | `/auth/logout` | – | Revoke the current token |
| `GET` | `/auth/me` | – | Current user |

### Tenant settings (one per tenant)

| Method | Path | Maps to frontend |
|---|---|---|
| `GET` | `/tenant` | `pages/settings/TenantSettings.vue` profile |
| `PATCH` | `/tenant` | same — partial updates |

### Business resources (full REST)

For each resource: `GET /` (list), `POST /` (create), `GET /:id` (show), `PATCH /:id` (update), `DELETE /:id` (delete).

| Path | Pinia store / entity | List filters |
|---|---|---|
| `/agents` | `stores/agents.ts → Agent` | `q`, `activeOnly`, `parentAgentId` |
| `/customers` | `stores/customers.ts → Customer` | `q`, `assignedAgentId`, `unassigned` |
| `/carriers` | `pages/carriers/CarrierManagement.vue → Carrier` | `q`, `activeOnly` |
| `/products` | `pages/products/ProductManagement.vue → Product` | `q`, `carrierId`, `type`, `activeOnly` |
| `/contracts` | `pages/contracts/ContractManagement.vue → Contract` | `q`, `carrierId`, `activeOnly` |
| `/policies` | `stores/policies.ts → Policy` | `q`, `status`, `customerId`, `writingAgentId` |
| `/carrier-contact-groups` | `stores/carrierContacts.ts → CarrierContactGroup` | `carrierCode`, `department` |
| `/email-templates` | `stores/emailTemplates.ts → EmailTemplate` | `department` |

Common list params: `?q=` (search), `?page=`, `?perPage=` (1-100, default 25).

### Commission ledger

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/commissions/transactions` | `stores/commissions.ts → transactions[]` (filters: `status`, `agentId`, `policyId`) |

### Lookups (read-only)

| Path | Returns |
|---|---|
| `/lookups/banks` | 11 banks (Thai labels) |
| `/lookups/nationalities` | 242 nationalities |
| `/lookups/religions` | 11 religions |
| `/lookups/occupations` | 48 occupations |
| `/lookups/name-prefixes` | 244 prefixes (Mr/Mrs/บริษัท/…) |
| `/lookups/locations?zip=10170&province=กรุงเทพฯ` | Thai admin lookup (max 50 rows) |
| `/lookups/policy-statuses` | 11 legacy policy statuses |
| `/lookups/payment-methods` | 3 payment methods |
| `/lookups/motor-vehicles?brand=Toyota&model=Camry` | Redbook lookup (max 50 rows) |

### Tenant scoping

Every authenticated request is implicitly scoped to the user's `tenant_id`.
The `tenant` middleware rejects users without a tenant (super-admin
exempted). Cross-tenant access is not exposed via API yet — that needs an
explicit super-admin-only header to be designed.

### CORS

`config/cors.php` reads allowed origins from the `FRONTEND_URLS` env var
(comma-separated). The default covers the Vite dev server on both
`localhost:5173` and `127.0.0.1:5173` — those are distinct origins to the
browser, so we whitelist both. Override for staging/prod by adding to `.env`:

```
FRONTEND_URLS=http://localhost:5173,http://127.0.0.1:5173,https://insurehub.co.th
```

`supports_credentials` is on so cookies / Sanctum SPA mode work later; with
Bearer-token auth it's a no-op. Wildcard origin (`*`) is intentionally NOT
used — browsers reject it as soon as credentials are involved.

### Quick local check

```bash
# 1. Start the API
php artisan serve --port=8765

# 2. Log in (seeded admin)
TOKEN=$(curl -sS -X POST http://127.0.0.1:8765/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"admin@insurehub.co.th","password":"insurehub"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN))->token;')

# 3. Hit any endpoint
curl -sS http://127.0.0.1:8765/api/v1/carriers \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | head
```

## Zoho Mail integration

Outbound + inbound mail is mediated by the Laravel backend, which talks to
the **Zoho Mail REST API**. The frontend never touches Zoho directly — it
POSTs Zoho-shaped JSON to `/api/v1/mail/*` and gets Zoho-shaped responses
back. Sends are persisted in `mail_threads` + `mail_messages`; an artisan
command polls Zoho every minute for inbound replies.

### Endpoints

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/v1/mail/send` | Immediate send. Body matches Zoho's `/messages` payload. |
| `POST` | `/api/v1/mail/schedule` | Same body + `scheduleType: 6, scheduleTime: "MM/dd/yyyy HH:mm:ss"`. |
| `DELETE` | `/api/v1/mail/schedule/{id}` | Cancel a scheduled send. |
| `POST` | `/api/v1/mail/attachments` | Multipart `file=`. Returns Zoho's `{storeName, attachmentName, attachmentPath}`. |
| `GET` | `/api/v1/mail/incoming?since=…` | Inbound replies persisted by the poll worker (reads from local DB, doesn't hit Zoho). |

### One-time Zoho setup

1. **Register an OAuth app.** On a Workplace plan, the Mail-specific console
   lives at <https://workplace.zoho.com/#mail_app/settings/integrations-settings/DeveloperSpace/restapi>
   (Mail → Settings → Integrations → Developer Space → REST API). Click
   **Add Client** → **Server-based Application**. Set the redirect URI to
   `https://localhost` (only used during the consent step). Note the
   `Client ID` and `Client Secret`.

   On a standalone (non-Workplace) Zoho account, the equivalent is at
   <https://api-console.zoho.com/> — same OAuth flow, same scopes.

2. **Generate a refresh token.** Visit (substitute your client ID):

   ```
   https://accounts.zoho.com/oauth/v2/auth?scope=ZohoMail.messages.ALL,ZohoMail.accounts.READ,ZohoMail.attachments.ALL&client_id=<CLIENT_ID>&response_type=code&access_type=offline&redirect_uri=https://localhost&prompt=consent
   ```

   You'll be bounced to `https://localhost?code=…`. Copy that `code` (it expires
   in 60 seconds) and exchange it for a refresh token:

   ```bash
   curl -sS -X POST 'https://accounts.zoho.com/oauth/v2/token' \
     -d "code=<THE_CODE>" \
     -d "client_id=<CLIENT_ID>" \
     -d "client_secret=<CLIENT_SECRET>" \
     -d "redirect_uri=https://localhost" \
     -d "grant_type=authorization_code"
   ```

   Save the returned `refresh_token`.

3. **Fetch your account ID** (one-time, you'll need an access token first):

   ```bash
   curl -sS https://mail.zoho.com/api/accounts \
     -H "Authorization: Zoho-oauthtoken <access_token>"
   ```

   The response includes `accountId` for each mailbox you own. Use the one for
   the sender address you want to use.

4. **Fill in `.env`:**

   ```ini
   ZOHO_REGION=com                    # or eu / in / com.au — match your account
   ZOHO_CLIENT_ID=…
   ZOHO_CLIENT_SECRET=…
   ZOHO_REFRESH_TOKEN=…
   ZOHO_ACCOUNT_ID=…
   ZOHO_FROM_ADDRESS=no-reply@insurehub.co.th  # must be a verified Zoho identity
   ZOHO_FROM_NAME=InsureHub
   ```

   Then `php artisan config:clear`.

5. **Start the scheduler** to pick up inbound replies (one shell):

   ```bash
   php artisan schedule:work
   ```

   This invokes `mail:poll` every minute. In production, replace with a system
   cron entry: `* * * * * cd /path/to/backend && php artisan schedule:run`.

### Threading via plus-addressing

Outbound messages carry `Reply-To: no-reply+T-<threadId>@insurehub.co.th` (the
prefix and domain come from `ZOHO_REPLY_ALIAS_PREFIX` + `ZOHO_FROM_ADDRESS`).
The poll worker scans inbound mail for that alias and associates each reply
with the right `mail_thread` row. The subject also carries `[#T-…]` as a
fallback — if a carrier strips the plus-address.

### Without credentials

`POST /api/v1/mail/send` and the other write endpoints return **503** with the
message "Zoho Mail is not configured" until the env vars are filled in. The
`mail:poll` command logs the same message and exits cleanly, so an unconfigured
deployment doesn't spam errors. `GET /mail/incoming` always works — it reads
from the local DB.

## Running tests

```bash
vendor/bin/phpunit
```

Add Pest later if preferred — see `~/.claude/rules/php/testing.md`.
