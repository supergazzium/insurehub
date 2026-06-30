<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCommissionStore, type CommissionTxType } from '../../stores/commissions'
import { usePolicyStore } from '../../stores/policies'
import { useCustomerStore } from '../../stores/customers'
import { useAgentStore } from '../../stores/agents'

const { t } = useI18n()
const engine = useCommissionStore()
const policyStore = usePolicyStore()
const customerStore = useCustomerStore()
const agentStore = useAgentStore()

onMounted(async () => {
  await Promise.all([
    policyStore.load(),
    customerStore.load(),
    agentStore.load(),
    engine.load(),
  ])
  await engine.bootstrapFromExistingPolicies()
  // Default-select the first non-cancelled policy once everything is in.
  if (!previewPolicyId.value) {
    const first =
      policyStore.policies.find((p) => p.status !== 'cancelled') ?? policyStore.policies[0]
    if (first) previewPolicyId.value = first.id
  }
})

type Tab = 'preview' | 'runs' | 'settings'
const tab = ref<Tab>('preview')

// ── Preview tab ───────────────────────────────────────────────────────────
const previewPolicyId = ref<string>('')

const preview = computed(() => engine.previewForPolicy(previewPolicyId.value))

function customerName(id: string) {
  const c = customerStore.getCustomer(id)
  return c ? `${c.firstName} ${c.lastName}` : '–'
}

function agentName(id: string) {
  const a = agentStore.getAgent(id)
  return a ? `${a.firstName} ${a.lastName}` : '–'
}

const fmtTHB = (n: number) => n.toLocaleString('th-TH')

function policyDisplayNo(id: string) {
  const p = policyStore.getPolicy(id)
  if (!p) return '–'
  return p.policyNo ?? p.applicationNo ?? p.quoteNo
}

// ── Runs tab ──────────────────────────────────────────────────────────────
const runFilter = ref<'all' | CommissionTxType>('all')

const filteredRuns = computed(() => {
  return [...engine.runs].reverse().filter((r) => {
    if (runFilter.value === 'all') return true
    const txns = r.transactionIds.map((id) => engine.transactions.find((t) => t.id === id)).filter(Boolean)
    return txns.some((t) => t!.type === runFilter.value)
  })
})

function txnsForRun(runId: string) {
  const run = engine.runs.find((r) => r.id === runId)
  if (!run) return []
  return run.transactionIds
    .map((id) => engine.transactions.find((t) => t.id === id))
    .filter(Boolean) as NonNullable<ReturnType<typeof engine.transactions.find>>[]
}

const expandedRun = ref<string | null>(null)
function toggleRun(id: string) {
  expandedRun.value = expandedRun.value === id ? null : id
}

function eventBadgeClass(type: string) {
  return {
    premiumPaid: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-rose-50 text-rose-700',
    lapsed: 'bg-rose-50 text-rose-700',
    reinstated: 'bg-violet-50 text-violet-700',
  }[type] ?? 'bg-slate-100 text-slate-600'
}

function txnTypeBadgeClass(type: CommissionTxType) {
  return {
    earning: 'bg-emerald-50 text-emerald-700',
    override: 'bg-sky-50 text-sky-700',
    clawback: 'bg-rose-50 text-rose-700',
    referralBonus: 'bg-violet-50 text-violet-700',
  }[type]
}

// ── Settings tab ──────────────────────────────────────────────────────────
const showRecalcConfirm = ref(false)
const settingsSaved = ref(false)
function saveSettings() {
  settingsSaved.value = true
  setTimeout(() => (settingsSaved.value = false), 1500)
}
const recalculating = ref(false)
async function doRecalc() {
  recalculating.value = true
  try {
    await engine.recalculateAll()
  } finally {
    recalculating.value = false
    showRecalcConfirm.value = false
  }
}

