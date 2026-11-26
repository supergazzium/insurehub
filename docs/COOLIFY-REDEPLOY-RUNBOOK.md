# Coolify Redeploy Runbook — migrated data

Step-by-step to get the current (fully-fixed) migrated dataset onto Coolify.
Run top to bottom. **I (Claude) cannot reach your Coolify/prod — you run every
step here.** Verify with `docs/REDEPLOY-TEST-PLAN.html` at the end.

---

## 0. What you're deploying

| Item | Value |
|---|---|
| **Dump** | `insurehub_migrated_full_20261126.sql.gz` (repo root, **gitignored — PII**) |
| **Dump sha256** | `ae42f953978e72eaac7552a359fe2ade128243784042b0a6fd8d4be11659f617` |
| **Code** | `fix/wht-policyyear` (PR pending) + merged PR #53 |
| Rows the dump contains | 19,638 live policies (21,801 incl. collapsed), 11,312 customers, 703 agents |
| Money | payment sum **฿263,795,618.18** exact |
| Fixes baked in | license flags (72 agents), commission zero-fill (1,030 policies), collapse, fold, premium backfill, PII encryption, **annual_premium gross fix (468 policies)**, **stale installment-schedule cleanup (92 policies / 153 rows)**, **endorsement source notes (252 events)**, **endorsement re-sequencing to source $N order (22 policies / 199 events)**, **WHT include/exclude restored (5,102 policies)**, **policy_year restored from source (1,957 policies)**, **payment payee restored — InsureHub/Insurance Company (19,986 payments)**, **re-migrated from the 25/11 export (full pipeline via run_migration.sh)**, **same-national-ID customer dedup** |

Because the dump was cut **after** every backfill ran locally, **you do NOT run
any artisan backfill commands on prod.** Restore-and-go.

---

## 1. ⚠️ APP_KEY — the one that will silently break PII

The agent bank accounts, ID cards, and licenses in the dump are **encrypted with
your local `APP_KEY`**. Coolify's backend must use the **same** `APP_KEY`, or
those fields decrypt to garbage (you'll see it on an agent's ข้อมูลส่วนตัว tab).

- **Do:** copy the `APP_KEY` from your local `backend/.env` into the Coolify
  backend service's environment variables (Coolify → backend service →
  Environment Variables). It starts with `base64:`.
- **Do NOT** run `php artisan key:generate` on prod — that mints a *new* key and
  permanently breaks decryption of the restored PII.
- If prod already has a different `APP_KEY` and you can't change it, the fix is
  to re-encrypt: not covered here — ask before doing it.

**This is the #1 migration deploy mistake. Confirm the keys match before step 4.**

---

## 2. Deploy the code

Pushing `main` triggers CI → on green, the GitHub `Deploy` workflow fires the
Coolify webhooks (`COOLIFY_BACKEND_WEBHOOK` / `COOLIFY_FRONTEND_WEBHOOK`).

- Check **GitHub → Actions**: the `CI` then `Deploy` runs for `cf6919b` should be
  green. If `Deploy` says *"COOLIFY_*_WEBHOOK secret is not set"*, the webhooks
  aren't wired — redeploy manually in Coolify (each service → **Redeploy**).
- Confirm the backend came up: open `https://<your-domain>/up` → expect `200`.

### ⚠️ The FRONTEND must be redeployed this round

Most of this migration is data-only (restore-and-go, no code needed). **This
release is the exception:** the endorsement history now reads the original source
note (`payload.sourceNote`) added by the migration. That is a **frontend** change
(`PolicyApplicationWizard.vue` @ `cf6919b`).

- **Redeploy the frontend service** (webhook on push, or Coolify → frontend →
  **Redeploy**). Without it, endorsement rows keep showing the generic system
  reason ("สมาชิกเพิ่ม…") instead of the staff note ("แจ้งเข้า 16 คน").
- The **backend needs no code change** for the notes — it already passes the full
  event payload through; the data arrives via the dump restore (step 5).

