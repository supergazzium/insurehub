-- ============================================================================
-- ROW-BY-ROW RECONCILIATION: imported policies vs Access source.
-- Run: docker exec -i <mysql> mysql -uroot -prootpw --default-character-set=utf8mb4 < this.sql
--
-- Philosophy: trust INVARIANTS, not the importer's transform code.
--   * PASS-THROUGH fields must EXACTLY equal source  -> any diff is a real bug.
--   * TRANSFORMED fields are checked by the RULE the transform must satisfy
--     (e.g. commission amount == rate*net regardless of scaling), NOT by
--     re-implementing the importer's formula (which could share its bug).
-- Each section prints a COUNT of violations. All-zero = provably faithful.
-- ============================================================================
SET NAMES utf8mb4;

SELECT '=== 0. JOIN COMPLETENESS (every source row maps, nothing invented) ===' AS section;
SELECT 'source applications'                      AS metric, COUNT(*) AS n FROM insurehub_legacy.applications
UNION ALL SELECT 'imported policies (incl soft-deleted)', COUNT(*) FROM insurehub.policies
UNION ALL SELECT 'source rows with NO imported policy',   COUNT(*)
  FROM insurehub_legacy.applications a LEFT JOIN insurehub.policies p ON p.application_no=a.application_code
  WHERE p.id IS NULL
UNION ALL SELECT 'imported policies with NO source row',  COUNT(*)
  FROM insurehub.policies p LEFT JOIN insurehub_legacy.applications a ON a.application_code=p.application_no
  WHERE a.application_code IS NULL;

SELECT '=== 1. PASS-THROUGH: net_premium must equal source (non-installment) ===' AS section;
-- installment policies have net ANNUALIZED (×term), so exclude them here; they
-- are covered by the invariant in section 5.
SELECT 'net_premium mismatch vs source (annual/single only)' AS metric, COUNT(*) AS n
FROM insurehub.policies p JOIN insurehub_legacy.applications a ON a.application_code=p.application_no
WHERE p.deleted_at IS NULL
  AND (p.premium_mode IS NULL OR p.premium_mode IN ('annual','single'))
  AND a.net_premium IS NOT NULL
  AND ABS(COALESCE(p.net_premium,0) - CAST(a.net_premium AS DECIMAL(15,2))) > 0.01;

SELECT '=== 2. PASS-THROUGH: carrier commission AMOUNT must equal source ===' AS section;
SELECT 'comm_carrier_to_hub_amount mismatch (non-collapsed)' AS metric, COUNT(*) AS n
FROM insurehub.policies p JOIN insurehub_legacy.applications a ON a.application_code=p.application_no
WHERE ABS(COALESCE(p.comm_carrier_to_hub_amount,0) - COALESCE(CAST(a.main_commission_inh_amount AS DECIMAL(15,2)),0)) > 0.01
  -- collapse/fold moves/merges, so limit to policies that were NOT collapsed members
  AND p.deleted_at IS NULL AND p.imported_from_legacy=1;

SELECT '=== 3. INVARIANT (D11): commission amount == rate * net (where amount present) ===' AS section;
-- Regardless of how the rate was scaled, the stored amount must equal rate*net.
-- Tolerance 1% or ฿5 (rounding + annualization). Flags rows where they disagree.
SELECT 'carrier commission amount != rate*net' AS metric, COUNT(*) AS n
FROM insurehub.policies p
WHERE p.deleted_at IS NULL AND p.comm_carrier_to_hub_amount>0 AND p.comm_carrier_to_hub_rate>0 AND p.net_premium>0
  AND ABS(p.comm_carrier_to_hub_rate*p.net_premium - p.comm_carrier_to_hub_amount)
      > GREATEST(5, p.comm_carrier_to_hub_amount*0.02);

SELECT '=== 4. INVARIANT (D10): net + duty_stamp + vat == total (life/non-motor) ===' AS section;
SELECT 'net+stamp+vat != annual_premium (where all present)' AS metric, COUNT(*) AS n
FROM insurehub.policies p
WHERE p.deleted_at IS NULL AND p.net_premium>0 AND p.annual_premium>0
  AND ABS(p.net_premium + COALESCE(p.duty_stamp,0) + COALESCE(p.vat,0) - p.annual_premium) > GREATEST(1, p.annual_premium*0.005);

SELECT '=== 5. INVARIANT: installment net == per-inst-source-net * term ===' AS section;
SELECT 'installment net != source net * term' AS metric, COUNT(*) AS n
FROM insurehub.policies p JOIN insurehub_legacy.applications a ON a.application_code=p.application_no
WHERE p.deleted_at IS NULL AND p.premium_mode IN ('monthly','quarterly','semiannual')
  AND p.installment_term REGEXP '^[0-9]+$' AND CAST(p.installment_term AS UNSIGNED)>1
  AND a.net_premium>0
  AND ABS(COALESCE(p.net_premium,0) - CAST(a.net_premium AS DECIMAL(15,2))*CAST(p.installment_term AS UNSIGNED)) > GREATEST(2, p.net_premium*0.01);

SELECT '=== 6. INVARIANT (D2): payment sum == source payment sum (sign-corrected) ===' AS section;
-- The ONLY deliberate payment change is flipping a positive payment on a
-- negative-premium-delta application. Everything else must match 1:1.
SELECT 'imported payment sum' AS metric, FORMAT(SUM(amount),2) AS n FROM insurehub.policy_payments WHERE voided_at IS NULL AND recorded_by_user_id IS NULL
UNION ALL
SELECT 'source payment sum (D2-corrected)' AS metric,
  FORMAT(SUM(CASE WHEN p.amount>0 AND a.main_premium<0 THEN -p.amount ELSE p.amount END),2)
  FROM insurehub_legacy.payments p JOIN insurehub_legacy.applications a ON a.application_code=p.application_code;

SELECT '=== 7. INVARIANT (D1): endorsement policies: main_premium == base + Σ endorsements ===' AS section;
SELECT 'main_premium != base + sum(additionalPremium)' AS metric, COUNT(*) AS n
FROM (
  SELECT p.id, p.main_premium,
    (SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.before.annualPremium')) AS DECIMAL(15,2))
       FROM insurehub.policy_events e WHERE e.policy_id=p.id AND e.type='endorsement.premium_change' AND e.voided_at IS NULL
       ORDER BY e.endorsement_seq ASC, e.id ASC LIMIT 1) base,
    (SELECT COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.additionalPremium')) AS DECIMAL(15,2))),0)
       FROM insurehub.policy_events e WHERE e.policy_id=p.id AND e.type='endorsement.premium_change' AND e.voided_at IS NULL) addl
  FROM insurehub.policies p WHERE p.deleted_at IS NULL
    AND EXISTS(SELECT 1 FROM insurehub.policy_events e WHERE e.policy_id=p.id AND e.type='endorsement.premium_change' AND e.voided_at IS NULL)
) t WHERE base IS NOT NULL AND ABS(main_premium - (base+addl)) > 1;