const stats = engine.stats
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('commissionEngine.page.title') }}</h1>
      <p class="text-slate-500 text-sm mt-1">{{ t('commissionEngine.page.subtitle') }}</p>
    </header>

    <!-- Engine stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div class="card p-4">
        <div class="text-xs text-slate-500">รายการทั้งหมด</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ stats.totalTransactions }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">การคำนวณ</div>
        <div class="text-2xl font-semibold text-brand-600 mt-1">{{ stats.totalRuns }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">ยังไม่จ่าย</div>
        <div class="text-2xl font-semibold text-emerald-600 mt-1">฿{{ fmtTHB(stats.totalUnsettled) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500 flex items-center gap-1.5">
          <i class="pi pi-exclamation-triangle text-rose-500" />
          clawback
        </div>
        <div class="text-2xl font-semibold text-rose-600 mt-1">{{ stats.totalClawbacks }}</div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-slate-200 flex items-center gap-1">
      <button
        v-for="tk in (['preview', 'runs', 'settings'] as Tab[])"
        :key="tk"
        type="button"
        @click="tab = tk"
        :class="[
          'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition',
          tab === tk ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
        ]"
      >
        {{ t(`commissionEngine.tabs.${tk}`) }}
      </button>
    </div>

    <!-- ─── Preview tab ─── -->
    <section v-if="tab === 'preview'" class="space-y-5">
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('commissionEngine.preview.pickPolicy') }}</label>
        <select
          v-model="previewPolicyId"
          class="w-full md:max-w-2xl px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        >
          <option v-for="p in policyStore.policies" :key="p.id" :value="p.id">
            {{ policyDisplayNo(p.id) }} — {{ customerName(p.customerId) }} (ผู้ขาย: {{ agentName(p.writingAgentId) }})
          </option>
        </select>
      </div>

      <div v-if="preview.policy" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Summary card -->
        <div class="card p-5">
          <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">{{ t('commissionEngine.preview.basePremium') }}</h3>
          <div class="text-3xl font-semibold text-slate-900">฿{{ fmtTHB(preview.basePremium) }}</div>
          <div class="text-xs text-slate-500 mt-1">เบี้ยรายปีของกรมธรรม์</div>
          <div class="mt-4 pt-4 border-t border-slate-100 space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-slate-500">{{ t('commissionEngine.preview.writingAgent') }}</span>
              <span class="text-slate-900">{{ agentName(preview.policy.writingAgentId) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">{{ t('commissionEngine.preview.contractRate') }}</span>
              <span class="text-slate-900 font-mono">{{ agentStore.getAgent(preview.policy.writingAgentId)?.commissionPct }}%</span>
            </div>
          </div>
        </div>

        <!-- Chain -->
        <div class="card p-5 lg:col-span-2">
          <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">{{ t('commissionEngine.preview.uplineSplit') }}</h3>

          <div v-if="!preview.chain.length" class="text-sm text-slate-400 italic py-6 text-center">
            {{ t('commissionEngine.preview.noUplineNote') }}
          </div>

          <ol v-else class="space-y-2">
            <li
              v-for="(step, i) in preview.chain"
              :key="step.agent.id"
              :class="[
                'border rounded-lg p-3 transition',
                step.role === 'writing' ? 'border-emerald-200 bg-emerald-50/30' :
                step.role === 'override' ? 'border-sky-200 bg-sky-50/30' :
                'border-slate-200 bg-slate-50/50 opacity-70',
              ]"
            >
              <div class="flex items-center gap-3">
                <div class="text-xs text-slate-400 font-mono w-5">{{ i + 1 }}</div>
                <div class="w-9 h-9 rounded-full bg-white text-slate-700 flex items-center justify-center text-xs font-medium border border-slate-200 shrink-0">
                  {{ step.agent.firstName.charAt(0) }}{{ step.agent.lastName.charAt(0) }}
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium text-slate-900 truncate">
                    {{ step.agent.firstName }} {{ step.agent.lastName }}
                  </div>
                  <div class="text-xs text-slate-500">
                    <span class="font-mono">{{ step.agent.agentCode }}</span>
                    <span class="mx-1">·</span>
                    <span>{{ t(`agents.levelShort.${step.agent.level}`) }}</span>
                    <span class="mx-1">·</span>
                    <span class="font-mono">{{ step.agent.commissionPct }}%</span>
                  </div>
                </div>
                <div class="text-right shrink-0">
                  <span
                    v-if="step.role === 'writing'"
                    class="inline-flex px-2 py-0.5 rounded-md bg-emerald-100 text-emerald-700 text-xs font-medium mb-1"
                  >ผู้ขาย</span>
                  <span
                    v-else-if="step.role === 'override'"
                    class="inline-flex px-2 py-0.5 rounded-md bg-sky-100 text-sky-700 text-xs font-medium mb-1"
                  >override +{{ step.diffPct.toFixed(1) }}%</span>
                  <span
                    v-else
                    class="inline-flex px-2 py-0.5 rounded-md bg-slate-200 text-slate-600 text-xs font-medium mb-1"
                  >{{ t('commissionEngine.preview.compressed') }}</span>
                  <div :class="[
                    'text-base font-semibold mt-1',
                    step.role === 'compressed' ? 'text-slate-400 line-through' : 'text-slate-900',
                  ]">
                    ฿{{ fmtTHB(step.amount) }}
                  </div>
                </div>
              </div>
            </li>
          </ol>

          <p class="text-xs text-slate-500 mt-3 flex items-start gap-1.5">
            <i class="pi pi-info-circle mt-0.5" />
            {{ t('commissionEngine.preview.compressionNote') }}
          </p>
        </div>

        <!-- Referral bonus -->
        <div v-if="preview.referral.eligible" class="card p-5 lg:col-span-3 border-violet-200 bg-violet-50/30">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-violet-100 text-violet-700 flex items-center justify-center">
              <i class="pi pi-star" />
            </div>
            <div class="flex-1">
              <h4 class="font-semibold text-violet-900">{{ t('commissionEngine.transactionTypes.referralBonus') }}</h4>
              <p class="text-sm text-violet-700 mt-0.5">{{ preview.referral.note }}</p>
            </div>
            <div class="text-2xl font-semibold text-violet-700">+฿{{ fmtTHB(preview.referral.amount) }}</div>
          </div>
        </div>

        <!-- Total summary -->
        <div class="card p-5 lg:col-span-3 bg-slate-900 text-white">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-slate-400 uppercase tracking-wider">{{ t('commissionEngine.preview.totalCommission') }}</div>
              <div class="text-3xl font-semibold mt-1">฿{{ fmtTHB(preview.total + preview.referral.amount) }}</div>
              <div class="text-xs text-slate-400 mt-1">
                {{ t('commissionEngine.preview.pctOfPremium') }}:
                {{ ((preview.total + preview.referral.amount) / preview.basePremium * 100).toFixed(1) }}%
              </div>
            </div>
            <div class="text-right">
              <i class="pi pi-info-circle text-slate-500 text-sm" />
              <p class="text-xs text-slate-400 mt-1 max-w-xs">{{ t('commissionEngine.preview.previewWillBecome') }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Rule reference -->
      <div class="card p-5 bg-slate-50/50 border-slate-200">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">{{ t('commissionEngine.rules.title') }}</h3>
        <ol class="text-sm text-slate-700 space-y-2">
          <li v-for="n in 5" :key="n" class="flex items-start gap-2">
            <span class="text-slate-400 font-mono text-xs mt-0.5">{{ n }}.</span>
            <span>{{ t(`commissionEngine.rules.r${n}`) }}</span>
          </li>
        </ol>
      </div>
    </section>

    <!-- ─── Runs tab ─── -->
    <section v-if="tab === 'runs'" class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-slate-900">{{ t('commissionEngine.runs.title') }}</h2>
          <p class="text-xs text-slate-500 mt-0.5">{{ t('commissionEngine.runs.subtitle') }}</p>
        </div>
        <div class="flex items-center gap-1 bg-white border border-slate-200 rounded-lg p-0.5">
          <button
            v-for="f in (['all', 'earning', 'clawback', 'referralBonus'] as ('all' | CommissionTxType)[])"
            :key="f"
            type="button"
            @click="runFilter = f"
            :class="[
              'px-3 py-1.5 text-xs font-medium rounded transition',
              runFilter === f ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-900',
            ]"
          >
            {{ f === 'all' ? t('commissionEngine.runs.filterAll') :
               f === 'earning' ? t('commissionEngine.runs.filterEarning') :
               f === 'clawback' ? t('commissionEngine.runs.filterClawback') :
               t('commissionEngine.runs.filterReferral') }}
          </button>
        </div>
      </div>

      <div v-if="!filteredRuns.length" class="card p-10 text-center text-slate-400">
        <i class="pi pi-calculator text-3xl block mb-2" />
        <p class="text-sm">{{ t('commissionEngine.runs.noRuns') }}</p>
      </div>

      <div v-else class="card overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="w-8" />
              <th class="text-left px-4 py-3 font-medium">{{ t('commissionEngine.runs.eventCol') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('commissionEngine.runs.policyCol') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('commissionEngine.runs.runAtCol') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('commissionEngine.runs.txCountCol') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('commissionEngine.runs.totalCol') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <template v-for="r in filteredRuns" :key="r.id">
              <tr class="hover:bg-slate-50/50 cursor-pointer" @click="toggleRun(r.id)">
                <td class="px-2 py-3 text-center">
                  <i :class="expandedRun === r.id ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" class="text-xs text-slate-400" />
                </td>
                <td class="px-4 py-3">
                  <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', eventBadgeClass(r.policyEventType)]">
                    {{ t(`policies.events.${r.policyEventType}`) }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <div class="font-mono text-xs text-slate-900">{{ policyDisplayNo(r.policyId) }}</div>
                  <div class="text-[10px] text-slate-400">{{ customerName(policyStore.getPolicy(r.policyId)?.customerId ?? '') }}</div>
                </td>
                <td class="px-4 py-3 text-xs text-slate-500 font-mono">{{ r.runAt }}</td>
                <td class="px-4 py-3 text-right font-medium text-slate-900">{{ r.transactionIds.length }}</td>
                <td class="px-4 py-3 text-right font-medium" :class="txnsForRun(r.id).reduce((s, t) => s + t.amount, 0) < 0 ? 'text-rose-600' : 'text-emerald-600'">
                  {{ txnsForRun(r.id).reduce((s, t) => s + t.amount, 0) < 0 ? '−' : '+' }}฿{{ fmtTHB(Math.abs(txnsForRun(r.id).reduce((s, t) => s + t.amount, 0))) }}
                </td>
              </tr>
              <tr v-if="expandedRun === r.id" class="bg-slate-50/40">
                <td colspan="6" class="px-12 py-4">
                  <div class="text-xs text-slate-500 mb-2 flex items-center gap-3">
                    <span>{{ t('commissionEngine.runs.sourceEvent') }}: <span class="font-mono text-slate-700">{{ r.policyEventId }}</span></span>
                  </div>
                  <div class="rounded-lg border border-slate-200 bg-white overflow-hidden">
                    <table class="w-full text-xs">
                      <thead class="bg-slate-50 text-slate-500 uppercase tracking-wider text-[10px]">
                        <tr>
                          <th class="text-left px-3 py-2 font-medium">ประเภท</th>
                          <th class="text-left px-3 py-2 font-medium">{{ t('commissionEngine.txn.forAgent') }}</th>
                          <th class="text-right px-3 py-2 font-medium">{{ t('commissionEngine.txn.basePremium') }}</th>
                          <th class="text-right px-3 py-2 font-medium">{{ t('commissionEngine.txn.diffPct') }}</th>
                          <th class="text-right px-3 py-2 font-medium">{{ t('commissionEngine.txn.amount') }}</th>
                          <th class="text-left px-3 py-2 font-medium">{{ t('commissionEngine.txn.idempotencyKey') }}</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-slate-100">
                        <tr v-for="t2 in txnsForRun(r.id)" :key="t2.id">
                          <td class="px-3 py-2">
                            <span :class="['inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium', txnTypeBadgeClass(t2.type)]">
                              {{ t(`commissionEngine.transactionTypes.${t2.type}`) }}
                            </span>
                          </td>
                          <td class="px-3 py-2">
                            <div class="text-slate-900">{{ agentName(t2.agentId) }}</div>
                            <div class="text-[10px] text-slate-400">{{ agentStore.getAgent(t2.agentId)?.agentCode }} · {{ t2.payerLevel ? t(`agents.levelShort.${t2.payerLevel}`) : '' }}</div>
                          </td>
                          <td class="px-3 py-2 text-right text-slate-700">฿{{ fmtTHB(t2.basePremium) }}</td>
                          <td class="px-3 py-2 text-right font-mono text-slate-700">{{ t2.diffPct.toFixed(1) }}%</td>
                          <td class="px-3 py-2 text-right font-semibold" :class="t2.amount < 0 ? 'text-rose-600' : 'text-emerald-600'">
                            {{ t2.amount < 0 ? '−' : '+' }}฿{{ fmtTHB(Math.abs(t2.amount)) }}
                          </td>
                          <td class="px-3 py-2 font-mono text-[10px] text-slate-400 truncate max-w-[200px]">{{ t2.idempotencyKey }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ─── Settings tab ─── -->
    <section v-if="tab === 'settings'" class="space-y-5">
      <!-- Mode -->
      <div class="card p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">{{ t('commissionEngine.settings.modeTitle') }}</h3>
        <p class="text-xs text-slate-500 mb-4">{{ t('commissionEngine.settings.modeCurrent') }}</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label
            v-for="m in (['asEarned', 'advance'] as const)"
            :key="m"
            :class="[
              'border rounded-lg p-3 cursor-pointer transition flex items-start gap-3',
              engine.mode === m ? 'ring-2 ring-brand-500 border-brand-200' : 'border-slate-200 hover:border-slate-300',
              m === 'advance' ? 'opacity-50 cursor-not-allowed' : '',
            ]"
          >
            <input
              type="radio"
              v-model="engine.mode"
              :value="m"
              :disabled="m === 'advance'"
              class="mt-0.5 w-4 h-4 text-brand-600 focus:ring-brand-500"
            />
            <div>
              <div class="font-medium text-slate-900 text-sm">{{ t(`commissionEngine.settings.mode${m === 'asEarned' ? 'AsEarned' : 'Advance'}`) }}</div>
              <div v-if="m === 'advance'" class="text-xs text-slate-400 mt-0.5">v2 — เร็วๆ นี้</div>
            </div>
          </label>
        </div>
      </div>

      <!-- Referral bonus -->
      <div class="card p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">{{ t('commissionEngine.settings.referralTitle') }}</h3>
        <label class="flex items-start gap-3 cursor-pointer pt-2 pb-3">
          <input
            v-model="engine.referralConfig.enabled"
            type="checkbox"
            class="mt-0.5 w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
          />
          <div>
            <div class="text-sm font-medium text-slate-700">{{ t('commissionEngine.settings.referralEnabled') }}</div>
            <div class="text-xs text-slate-500 mt-0.5">{{ t('commissionEngine.settings.referralEnabledHint') }}</div>
          </div>
        </label>

        <div v-if="engine.referralConfig.enabled" class="mt-3 pt-3 border-t border-slate-100 space-y-3">
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-2">{{ t('commissionEngine.settings.referralType') }}</label>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
              <label
                v-for="rt in (['flat', 'pctOfFirstYear'] as const)"
                :key="rt"
                :class="[
                  'border rounded-lg p-3 cursor-pointer transition flex items-center gap-3',
                  engine.referralConfig.type === rt ? 'ring-2 ring-brand-500 border-brand-200' : 'border-slate-200 hover:border-slate-300',
                ]"
              >
                <input
                  type="radio"
                  v-model="engine.referralConfig.type"
                  :value="rt"
                  class="w-4 h-4 text-brand-600 focus:ring-brand-500"
                />
                <span class="text-sm text-slate-700">{{ t(`commissionEngine.settings.referral${rt === 'flat' ? 'Flat' : 'PctOfFirstYear'}`) }}</span>
              </label>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div v-if="engine.referralConfig.type === 'flat'">
              <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('commissionEngine.settings.referralFlatAmount') }}</label>
              <input
                v-model.number="engine.referralConfig.flatAmount"
                type="number"
                min="0"
                step="100"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500"
              />
            </div>
            <div v-else>
              <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('commissionEngine.settings.referralPctValue') }}</label>
              <div class="relative">
                <input
                  v-model.number="engine.referralConfig.pctValue"
                  type="number"
                  min="0"
                  max="100"
                  step="0.5"
                  class="w-full px-3.5 py-2.5 pr-9 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500"
                />
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">%</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Compression rule (informational) -->
      <div class="card p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-1">{{ t('commissionEngine.settings.compressionTitle') }}</h3>
        <p class="text-xs text-slate-500">{{ t('commissionEngine.settings.compressionDesc') }}</p>
      </div>

      <!-- Recalculate -->
      <div class="card p-5 border-amber-200 bg-amber-50/30">
        <div class="flex items-start gap-3 mb-3">
          <div class="w-9 h-9 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
            <i class="pi pi-replay" />
          </div>
          <div>
            <h3 class="text-sm font-semibold text-slate-900">{{ t('commissionEngine.settings.recalcTitle') }}</h3>
            <p class="text-xs text-slate-600 mt-1">{{ t('commissionEngine.settings.recalcDesc') }}</p>
          </div>
        </div>
        <button
          type="button"
          @click="showRecalcConfirm = true"
          class="px-4 py-2 text-sm bg-amber-600 text-white rounded-lg font-medium hover:bg-amber-700 transition flex items-center gap-2"
        >
          <i class="pi pi-refresh" />
          {{ t('commissionEngine.settings.recalcButton') }}
        </button>
      </div>

      <!-- Save action -->
      <div class="flex items-center justify-end gap-3">
        <span v-if="settingsSaved" class="text-emerald-600 text-sm flex items-center gap-1">
          <i class="pi pi-check-circle" />
          บันทึกแล้ว
        </span>
        <button
          @click="saveSettings"
          class="px-5 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition"
        >
          {{ t('commissionEngine.settings.saveButton') }}
        </button>
      </div>
    </section>

    <!-- Recalc confirm -->
    <div v-if="showRecalcConfirm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" @click.self="showRecalcConfirm = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-5 py-5">
          <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center mb-3">
            <i class="pi pi-exclamation-triangle" />
          </div>
          <h3 class="font-semibold text-slate-900">{{ t('commissionEngine.settings.recalcConfirmTitle') }}</h3>
          <p class="text-sm text-slate-500 mt-1.5">{{ t('commissionEngine.settings.recalcConfirmDesc') }}</p>
          <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 mt-3 flex items-start gap-2">
            <i class="pi pi-info-circle mt-0.5" />
            <span>{{ t('commissionEngine.settings.recalcWarning') }}</span>
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="showRecalcConfirm = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">{{ t('common.cancel') }}</button>
          <button @click="doRecalc" class="px-4 py-2 text-sm rounded-lg bg-amber-600 text-white font-medium hover:bg-amber-700">
            {{ t('common.confirm') }}
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>