Code must be live **before** the restore (the dump's schema matches `main`).

---

## 3. Back up what's on prod now (safety net)

Before overwriting, snapshot the current prod DB so you can roll back.

```bash
# In the Coolify MySQL container's terminal (Coolify → MySQL service → Terminal):
mysqldump -u root -p --default-character-set=utf8mb4 \
  --single-transaction --quick --routines --triggers --no-tablespaces \
  insurehub | gzip > /tmp/insurehub_prod_backup_$(date +%Y%m%d).sql.gz
# then download it off the container, or copy to a safe volume.
```

---

## 4. Upload the dump to Coolify

Get `insurehub_migrated_full_20261126.sql.gz` onto the Coolify MySQL container. Options:

- **scp** to the host, then `docker cp` into the MySQL container; or
- Coolify file manager / a mounted volume; or
- `curl` it from a temporary signed URL.

Verify the file arrived intact:

```bash
sha256sum insurehub_migrated_full_20261126.sql.gz
# must equal: ae42f953978e72eaac7552a359fe2ade128243784042b0a6fd8d4be11659f617
```

If the sha256 doesn't match, the upload corrupted — re-upload. Do not restore a
mismatched file.

> This sha is for **this exact dump file** and only checks the upload didn't
> corrupt. `mysqldump` isn't byte-reproducible, so re-cutting the dump from the
> same data yields a *different* file sha — that's normal. The proof the **data**
> is faithful is the content-hash table in §7b, not this file sha.

---

## 5. Restore

```bash
# In the Coolify MySQL container terminal, with the .gz present:

# 5a. (optional but clean) drop + recreate so no stale rows linger
mysql -u root -p -e "DROP DATABASE IF EXISTS insurehub;
  CREATE DATABASE insurehub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 5b. restore
gunzip -c insurehub_migrated_full_20261126.sql.gz | \
  mysql -u root -p --default-character-set=utf8mb4 insurehub
```

Restore is clean (verified by a local round-trip: 0 errors). It should finish in
well under a minute.

> Make sure the DB name / user in the restore matches what the backend's `.env`
> `DB_DATABASE` / `DB_USERNAME` point at.

---

## 6. Clear backend caches (so it reads the fresh data + env)

```bash
# In the Coolify BACKEND container terminal:
php artisan config:clear
php artisan cache:clear
php artisan route:clear
# NO migrate, NO db:seed, NO backfills — the dump is complete.
```

(If the backend image has OPcache with `validate_timestamps=Off`, a service
**Restart** in Coolify guarantees it picks up the new code — same gotcha as
local.)

---

## 7. Verify (hard gates)

Open the app and confirm, in order:

1. **Login** `admin@insurehub.co.th` → dashboard loads.
2. **Counts** — dashboard shows **19,638** policies (NOT 38,955 = stale backend),
   **11,312** customers, **703** agents.
3. **Money** — in the backend container:
   `php artisan tinker --execute="echo \App\Models\PolicyPayment::whereNull('voided_at')->sum('amount');"`
   → **253602089.30** exact.
4. **PII decrypts (APP_KEY check)** — open agent `AG210001` → ข้อมูลส่วนตัว →
   bank account reads **กสิกรไทย 744-2-51287-0** (readable, not garbled). Garbled
   here = APP_KEY mismatch → fix step 1, re-restore.
5. **Agent login** — an agent logs in (password = birthdate `ddmmyyyy`).
6. **Commission warning quiet** — งานค้าง → ยังไม่บันทึกค่าคอม shows a small/zero
   count (migrated zero-commission policies no longer warn).
7. **Premium reconciles (no false overpay)** — open policy `GM 65`
   (`/policies/21043/edit-draft`) → payment panel shows **ชำระครบแล้ว / ฿0**,
   not ชำระเกินยอด.
8. **Endorsement notes + $N order (frontend redeploy check)** — open policy
   `0222465843` (`/policies/18817/edit-draft`) → สลักหลัง history → the
   รายละเอียด column reads the staff notes ("แจ้งเข้า 16 คน",
   "IN260677_นาวิน จ่าย 7%"), NOT the generic "สมาชิกเพิ่ม…". Generic text here =
   the **frontend wasn't redeployed** (step 2), not a data problem. Also confirm
   the ฿-24,917 refund shows at **$24** (source order), not $3.

### 7b. Deployed-parity checksum (the systematic proof)

This proves the Coolify restore is **bit-identical** to the verified local dump —
not just "counts look right". Run the same script on both and diff.

```bash
# On the Coolify MySQL container (or via its terminal):
mysql -u root -p --default-character-set=utf8mb4 insurehub \
  < docs/verify/parity_checksum.sql
```

The output must match this local reference **exactly** (same row counts AND
content hashes). A single changed cell flips a hash; a dropped row changes a
count:

| tbl | rows | content_hash |
|---|---|---|
| policies | 21801 | C954B5D6 |
| policy_payments | 21490 | 915C2EB7 |
| policy_installments | 3276 | FED6C591 |
| policy_events | 23435 | 5DF8EFD9 |
| commission_ledgers | 80 | E286C99A |
| customers | 11313 | 7AC4AEEF |
| agents | 703 | DBD8C016 |

Any divergence = the restore is NOT faithful → re-check the sha256 of the
uploaded dump and re-restore. (The hashes are APP_KEY-independent: they run on
raw stored ciphertext, so they match across a faithful restore regardless of
decryption — a hash mismatch is a *data* problem, a garbled bank-account in
step 4 is an *APP_KEY* problem. Check both.)

Two deeper suites can also be run on the restored DB for logic (not just
byte-parity) confidence — both ship in `docs/verify/`:
- `business_rules.sql` — 10 invariants; R1/R2/R3/R6/R7/R8/R9/R10 must be 0.
  (R4≈198 and R5=2 are known source-faithful anomalies, documented in
  `docs/MIGRATION-DEFECTS.md`.)
- the row-by-row source reconciliation (needs the `insurehub_legacy` source DB,
  so run it locally pre-deploy, not on Coolify).

Then run the full `docs/REDEPLOY-TEST-PLAN.html` (P0–P9) for thorough coverage.

---

## 8. Rollback (if a hard gate fails)

```bash
# restore the step-3 backup:
mysql -u root -p -e "DROP DATABASE insurehub; CREATE DATABASE insurehub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c /tmp/insurehub_prod_backup_YYYYMMDD.sql.gz | mysql -u root -p insurehub
php artisan config:clear && php artisan cache:clear
```

---

## Quick reference — what NOT to do

- ❌ `php artisan key:generate` on prod (breaks PII decryption)
- ❌ `php artisan db:seed` (overwrites migrated data with demo seed)
- ❌ any `php artisan *backfill*` / `collapse` / `fold` command (already applied in the dump)
- ❌ restoring a dump whose sha256 doesn't match
