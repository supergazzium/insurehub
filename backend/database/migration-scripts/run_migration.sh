#!/usr/bin/env bash
# =============================================================================
# InsureHub legacy migration — full orchestration (idempotent, ordered).
#
# Runs the WHOLE pipeline against the local Docker stack:
#   stage (insurehub_legacy)  ->  import (truncate+fresh)  ->  collapse/fold
#   ->  all backfills/fixes in dependency order  ->  final verification.
#
# PREREQUISITES (do these first, they need the source files + decisions):
#   1. Convert the new Access export:
#        cd "Access Database/Database/mysql_migration"
#        uv run tools/xlsx_to_csv.py "<NEW_EXPORT_DIR_or_ZIP>" \
#          "$ROOT/Access Database/Database/realdata"
#   2. Regenerate Strapi agents from the NEW Strapi dump:
#        uv run tools/extract_strapi_agents.py "<NEW_STRAPI_DUMP.sql>" strapi_agents.csv
#   3. Rebuild the manifests from the NEW Application.xlsx:
#        APP_XLSX="<NEW>/Application.xlsx" \
#        PATTERN_C_DECISIONS="reports/pattern_C_decisions.csv" \
#        OUT_MANIFEST="../../../backend/database/migration-manifests/collapse_manifest.csv" \
#          uv run --with pandas --with openpyxl python3 \
#          ../../../backend/database/migration-manifests/build_collapse_manifest.py
#        (same for build_endorsement_manifest.py / endorsement_manifest.csv)
#      The collapse builder RE-EMITS the 9 'collapse-group' GM policies — do not
#      hand-edit; it is baked into the builder so a rebuild never loses the fix.
#
# Then run this script.  Flags matter: some artisan commands take --force, most
# do NOT (they take --apply or just run). Passing an unsupported --force makes a
# command error out SILENTLY and do nothing — so each line below uses the flag
# that command actually supports. Verified 2026-11-26.
# =============================================================================
set -uo pipefail
ROOT="/Users/prachumchanman/Documents/insurehub"
cd "$ROOT"
BID="$(docker compose ps -q backend)"
CID="$(docker compose ps -q mysql)"
MAN="/app/database/migration-manifests"
[ -n "$BID" ] && [ -n "$CID" ] || { echo "backend/mysql container not found"; exit 1; }

art(){ echo ""; echo ">>> artisan $*"; docker exec "$BID" php /app/artisan "$@" --no-interaction 2>&1 | tail -3; }
sql(){ docker exec "$CID" sh -c "mysql --default-character-set=utf8mb4 -uroot -prootpw insurehub -N -e \"$1\"" 2>/dev/null; }

echo "########## 0. stage legacy (resets insurehub_legacy; never touches app db) ##########"
bash "Access Database/Database/mysql_migration/tools/run_staging.sh" 2>&1 | tail -4

echo ""
echo "########## 0b. copy manifests into the backend container ##########"
docker cp backend/database/migration-manifests/collapse_manifest.csv       "$BID":$MAN/collapse_manifest.csv
docker cp backend/database/migration-manifests/collapse_product_fixes.csv  "$BID":$MAN/collapse_product_fixes.csv 2>/dev/null || true
docker cp backend/database/migration-manifests/endorsement_manifest.csv    "$BID":$MAN/endorsement_manifest.csv

echo ""
echo "########## 0c. clear stale leftover tables (NOT in importer truncate list) ##########"
docker exec "$CID" sh -c "mysql -uroot -prootpw insurehub -e '
  SET FOREIGN_KEY_CHECKS=0;
  TRUNCATE policy_installments; TRUNCATE payment_reminders; TRUNCATE renewal_notices;
  DROP TABLE IF EXISTS _removed_installments_backup; DROP TABLE IF EXISTS _policy_year_backup;
  DROP TABLE IF EXISTS _resequence_endorsements_backup; DROP TABLE IF EXISTS _annual_premium_backup;
  SET FOREIGN_KEY_CHECKS=1;'" 2>/dev/null
echo "cleared"

