// Commissions store — calculator runs client-side; transactions are persisted
// to the Laravel API. The backend has a unique constraint on
// (tenant_id, idempotency_key), so duplicate posts return the existing row
// instead of erroring — which makes this safe under concurrent clients.
//
// The `runs` array remains client-only (no server table); the UI uses it to
// group per-event transactions for display.

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useAgentStore, type Agent, type AgentLevel } from './agents'
import { usePolicyStore, type Policy, type PolicyEvent } from './policies'
import { useCustomerStore } from './customers'
import { api, buildQuery, type Paginated, type Single } from '../api/client'

export type CommissionTxType = 'earning' | 'override' | 'clawback' | 'referralBonus'
export type CommissionTxStatus = 'unsettled' | 'settled' | 'reversed'

export interface CommissionTransaction {
  id: string
  type: CommissionTxType
  status: CommissionTxStatus
  agentId: string
  policyId: string
  policyEventId: string
  idempotencyKey: string
  reversesTxnId: string | null
  basePremium: number
  payerLevel: AgentLevel | null
  diffPct: number
  amount: number
  createdAt: string
  settledByPayoutId: string | null
}

export interface CommissionRun {
  id: string
  policyId: string
  policyEventId: string
  policyEventType: string
  runAt: string
  transactionIds: string[]
}

export interface ReferralBonusConfig {
  enabled: boolean
  type: 'flat' | 'pctOfFirstYear'
  flatAmount: number
  pctValue: number
}

export type CommissionMode = 'asEarned' | 'advance'

const LEVEL_RANK: Record<AgentLevel, number> = { l1: 1, l2: 2, l3: 3, l4: 4, l5: 5 }

/** Body shape sent to the API. The server fills in `id`, `createdAt`,
 *  `settledByPayoutId`. */
type TxnPayload = Omit<CommissionTransaction, 'id' | 'createdAt' | 'settledByPayoutId'>

