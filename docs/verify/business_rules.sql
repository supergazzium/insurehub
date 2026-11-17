-- ============================================================================
-- BUSINESS-RULE ASSERTION SUITE — invariants over the whole dataset.
-- Run: docker exec -i <mysql> mysql -uroot -prootpw --default-character-set=utf8mb4 insurehub < business_rules.sql
--
-- Each row prints a rule and a VIOLATION count. 0 = rule holds.
-- These check LOGIC (not source parity) — they catch bugs a source comparison
-- can't, and must all pass (or have a documented, understood exception) before
-- the data is considered deploy-ready.
-- ============================================================================
SET NAMES utf8mb4;

SELECT 'RULE' AS rule, 'violations' AS n, 'note' AS note
UNION ALL
-- R1: an active policy's coverage must span today.
SELECT 'R1 active policy covers today',
  CAST(COUNT(*) AS CHAR),
  'active with expiry<today or effective>today'
FROM policies WHERE deleted_at IS NULL AND status='active'
  AND (expiry_date < CURDATE() OR effective_date > CURDATE())
UNION ALL
-- R2: an expired policy must have ended.
SELECT 'R2 expired policy has ended',
  CAST(COUNT(*) AS CHAR), 'status=expired but expiry_date>=today'
FROM policies WHERE deleted_at IS NULL AND status='expired' AND expiry_date >= CURDATE()
UNION ALL
-- R3: no future-dated installment is marked paid.
SELECT 'R3 no future installment marked paid',
  CAST(COUNT(*) AS CHAR), 'policy_installments due_date>today AND status=paid'
FROM policy_installments WHERE due_date > CURDATE() AND status='paid'
UNION ALL
-- R4: net + duty_stamp + vat == total (where all present & net<=total).
SELECT 'R4 net+stamp+vat == total',
  CAST(COUNT(*) AS CHAR), 'excludes net>total source anomalies'
FROM policies WHERE deleted_at IS NULL AND net_premium>0 AND annual_premium>0 AND net_premium<=annual_premium+0.5
  AND ABS(net_premium+COALESCE(duty_stamp,0)+COALESCE(vat,0)-annual_premium) > GREATEST(10, annual_premium*0.01)
UNION ALL
-- R5: commission rate is a fraction in [0,1].
SELECT 'R5 commission rate in [0,1]',
  CAST(COUNT(*) AS CHAR), 'rate>1 means mis-scaled'
FROM policies WHERE deleted_at IS NULL
  AND (comm_hub_to_agent_rate > 1 OR comm_carrier_to_hub_rate > 1)
UNION ALL
-- R6: endorsement ledger nets to the final premium (main_premium == base+Σdelta).
SELECT 'R6 endorsement nets to main_premium',
  CAST(COUNT(*) AS CHAR), 'group/endorsement policies'
FROM (
  SELECT p.id, p.main_premium,
    (SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.before.annualPremium')) AS DECIMAL(15,2))
       FROM policy_events e WHERE e.policy_id=p.id AND e.type='endorsement.premium_change' AND e.voided_at IS NULL
       ORDER BY e.endorsement_seq, e.id LIMIT 1) base,
    (SELECT COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.additionalPremium')) AS DECIMAL(15,2))),0)
       FROM policy_events e WHERE e.policy_id=p.id AND e.type='endorsement.premium_change' AND e.voided_at IS NULL) addl
  FROM policies p WHERE p.deleted_at IS NULL
    AND EXISTS(SELECT 1 FROM policy_events e WHERE e.policy_id=p.id AND e.type='endorsement.premium_change' AND e.voided_at IS NULL)
) t WHERE base IS NOT NULL AND ABS(main_premium-(base+addl))>1
UNION ALL
-- R7: endorsement events are in date order (seq follows occurred_at).
SELECT 'R7 endorsement events in date order',
  CAST(COUNT(*) AS CHAR), 'seq N occurred before seq N-1'
FROM (
  SELECT e.occurred_at, LAG(e.occurred_at) OVER (PARTITION BY e.policy_id ORDER BY e.endorsement_seq) pv
  FROM policy_events e JOIN policies p ON p.id=e.policy_id
  WHERE p.deleted_at IS NULL AND e.type='endorsement.premium_change' AND e.voided_at IS NULL
) t WHERE pv IS NOT NULL AND occurred_at < pv
UNION ALL
-- R8: no empty (฿0) endorsement events.
SELECT 'R8 no zero-value endorsements',
  CAST(COUNT(*) AS CHAR), 'additionalTotal = 0'
FROM policy_events e JOIN policies p ON p.id=e.policy_id
WHERE p.deleted_at IS NULL AND e.type='endorsement.premium_change' AND e.voided_at IS NULL
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.additionalTotal')) AS DECIMAL(15,2))=0
UNION ALL
-- R9: renewal chains have no overlapping coverage (by date order).
SELECT 'R9 renewal chains no date overlap',
  CAST(COUNT(*) AS CHAR), 'year N starts before year N-1 ends'
FROM (
  SELECT p.id, LAG(p.expiry_date) OVER (PARTITION BY p.policy_no,p.customer_id ORDER BY p.effective_date) pe, p.effective_date
  FROM policies p WHERE p.deleted_at IS NULL AND p.policy_no<>'' AND p.customer_id IS NOT NULL
    AND p.policy_no IN (SELECT policy_no FROM policies WHERE deleted_at IS NULL GROUP BY policy_no,customer_id HAVING COUNT(DISTINCT policy_year)>1 AND MAX(policy_year)>1)
) t WHERE pe IS NOT NULL AND effective_date < pe
UNION ALL
-- R10: a refund endorsement (negative premium) has a negative additionalTotal.
SELECT 'R10 refund endorsements are negative',
  CAST(COUNT(*) AS CHAR), 'additionalPremium<0 but additionalTotal>0'
FROM policy_events e JOIN policies p ON p.id=e.policy_id
WHERE p.deleted_at IS NULL AND e.type='endorsement.premium_change' AND e.voided_at IS NULL
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.additionalPremium')) AS DECIMAL(15,2))<0
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.additionalTotal')) AS DECIMAL(15,2))>0;
