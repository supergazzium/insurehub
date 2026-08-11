<script setup lang="ts">
// Per-agent commission detail — read-only summary of every commission_ledgers
// row where this agent is the beneficiary.
//
// URL: /admin/agents/:code/commission-detail
//
// Sections:
//   1. Agent header (code, rank, active/license flags)
//   2. Upline chain (root ← ← ← this agent)
//   3. Ledger table: date, source, policy, payout_type, base × rate = amount
//   4. Totals: per payout type + grand total

import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError } from '../../api/client'
import {
  fetchAgentCommissionDetail,
  type AgentCommissionResponse,
  type PayoutType,
} from '../../api/mgm'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const agentCode = computed(() => String(route.params.code ?? ''))
// Free-text input so the operator can search a different agent without
// re-navigating; changing this value pushes a new URL and reloads.
const search = ref<string>(agentCode.value)

const data = ref<AgentCommissionResponse | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

// Filter by payout type — null = show all.
const filter = ref<PayoutType | null>(null)

async function load(): Promise<void> {
  if (!agentCode.value) {
    data.value = null

    return
  }
  loading.value = true
  error.value = null
  try {
    data.value = await fetchAgentCommissionDetail(agentCode.value)
  } catch (e: unknown) {
    data.value = null
    if (e instanceof ApiError && e.status === 404) {
      error.value = t('adminAgentCommission.notFound', { code: agentCode.value })
    } else {
      error.value = e instanceof ApiError ? e.message : 'Failed to load.'
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(agentCode, load)

function jumpTo(): void {
  const code = search.value.trim()
  if (!code || code === agentCode.value) return
  void router.push({ name: 'admin-agent-commission-detail', params: { code } })
}

// Filtered ledger rows.
const filteredRows = computed(() => {
  if (data.value === null) return []
  if (filter.value === null) return data.value.ledger

  return data.value.ledger.filter((r) => r.payoutType === filter.value)
})

// Formatters.
const nf2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const nfPct = (rate: number): string => (rate * 100).toFixed(3)
const fmtAmt = (n: number): string => nf2.format(n)
const fmtDate = (iso: string | null): string => {
  if (!iso) return '—'

  return iso.slice(0, 10)  // YYYY-MM-DD
}

const payoutBadge: Record<PayoutType, { label: string; class: string }> = {
  DIRECT_COMMISSION: { label: 'DIRECT', class: 'bg-brand-100 text-brand-700' },
  REFERRAL_FEE: { label: 'REFERRAL', class: 'bg-emerald-100 text-emerald-700' },
  MANAGEMENT_DIFFERENTIAL: { label: 'DIFF', class: 'bg-amber-100 text-amber-700' },
}

// ── Hierarchy tree rendering (root at top, seller at bottom) ─────────────
// The API returns uplineChain as [direct upline, next up, ..., root]. To
// render top-down we reverse and append the current agent at the end so
// the reader scans root→leaf. Each node carries a levelNumeric (parsed
// from "Lv7" → 7) used to color the rank badge on a light→dark scale.

interface TreeNode {
  code: string
  name: string
  rankCode: string | null
  rankLevel: number | null
  active: boolean
  hasLicense?: boolean
  isCurrent: boolean
  depth: number  // 0 = root
}

const hierarchyNodes = computed<TreeNode[]>(() => {
  if (data.value === null) return []
  const uplineTopDown = [...data.value.uplineChain].reverse()  // root first
  const nodes: TreeNode[] = uplineTopDown.map((u, idx) => ({
    code: u.code,
    name: u.name,
    rankCode: u.rankCode,
    rankLevel: parseRankLevel(u.rankCode),
    active: u.active,
    isCurrent: false,
    depth: idx,
  }))
  nodes.push({
    code: data.value.agent.code,
    name: data.value.agent.name,
    rankCode: data.value.agent.rankCode,
    rankLevel: data.value.agent.rankLevel,
    active: data.value.agent.active,
    hasLicense: data.value.agent.hasLicense,
    isCurrent: true,
    depth: nodes.length,
  })

  return nodes
})

function parseRankLevel(rankCode: string | null): number | null {
  if (!rankCode) return null
  const m = rankCode.match(/^Lv(\d+)$/)

  return m ? Number(m[1]) : null
}

// Rank badge color by level — darker = higher rank. 10-step Tailwind ramp.
function rankBadgeClasses(level: number | null): string {
  if (level === null) return 'bg-slate-100 text-slate-500'
  if (level >= 10) return 'bg-purple-700 text-white'
  if (level >= 9) return 'bg-purple-600 text-white'
  if (level >= 8) return 'bg-indigo-600 text-white'
  if (level >= 7) return 'bg-indigo-500 text-white'
  if (level >= 6) return 'bg-blue-500 text-white'
  if (level >= 5) return 'bg-blue-400 text-white'
  if (level >= 4) return 'bg-sky-400 text-white'
  if (level >= 3) return 'bg-cyan-300 text-cyan-900'
  if (level >= 2) return 'bg-teal-200 text-teal-800'

  return 'bg-slate-200 text-slate-700'
}
</script>

<template>
  <div class="space-y-6 max-w-6xl">
    <header class="space-y-2">
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('adminAgentCommission.title') }}</h1>
      <p class="text-sm text-slate-500">{{ t('adminAgentCommission.subtitle') }}</p>

      <div class="flex items-center gap-2">
        <input
          v-model="search"
          type="text"
          :placeholder="t('adminAgentCommission.searchPlaceholder')"
          class="border border-slate-200 rounded px-3 py-1.5 text-sm w-64 focus:outline-none focus:border-brand-400"
          @keydown.enter="jumpTo"
        />
        <button
          type="button"
          class="px-3 py-1.5 rounded bg-brand-500 text-white text-sm hover:bg-brand-600"
          @click="jumpTo"
        >
          {{ t('adminAgentCommission.load') }}
        </button>
      </div>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <div v-if="loading" class="text-sm text-slate-500">{{ t('adminAgentCommission.loading') }}</div>

    <template v-else-if="data">
      <!-- Agent header card -->
      <section class="card p-5 space-y-3">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <div class="text-xs font-mono text-slate-400">{{ data.agent.code }}</div>
            <div class="text-xl font-semibold text-slate-900">{{ data.agent.name || '—' }}</div>
          </div>
          <div class="flex items-center gap-2">
            <span
              v-if="data.agent.rankCode"
              class="px-2 py-1 rounded bg-brand-100 text-brand-700 text-xs font-medium"
            >
              {{ data.agent.rankCode }} (Lv{{ data.agent.rankLevel }})
            </span>
            <span
              class="px-2 py-1 rounded text-xs font-medium"
              :class="data.agent.active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500'"
            >
              {{ data.agent.active ? t('adminAgentCommission.active') : t('adminAgentCommission.inactive') }}
            </span>
            <span
              v-if="data.agent.hasLicense"
              class="px-2 py-1 rounded bg-indigo-100 text-indigo-700 text-xs font-medium"
            >
              {{ t('adminAgentCommission.licensed') }}
            </span>
          </div>
        </div>

      </section>

      <!-- Hierarchy tree — root at top, current agent (seller) at bottom -->
      <section class="card p-5 space-y-3">
        <div class="flex items-baseline justify-between">
          <h2 class="text-sm font-semibold text-slate-700">{{ t('adminAgentCommission.hierarchy') }}</h2>
          <span class="text-xs text-slate-400">{{ t('adminAgentCommission.hierarchyHint') }}</span>
        </div>

        <div v-if="hierarchyNodes.length <= 1 && (data.agent.rankCode === null || data.uplineChain.length === 0)" class="text-xs text-slate-400 italic">
          {{ t('adminAgentCommission.noUpline') }}
        </div>

        <ol v-else class="space-y-0">
          <li
            v-for="node in hierarchyNodes"
            :key="node.code"
            class="relative"
            :style="{ paddingLeft: (node.depth * 24) + 'px' }"
          >
            <!-- Vertical connector line from previous node -->
            <span
              v-if="node.depth > 0"
              class="absolute top-0 border-l-2 border-slate-200"
              :style="{ left: ((node.depth - 1) * 24 + 12) + 'px', height: '50%' }"
              aria-hidden="true"
            />
            <!-- Horizontal connector into this node -->
            <span
              v-if="node.depth > 0"
              class="absolute border-t-2 border-slate-200"
              :style="{
                left: ((node.depth - 1) * 24 + 12) + 'px',
                top: '50%',
                width: '12px',
              }"
              aria-hidden="true"
            />

            <div
              class="flex items-center gap-3 py-2 pl-2 pr-3 rounded border transition-colors"
              :class="[
                node.isCurrent
                  ? 'border-brand-500 bg-brand-50 shadow-sm ring-1 ring-brand-200'
                  : 'border-slate-200 hover:bg-slate-50',
                !node.active ? 'opacity-60' : '',
              ]"
            >
              <!-- Rank badge with color scale by level -->
              <span
                class="px-2 py-1 rounded text-xs font-bold font-mono min-w-[42px] text-center"
                :class="rankBadgeClasses(node.rankLevel)"
              >
                {{ node.rankCode ?? '—' }}
              </span>

              <!-- Agent code + name -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <router-link
                    v-if="!node.isCurrent"
                    :to="{ name: 'admin-agent-commission-detail', params: { code: node.code } }"
                    class="font-mono text-xs text-brand-600 hover:underline truncate"
                  >
                    {{ node.code }}
                  </router-link>
                  <span v-else class="font-mono text-xs font-semibold text-brand-800 truncate">
                    {{ node.code }}
                  </span>
                  <span v-if="node.isCurrent" class="text-[10px] px-1.5 py-0.5 rounded bg-brand-500 text-white font-medium">
                    {{ t('adminAgentCommission.thisAgent') }}
                  </span>
                </div>
                <div class="text-xs text-slate-500 truncate">{{ node.name || '—' }}</div>
              </div>

              <!-- Status pills (compact) -->
              <div class="flex items-center gap-1 shrink-0">
                <span
                  v-if="!node.active"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-slate-200 text-slate-500 font-medium"
                >
                  {{ t('adminAgentCommission.inactive') }}
                </span>
                <span
                  v-if="node.isCurrent && node.hasLicense"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700 font-medium"
                >
                  {{ t('adminAgentCommission.licensed') }}
                </span>
              </div>

              <!-- "Seller" marker on the bottom row -->
              <span
                v-if="node.isCurrent"
                class="shrink-0 text-[10px] uppercase tracking-wider text-brand-600 font-semibold"
              >
                <i class="pi pi-user mr-1" />{{ t('adminAgentCommission.seller') }}
              </span>
            </div>
          </li>
        </ol>
      </section>

      <!-- Totals card -->
      <section class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="card p-4">
          <div class="text-xs text-slate-500">{{ t('adminAgentCommission.direct') }}</div>
          <div class="text-lg font-semibold text-brand-700 mt-1">฿{{ fmtAmt(data.totals.directCommission) }}</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-slate-500">{{ t('adminAgentCommission.referral') }}</div>
          <div class="text-lg font-semibold text-emerald-700 mt-1">฿{{ fmtAmt(data.totals.referralFee) }}</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-slate-500">{{ t('adminAgentCommission.differential') }}</div>
          <div class="text-lg font-semibold text-amber-700 mt-1">฿{{ fmtAmt(data.totals.managementDifferential) }}</div>
        </div>
        <div class="card p-4 bg-slate-50 border border-slate-200">
          <div class="text-xs text-slate-500">{{ t('adminAgentCommission.grandTotal') }}</div>
          <div class="text-lg font-bold text-slate-900 mt-1">฿{{ fmtAmt(data.totals.grandTotal) }}</div>
        </div>
      </section>

      <!-- Filter chips -->
      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs text-slate-500">{{ t('adminAgentCommission.filter') }}</span>
        <button
          type="button"
          :class="[
            'px-3 py-1 rounded text-xs',
            filter === null ? 'bg-brand-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200',
          ]"
          @click="filter = null"
        >
          {{ t('adminAgentCommission.filterAll') }} ({{ data.ledger.length }})
        </button>
        <button
          type="button"
          v-for="pt in (['DIRECT_COMMISSION','REFERRAL_FEE','MANAGEMENT_DIFFERENTIAL'] as PayoutType[])"
          :key="pt"
          :class="[
            'px-3 py-1 rounded text-xs',
            filter === pt ? 'bg-brand-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200',
          ]"
          @click="filter = pt"
        >
          {{ payoutBadge[pt].label }} ({{ data.ledger.filter((r) => r.payoutType === pt).length }})
        </button>
      </div>

      <!-- Ledger table -->
      <div class="card overflow-hidden">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
            <tr>
              <th class="px-3 py-2 text-left">{{ t('adminAgentCommission.col.date') }}</th>
              <th class="px-3 py-2 text-left">{{ t('adminAgentCommission.col.type') }}</th>
              <th class="px-3 py-2 text-left">{{ t('adminAgentCommission.col.policy') }}</th>
              <th class="px-3 py-2 text-left">{{ t('adminAgentCommission.col.source') }}</th>
              <th class="px-3 py-2 text-left">{{ t('adminAgentCommission.col.product') }}</th>
              <th class="px-3 py-2 text-right">{{ t('adminAgentCommission.col.base') }}</th>
              <th class="px-3 py-2 text-right">{{ t('adminAgentCommission.col.rate') }}</th>
              <th class="px-3 py-2 text-right">{{ t('adminAgentCommission.col.amount') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in filteredRows" :key="row.id" class="hover:bg-slate-50">
              <td class="px-3 py-2 text-slate-600 whitespace-nowrap">{{ fmtDate(row.paymentDate) }}</td>
              <td class="px-3 py-2">
                <span
                  class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium"
                  :class="payoutBadge[row.payoutType].class"
                >
                  {{ payoutBadge[row.payoutType].label }}
                </span>
              </td>
              <td class="px-3 py-2">
                <div class="font-mono text-xs">{{ row.policyNo ?? '—' }}</div>
                <div class="text-[10px] text-slate-400">{{ row.paymentReference ?? '' }}</div>
              </td>
              <td class="px-3 py-2">
                <router-link
                  v-if="row.sourceAgentCode && row.sourceAgentCode !== data.agent.code"
                  :to="{ name: 'admin-agent-commission-detail', params: { code: row.sourceAgentCode } }"
                  class="font-mono text-xs text-brand-600 hover:underline"
                >
                  {{ row.sourceAgentCode }}
                </router-link>
                <span v-else-if="row.sourceAgentCode" class="font-mono text-xs text-slate-400">
                  {{ t('adminAgentCommission.self') }}
                </span>
                <span v-else class="text-xs text-slate-400">—</span>
              </td>
              <td class="px-3 py-2">
                <div class="text-xs">{{ row.productTypeNameTh ?? '—' }}</div>
                <div class="text-[10px] text-slate-400">{{ row.carrierCode ?? '' }}</div>
              </td>
              <td class="px-3 py-2 text-right font-mono text-xs">฿{{ fmtAmt(row.basePremium) }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs">{{ nfPct(row.rateApplied) }}%</td>
              <td class="px-3 py-2 text-right font-mono text-xs font-semibold">฿{{ fmtAmt(row.amount) }}</td>
            </tr>
            <tr v-if="filteredRows.length === 0">
              <td colspan="8" class="px-3 py-6 text-center text-sm text-slate-400">
                {{ t('adminAgentCommission.emptyRows') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
