# C-17 — Shim soak runbook

Ops checklist for the 2-4 week production soak period between the
C-4 shim rollout and the C-18 drop-columns migration. Ground truth:
[B2 §5](B2-schema-plan.md#5-drop-column-migration-gated-scheduled) +
[00-phase-b-summary.md §C-17](00-phase-b-summary.md).

## Purpose

Prove that `policies.risk_data` is authoritative — no reader falls
back to the legacy top-level columns — before dropping those columns
in C-18. A single fallback event during the soak window is a signal
the shim has a bug, a partial-deploy state, or a missed backfill row.
Dropping columns while any of those are true would lose data.

## The daily signal

Cron entry (from `routes/console.php`) runs every day at 00:30
Asia/Bangkok:

```
Schedule::command('policies:shim-report --json')
    ->dailyAt('00:30')
    ->timezone('Asia/Bangkok')
    ->appendOutputTo(storage_path('logs/shim-report.log'));
```

Output is one JSON line per run appended to
`storage/logs/shim-report.log`. Shape:

```json
{
  "status": "green" | "red" | "unknown",
  "totalEvents": 0,
  "daysScanned": 14,
  "requiredSilentDays": 14,
  "silentDaysObserved": 14,
  "byDate": { "2026-08-22": 0, ... },
  "topTuples": [],
  "recommendation": "Safe to set POLICY_RISK_SHIM_COMPLETE=true and run C-18 drop-columns migration."
}
```

Exit codes (for monitoring/alerting hooks):

| exit | status | meaning |
|---|---|---|
| 0 | `green`   | Silent for N consecutive days → safe to drop |
| 1 | `red`     | Fallback events observed → HOLD |
| 2 | `unknown` | No log files at all → verify shim deployed + storage mount |

## Ad-hoc invocation

```bash
# Table view — read for pattern
docker compose exec -T backend php artisan policies:shim-report

# Tighter window (default 14 days)
docker compose exec -T backend php artisan policies:shim-report --days=7

# Machine-readable
docker compose exec -T backend php artisan policies:shim-report --json | jq

# Show more tuples when noisy
docker compose exec -T backend php artisan policies:shim-report --top=20
```

## What to do when the report is RED

The `topTuples` list identifies exactly which (kind, key, column)
combinations are still hitting the fallback path. Common causes,
ordered by likelihood:

1. **A row missed the C-5 backfill.** Rare — the backfill is
   idempotent and printed a 230-row completion count. But if a
   post-C-5 restore or import happened, that data won't have
   `risk_data` populated. Fix: re-run
   `php artisan policies:backfill-risk-data --force` scoped to the
   affected tenant.

2. **A code path writes only to the legacy column, bypassing the
   shim writer.** Grep the backend for direct assignments to the
   retired columns:
   ```
   grep -rn "motor_type_driver\|motor_engine_no\|motor_chassis_no\|property_insured_" backend/app/
   ```
   Any hit outside `PolicyRiskShim.php` or `PolicyRequest.php` is
   suspicious — legacy writers survive but should route through the
   `risk` payload shape now (see B4 §3 for the wizard writer
   contract).

3. **A new column shipped without being added to `PolicyRiskShim::FIELDS`.**
   If C-3+ added a new risk field on `policies`, it needs a matching
   entry in the shim map. Otherwise the writer never populates it and
   every read falls back forever.

4. **A tenant with a different `product_types.kind` mapping.** Custom
   admin edits may have set a kind the shim doesn't know about (only
   6 canonical: motor/travel/fire/health/life/misc). The
   `canonicalKind()` normalizer handles the two known legacy vocab
   pairs (`property`→`fire`, `other`→`misc`); anything else is a
   config bug.

## Green-light checklist for C-18

When the report has been GREEN for the required window:

- [ ] `policies:shim-report --json` returns `"status":"green"` for
      **14 consecutive days** (default; tighter/looser via `--days`)
- [ ] A manual `grep` of the codebase confirms no writer bypasses
      the shim (see cause #2 above)
- [ ] A pre-flight `mysqldump` of the 29 retired columns has been
      captured with 90-day retention:
      ```
      mysqldump --tab=/tmp -T --no-create-info insurehub policies \
        --where="1=1" --fields-terminated-by=',' \
        --fields-optionally-enclosed-by='"' \
        --columns='id,motor_type_driver,motor_type_vehicle,motor_engine_no,motor_chassis_no,motor_register_year,motor_no_passenger,motor_notes,property_insured_name,property_insured_address,property_phone,property_building_cov,property_furniture_cov,property_stock_cov,property_other_cov,property_other_detail,property_notes,trip_destination,trip_start,trip_end,traveler_count,traveler_passport,insured_person_name,insured_person_id_card,insured_person_birth_date,sum_assured,premium_paying_term,health_declaration,health_beneficiary_name,health_beneficiary_relation'
      ```
- [ ] Set `POLICY_RISK_SHIM_COMPLETE=true` in the Coolify backend env
- [ ] Deploy the C-18 branch — its migration is gated on the env flag
      and no-ops otherwise
- [ ] Post-deploy: re-run `php artisan policies:shim-report` — should
      still be GREEN (readers now use JSON exclusively)
- [ ] Post-deploy: verify PolicyResource still emits `risk.fields`
      for a sample of each kind (motor / travel / fire / health / life)

## Rollback

If C-18 lands and something breaks, the pre-flight `mysqldump` is
the recovery path. Restore steps:

1. Set `POLICY_RISK_SHIM_COMPLETE=false` (or unset)
2. Roll the migration back: `php artisan migrate:rollback --step=1`
   (re-adds the 29 columns, all NULL)
3. `LOAD DATA INFILE` the mysqldump back into the recreated columns
4. Re-deploy the previous release with the shim reader intact

The shim reader gracefully falls through to columns even without the
JSON populated, so step 3's data loss (if the dump is stale) is
bounded to fields written since the dump — which the JSON path
covers regardless.
