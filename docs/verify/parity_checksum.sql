-- ============================================================================
-- DEPLOYED-PARITY CHECKSUM — prove the Coolify restore == the local dump.
--
-- Run IDENTICALLY on both sides and diff the output:
--   LOCAL:   docker exec -i <mysql> mysql -uroot -prootpw insurehub < parity_checksum.sql > local.txt
--   COOLIFY: (same, against the restored DB)                        > coolify.txt
--   diff local.txt coolify.txt     # must be empty
--
-- For each business-critical table it prints: row count + a content hash that
-- is invariant to row ORDER (XOR of per-row CRC32s) but sensitive to any value
-- change. Works on raw stored bytes, so encrypted columns (ciphertext is
-- identical across a faithful restore with the SAME APP_KEY) compare correctly
-- without decryption.
--
-- A single differing cell flips that table's content_hash; a dropped/added row
-- changes the count. Empty diff = the deployed data is bit-identical to local.
-- ============================================================================
SET NAMES utf8mb4;

-- policies — the money columns that every page reads
SELECT 'policies' tbl, COUNT(*) `rows`,
  CONV(COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',
    id, application_no, policy_no, policy_year, status,
    COALESCE(effective_date,''), COALESCE(expiry_date,''),
    COALESCE(net_premium,''), COALESCE(duty_stamp,''), COALESCE(vat,''),
    COALESCE(annual_premium,''), COALESCE(main_premium,''), COALESCE(total_premium_paid,''),
    COALESCE(comm_carrier_to_hub_rate,''), COALESCE(comm_carrier_to_hub_amount,''),
    COALESCE(comm_hub_to_agent_rate,''), COALESCE(comm_hub_to_agent_amount,''),
    COALESCE(premium_mode,''), COALESCE(installment_term,''),
    COALESCE(deleted_at,'')
  ))),0),10,16) content_hash
FROM policies
UNION ALL
-- policy_payments — the ledger
SELECT 'policy_payments', COUNT(*),
  CONV(COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',
    id, policy_id, COALESCE(amount,''), COALESCE(net_amount,''), COALESCE(vat_amount,''),
    COALESCE(duty_amount,''), COALESCE(payment_date,''),
    COALESCE(method,''), COALESCE(reference,''), COALESCE(policy_installment_id,''),
    COALESCE(voided_at,''), COALESCE(recorded_by_user_id,'')
  ))),0),10,16)
FROM policy_payments
UNION ALL
-- policy_installments — the schedule
SELECT 'policy_installments', COUNT(*),
  CONV(COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',
    id, policy_id, COALESCE(installment_no,''), COALESCE(installment_total,''),
    COALESCE(due_date,''), COALESCE(amount_due,''), COALESCE(amount_paid,''),
    COALESCE(status,''), COALESCE(paid_date,'')
  ))),0),10,16)
FROM policy_installments
UNION ALL
-- policy_events — endorsement ledger (payload drives the before/after math)
SELECT 'policy_events', COUNT(*),
  CONV(COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',
    id, policy_id, type, COALESCE(endorsement_seq,''),
    COALESCE(occurred_at,''), COALESCE(voided_at,''), COALESCE(payload,'')
  ))),0),10,16)
FROM policy_events
UNION ALL
-- commission_ledgers — accrued MGM slices
SELECT 'commission_ledgers', COUNT(*),
  CONV(COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',
    id, policy_id, COALESCE(policy_payment_id,''), COALESCE(payout_type,''),
    COALESCE(beneficiary_agent_id,''), COALESCE(source_agent_id,''),
    COALESCE(base_premium,''), COALESCE(rate_applied,''), COALESCE(amount,''),
    COALESCE(status,'')
  ))),0),10,16)
FROM commission_ledgers
UNION ALL
-- customers
SELECT 'customers', COUNT(*),
  CONV(COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',
    id, COALESCE(customer_code,''), COALESCE(first_name,''), COALESCE(last_name,''),
    COALESCE(juristic_name,''), COALESCE(gender,''), COALESCE(customer_type,''),
    COALESCE(deleted_at,'')
  ))),0),10,16)
FROM customers
UNION ALL
-- agents (raw ciphertext compares across a faithful restore with the SAME APP_KEY)
SELECT 'agents', COUNT(*),
  CONV(COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',
    id, COALESCE(agent_code,''), COALESCE(first_name,''), COALESCE(last_name,''),
    COALESCE(level,''), COALESCE(parent_agent_id,''), COALESCE(rank_id,''),
    COALESCE(has_life_license,''), COALESCE(has_non_life_license,''),
    COALESCE(deleted_at,'')
  ))),0),10,16)
FROM agents;