echo ""
echo "########## 1. IMPORT (truncate + fresh) ##########"
docker exec "$BID" php /app/artisan insurehub:import --truncate --force 2>&1 | tail -22

echo ""
echo "########## 2. STRUCTURAL: collapse -> fold -> coverage dates ##########"
art policies:collapse-installments --manifest=$MAN/collapse_manifest.csv --product-fixes=$MAN/collapse_product_fixes.csv --force
art policies:fold-group-endorsements --manifest=$MAN/endorsement_manifest.csv --force
art policies:fix-renewal-coverage-dates --force

echo ""
echo "########## 3. STATUS + PREMIUM ##########"
art policies:backfill-statuses          # (no --force)
art policies:backfill-premium-gross --force
art policies:backfill-premium-mode

echo ""
echo "########## 4. INSTALLMENTS (build -> fix premium -> clear stale) ##########"
art policies:backfill-installments --status=issued,active
art policies:backfill-installment-premium
art policies:fix-stale-installments --force

echo ""
echo "########## 5. PAYMENTS (legacy catch-up -> payee -> tidy) ##########"
art payments:backfill-legacy
art payments:backfill-payee             # classifies ISH/IC; synthetic rows default insurehub
art payments:tidy-legacy

echo ""
echo "########## 6. COMMISSION + RISK ##########"
art policies:backfill-commission
art policies:zerofill-imported-commission
art policies:backfill-risk-data

echo ""
echo "########## 7. YEARS + ENDORSEMENTS (notes -> resequence -> restore year LAST) ##########"
art policies:backfill-years
art policies:backfill-endorsement-note
art policies:resequence-endorsements --force
art policies:restore-policy-year        # LAST year step: pins policy_year to source
art policies:customer-year-from-act   # ปีของลูกค้า = act_year

echo ""
echo "########## 8. AGENTS (license flags -> PII encrypt -> logins) ##########"
art agents:backfill-license-flags
art agents:reencrypt-pii
art agents:create-logins

echo ""
echo "########## 9. CUSTOMERS (cleanup -> gender -> from-policies -> dup decisions) ##########"
art customers:cleanup
docker exec "$BID" php /app/artisan customers:backfill-gender --apply --no-interaction 2>&1 | tail -1
art customers:backfill-from-policies
art customers:apply-dup-decisions
art customers:merge-duplicates

echo ""
echo "########## 10. VERIFY ##########"
echo "policies live   : $(sql "SELECT COUNT(*) FROM policies WHERE deleted_at IS NULL;")"
echo "payments        : $(sql "SELECT COUNT(*) FROM policy_payments;")  total paid ฿$(sql "SELECT FORMAT(SUM(amount),2) FROM policy_payments WHERE voided_at IS NULL;")"
echo "installments    : $(sql "SELECT COUNT(*) FROM policy_installments;")"
echo "WHT inc/exc     : $(sql "SELECT CONCAT(SUM(wht_status='include'),'/',SUM(wht_status='exclude')) FROM policies;")"
echo "payee carr/inh  : $(sql "SELECT CONCAT(SUM(payee='carrier'),'/',SUM(payee='insurehub')) FROM policy_payments;")"
echo "policy_year miss: $(sql "SELECT IFNULL(SUM(p.policy_year<>la.policy_year),0) FROM policies p JOIN insurehub_legacy.applications la ON la.application_code=p.application_no WHERE p.deleted_at IS NULL;")  (0 = good)"
echo "sched mismatch  : $(sql "SELECT COUNT(DISTINCT p.id) FROM policies p WHERE p.deleted_at IS NULL AND EXISTS(SELECT 1 FROM policy_installments WHERE policy_id=p.id) AND ABS((SELECT SUM(amount_due) FROM policy_installments WHERE policy_id=p.id)-p.annual_premium)>1;")  (0 = good)"
echo ""
echo "########## DONE — regenerate the dump next:"
echo "  docker exec \$CID sh -c 'mysqldump -uroot -prootpw --single-transaction --default-character-set=utf8mb4 --no-tablespaces --ignore-table=insurehub._removed_installments_backup insurehub' | gzip > insurehub_migrated_full_<DATE>.sql.gz"