export const useCommissionStore = defineStore('commissions', () => {
  const agentStore = useAgentStore()
  const policyStore = usePolicyStore()
  const customerStore = useCustomerStore()

  // ── State ────────────────────────────────────────────────────────────────
  const transactions = ref<CommissionTransaction[]>([])
  const runs = ref<CommissionRun[]>([])
  const mode = ref<CommissionMode>('asEarned')
  const referralConfig = ref<ReferralBonusConfig>({
    enabled: true,
    type: 'flat',
    flatAmount: 500,
    pctValue: 5,
  })
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)

  // ── Idempotency helpers (cache lookup) ───────────────────────────────────
  function hasTransactionFor(idempotencyKey: string): boolean {
    return transactions.value.some(
      (t) => t.idempotencyKey === idempotencyKey && t.status !== 'reversed',
    )
  }

  function makeIdempotencyKey(policyEventId: string, agentId: string, kind: string): string {
    return `${kind}:${policyEventId}:${agentId}`
  }

  // ── Loader ───────────────────────────────────────────────────────────────
  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const all: CommissionTransaction[] = []
      let page = 1
      // eslint-disable-next-line no-constant-condition
      while (true) {
        const response = await api.get<Paginated<CommissionTransaction>>(
          `commissions/transactions${buildQuery({ page, perPage: 100 })}`,
        )
        all.push(...response.data)
        const meta = response.meta
        if (!meta || page >= meta.last_page) break
        page += 1
      }
      transactions.value = all
      loaded.value = true
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load commissions.'
      throw err
    } finally {
      loading.value = false
    }
  }

  /** POST one transaction and append the server's authoritative row to the cache. */
  async function postTransaction(payload: TxnPayload): Promise<CommissionTransaction> {
    const response = await api.post<Single<CommissionTransaction>>(
      'commissions/transactions',
      payload,
    )
    const created = response.data
    // The server returns the existing row on idempotency collisions, so dedupe locally too.
    const existingIdx = transactions.value.findIndex((t) => t.id === created.id)
    if (existingIdx === -1) {
      transactions.value = [created, ...transactions.value]
    }
    return created
  }

  // ── Core chain math (unchanged from the in-memory version) ───────────────
  interface ChainStep {
    agent: Agent
    role: 'writing' | 'override' | 'compressed'
    diffPct: number
    amount: number
  }

  function buildCommissionChain(writingAgentId: string, basePremium: number): ChainStep[] {
    const writing = agentStore.getAgent(writingAgentId)
    if (!writing) return []
    const steps: ChainStep[] = []
    const writingPct = writing.commissionPct
    steps.push({
      agent: writing,
      role: 'writing',
      diffPct: writingPct,
      amount: Math.round(basePremium * (writingPct / 100)),
    })
    const chain = agentStore.getUplineChain(writingAgentId)
    let lastIncludedPct = writingPct
    for (const ancestor of chain) {
      const diff = ancestor.commissionPct - lastIncludedPct
      if (diff > 0) {
        steps.push({
          agent: ancestor,
          role: 'override',
          diffPct: diff,
          amount: Math.round(basePremium * (diff / 100)),
        })
        lastIncludedPct = ancestor.commissionPct
      } else {
        steps.push({ agent: ancestor, role: 'compressed', diffPct: 0, amount: 0 })
      }
    }
    return steps
  }

  function previewForPolicy(policyId: string): {
    policy: Policy | null
    basePremium: number
    chain: ChainStep[]
    referral: { eligible: boolean; amount: number; note: string }
    total: number
  } {
    const policy = policyStore.getPolicy(policyId)
    if (!policy) {
      return {
        policy: null,
        basePremium: 0,
        chain: [],
        referral: { eligible: false, amount: 0, note: '' },
        total: 0,
      }
    }
    const chain = buildCommissionChain(policy.writingAgentId, policy.annualPremium)
    const total = chain.reduce((s, c) => s + c.amount, 0)
    const customer = customerStore.getCustomer(policy.customerId)
    let referral = { eligible: false, amount: 0, note: '' }
    if (
      referralConfig.value.enabled &&
      customer?.createdByAgentId &&
      customer.createdByAgentId !== policy.writingAgentId
    ) {
      const customerPolicies = policyStore.policiesForCustomer(policy.customerId)
      const isFirstPolicy = customerPolicies[0]?.id === policy.id
      if (isFirstPolicy) {
        const writingPctAmount = Math.round(
          (policy.annualPremium *
            (agentStore.getAgent(policy.writingAgentId)?.commissionPct ?? 0)) /
            100,
        )
        const amount =
          referralConfig.value.type === 'flat'
            ? referralConfig.value.flatAmount
            : Math.round(writingPctAmount * (referralConfig.value.pctValue / 100))
        const refAgent = agentStore.getAgent(customer.createdByAgentId)
        referral = {
          eligible: true,
          amount,
          note: `แนะนำโดย ${refAgent?.firstName ?? ''} ${refAgent?.lastName ?? ''}`,
        }
      }
    }
    return { policy, basePremium: policy.annualPremium, chain, referral, total }
  }

  // ── Earning calculation (writes via API) ─────────────────────────────────
  async function calculateForEvent(
    policy: Policy,
    event: PolicyEvent,
  ): Promise<CommissionRun | null> {
    if (event.type !== 'premiumPaid') return null
    const basePremium =
      typeof event.payload.amount === 'number' ? event.payload.amount : policy.annualPremium
    const chain = buildCommissionChain(policy.writingAgentId, basePremium)
    const txnIds: string[] = []

    for (const step of chain) {
      if (step.role === 'compressed') continue
      if (step.amount <= 0) continue
      const kind = step.role === 'writing' ? 'earning' : 'override'
      const idempotencyKey = makeIdempotencyKey(event.id, step.agent.id, kind)
      if (hasTransactionFor(idempotencyKey)) continue
      const created = await postTransaction({
        type: kind as CommissionTxType,
        status: 'unsettled',
        agentId: step.agent.id,
        policyId: policy.id,
        policyEventId: event.id,
        idempotencyKey,
        reversesTxnId: null,
        basePremium,
        payerLevel: step.agent.level,
        diffPct: step.diffPct,
        amount: step.amount,
      })
      txnIds.push(created.id)
    }

    // Referral bonus on the FIRST premiumPaid of the customer's FIRST policy.
    const customer = customerStore.getCustomer(policy.customerId)
    if (
      referralConfig.value.enabled &&
      customer?.createdByAgentId &&
      customer.createdByAgentId !== policy.writingAgentId
    ) {
      const customerPolicies = policyStore.policiesForCustomer(policy.customerId)
      const isFirstPolicy = customerPolicies[0]?.id === policy.id
      const priorPaidEvents = policy.events.filter(
        (e) => e.type === 'premiumPaid' && e.at < event.at,
      )
      if (isFirstPolicy && priorPaidEvents.length === 0) {
        const writingPctAmount = Math.round(
          (basePremium * (agentStore.getAgent(policy.writingAgentId)?.commissionPct ?? 0)) / 100,
        )
        const amount =
          referralConfig.value.type === 'flat'
            ? referralConfig.value.flatAmount
            : Math.round(writingPctAmount * (referralConfig.value.pctValue / 100))
        const idempotencyKey = makeIdempotencyKey(
          event.id,
          customer.createdByAgentId,
          'referralBonus',
        )
        if (!hasTransactionFor(idempotencyKey)) {
          const created = await postTransaction({
            type: 'referralBonus',
            status: 'unsettled',
            agentId: customer.createdByAgentId,
            policyId: policy.id,
            policyEventId: event.id,
            idempotencyKey,
            reversesTxnId: null,
            basePremium,
            payerLevel: agentStore.getAgent(customer.createdByAgentId)?.level ?? null,
            diffPct: 0,
            amount,
          })
          txnIds.push(created.id)
        }
      }
    }

    if (!txnIds.length) return null
    const run: CommissionRun = {
      id: 'crun-' + Math.random().toString(36).slice(2, 10),
      policyId: policy.id,
      policyEventId: event.id,
      policyEventType: event.type,
      runAt: event.at,
      transactionIds: txnIds,
    }
    runs.value = [...runs.value, run]
    return run
  }

  async function generateClawback(
    policy: Policy,
    event: PolicyEvent,
  ): Promise<CommissionRun | null> {
    if (event.type !== 'cancelled' && event.type !== 'lapsed') return null
    const originals = transactions.value.filter(
      (t) => t.policyId === policy.id && t.type !== 'clawback' && t.status !== 'reversed',
    )
    const txnIds: string[] = []
    for (const orig of originals) {
      const idempotencyKey = `clawback:${event.id}:${orig.id}`
      if (hasTransactionFor(idempotencyKey)) continue
      const created = await postTransaction({
        type: 'clawback',
        status: 'unsettled',
        agentId: orig.agentId,
        policyId: policy.id,
        policyEventId: event.id,
        idempotencyKey,
        reversesTxnId: orig.id,
        basePremium: orig.basePremium,
        payerLevel: orig.payerLevel,
        diffPct: orig.diffPct,
        amount: -Math.abs(orig.amount),
      })
      // Reflect reversal locally — the server keeps both rows for audit.
      transactions.value = transactions.value.map((t) =>
        t.id === orig.id ? { ...t, status: 'reversed' } : t,
      )
      txnIds.push(created.id)
    }
    if (!txnIds.length) return null
    const run: CommissionRun = {
      id: 'crun-' + Math.random().toString(36).slice(2, 10),
      policyId: policy.id,
      policyEventId: event.id,
      policyEventType: event.type,
      runAt: event.at,
      transactionIds: txnIds,
    }
    runs.value = [...runs.value, run]
    return run
  }

  async function reverseClawbackOnReinstate(
    policy: Policy,
    event: PolicyEvent,
  ): Promise<CommissionRun | null> {
    if (event.type !== 'reinstated') return null
    const clawbacks = transactions.value.filter(
      (t) => t.policyId === policy.id && t.type === 'clawback' && t.reversesTxnId,
    )
    const txnIds: string[] = []
    for (const cb of clawbacks) {
      const idempotencyKey = `reinstate:${event.id}:${cb.id}`
      if (hasTransactionFor(idempotencyKey)) continue
      const created = await postTransaction({
        type: 'override',
        status: 'unsettled',
        agentId: cb.agentId,
        policyId: policy.id,
        policyEventId: event.id,
        idempotencyKey,
        reversesTxnId: cb.id,
        basePremium: cb.basePremium,
        payerLevel: cb.payerLevel,
        diffPct: cb.diffPct,
        amount: Math.abs(cb.amount),
      })
      txnIds.push(created.id)
    }
    if (!txnIds.length) return null
    const run: CommissionRun = {
      id: 'crun-' + Math.random().toString(36).slice(2, 10),
      policyId: policy.id,
      policyEventId: event.id,
      policyEventType: event.type,
      runAt: event.at,
      transactionIds: txnIds,
    }
    runs.value = [...runs.value, run]
    return run
  }

  async function bootstrapFromExistingPolicies(): Promise<void> {
    // Ensure transactions are loaded first so the idempotency cache is hot.
    await load()
    if (transactions.value.length > 0) return // already done
    for (const policy of policyStore.policies) {
      const orderedEvents = [...policy.events].sort((a, b) => (a.at < b.at ? -1 : 1))
      for (const ev of orderedEvents) {
        if (ev.type === 'premiumPaid') {
          await calculateForEvent(policy, ev)
        } else if (ev.type === 'cancelled' || ev.type === 'lapsed') {
          await generateClawback(policy, ev)
        } else if (ev.type === 'reinstated') {
          await reverseClawbackOnReinstate(policy, ev)
        }
      }
    }
  }

  async function processPolicyEvent(
    policyId: string,
    eventId: string,
  ): Promise<CommissionRun | null> {
    const policy = policyStore.getPolicy(policyId)
    if (!policy) return null
    const ev = policy.events.find((e) => e.id === eventId)
    if (!ev) return null
    if (ev.type === 'premiumPaid') return calculateForEvent(policy, ev)
    if (ev.type === 'cancelled' || ev.type === 'lapsed') return generateClawback(policy, ev)
    if (ev.type === 'reinstated') return reverseClawbackOnReinstate(policy, ev)
    return null
  }

  async function recalculateAll(): Promise<void> {
    const seen = new Set<string>()
    const key = (t: CommissionTransaction) => `${t.policyId}:${t.policyEventId}`
    const active = transactions.value.filter(
      (t) => t.status === 'unsettled' && t.type !== 'clawback',
    )
    // First, reverse every active non-clawback txn.
    for (const t of active) {
      await postTransaction({
        type: 'clawback',
        status: 'unsettled',
        agentId: t.agentId,
        policyId: t.policyId,
        policyEventId: t.policyEventId,
        idempotencyKey: `recalc:${t.id}`,
        reversesTxnId: t.id,
        basePremium: t.basePremium,
        payerLevel: t.payerLevel,
        diffPct: t.diffPct,
        amount: -Math.abs(t.amount),
      })
      transactions.value = transactions.value.map((x) =>
        x.id === t.id ? { ...x, status: 'reversed' } : x,
      )
    }
    // Re-run earning calc for each unique (policy, event).
    for (const t of active) {
      const k = key(t)
      if (seen.has(k)) continue
      seen.add(k)
      const policy = policyStore.getPolicy(t.policyId)
      if (!policy) continue
      const ev = policy.events.find((e) => e.id === t.policyEventId)
      if (!ev) continue
      if (ev.type === 'premiumPaid') await calculateForEvent(policy, ev)
    }
  }

  // ── Queries ──────────────────────────────────────────────────────────────
  function transactionsForAgent(agentId: string): CommissionTransaction[] {
    return transactions.value.filter((t) => t.agentId === agentId)
  }
  function transactionsForPolicy(policyId: string): CommissionTransaction[] {
    return transactions.value.filter((t) => t.policyId === policyId)
  }
  function unsettledBalanceForAgent(agentId: string): number {
    return transactionsForAgent(agentId)
      .filter((t) => t.status === 'unsettled')
      .reduce((s, t) => s + t.amount, 0)
  }

  const stats = computed(() => ({
    totalTransactions: transactions.value.length,
    totalRuns: runs.value.length,
    totalUnsettled: transactions.value
      .filter((t) => t.status === 'unsettled')
      .reduce((s, t) => s + t.amount, 0),
    totalClawbacks: transactions.value.filter((t) => t.type === 'clawback').length,
    totalReferral: transactions.value
      .filter((t) => t.type === 'referralBonus')
      .reduce((s, t) => s + t.amount, 0),
  }))

  return {
    // state
    transactions,
    runs,
    mode,
    referralConfig,
    stats,
    loading,
    loaded,
    error,
    // loaders
    load,
    // engine
    previewForPolicy,
    calculateForEvent,
    generateClawback,
    reverseClawbackOnReinstate,
    processPolicyEvent,
    bootstrapFromExistingPolicies,
    recalculateAll,
    // helpers
    LEVEL_RANK,
    transactionsForAgent,
    transactionsForPolicy,
    unsettledBalanceForAgent,
  }
})
