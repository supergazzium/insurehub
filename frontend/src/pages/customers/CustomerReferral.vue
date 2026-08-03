<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCustomerStore, type CustomerReferralLink } from '../../stores/customers'
import { useAgentStore } from '../../stores/agents'
import CustomersSubnav from './CustomersSubnav.vue'

const { t } = useI18n()
const customerStore = useCustomerStore()
const agentStore = useAgentStore()

onMounted(() => {
  void customerStore.load()
  void agentStore.load()
})

// Demo product catalog (mirrors Section 4)
const products = [
  { id: 'p1', code: 'AIA-WL100', name: 'เอไอเอ ตลอดชีพ 100' },
  { id: 'p2', code: 'AIA-EN20', name: 'เอไอเอ สะสมทรัพย์ 20/10' },
  { id: 'p3', code: 'AIA-HEALTH+', name: 'เอไอเอ เฮลธ์ พลัส' },
  { id: 'p4', code: 'TLI-RET65', name: 'บำนาญ มั่นคง 65' },
  { id: 'p5', code: 'TLI-CI+', name: 'ไทยประกันชีวิต โรคร้ายแรง พรีเมียม' },
  { id: 'p6', code: 'MTI-UL', name: 'เมืองไทย ยูนิตลิงก์' },
  { id: 'p7', code: 'BLA-PA', name: 'กรุงเทพ PA สบายใจ' },
]

function productName(id: string | null) {
  if (!id) return t('customers.referral.anyProduct')
  return products.find((p) => p.id === id)?.name ?? id
}

const selectedAgentId = ref<string>('a4')

const myLinks = computed(() => customerStore.getActiveLinksForAgent(selectedAgentId.value))
const allLinksForAgent = computed(() =>
  customerStore.links.filter((l) => l.agentId === selectedAgentId.value),
)

const baseUrl = computed(() => (typeof window !== 'undefined' ? window.location.origin : 'https://app.insurehub.test'))
function linkUrl(token: string) {
  return `${baseUrl.value}/lead?ref=${token}`
}

const copiedFor = ref<string | null>(null)
async function copyLink(l: CustomerReferralLink) {
  try {
    await navigator.clipboard.writeText(linkUrl(l.token))
    copiedFor.value = l.id
    setTimeout(() => (copiedFor.value = null), 1500)
  } catch {
    /* ignore */
  }
}

// ── Generate new link ─────────────────────────────────────────────────────
const showGen = ref(false)
const genForm = reactive({
  campaign: '',
  productId: null as string | null,
})
const genValid = computed(() => genForm.campaign.trim().length >= 3)

function openGen() {
  genForm.campaign = ''
  genForm.productId = null
  showGen.value = true
}
async function submitGen() {
  if (!genValid.value) return
  await customerStore.createLink({
    agentId: selectedAgentId.value,
    productId: genForm.productId,
    campaign: genForm.campaign,
  })
  showGen.value = false
}

// ── Revoke ───────────────────────────────────────────────────────────────
const revokeTarget = ref<CustomerReferralLink | null>(null)
async function confirmRevoke() {
  if (!revokeTarget.value) return
  const target = revokeTarget.value
  revokeTarget.value = null
  await customerStore.revokeLink(target.id)
}

// ── Referred customers (filtered by agent who created them) ──────────────
const referredCustomers = computed(() =>
  customerStore.customers.filter((c) => c.createdByAgentId === selectedAgentId.value),
)

function conversionRateOf(l: CustomerReferralLink) {
  if (l.clicks === 0) return 0
  return (l.policies / l.clicks) * 100
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('customers.referral.title') }}</h1>
      <p class="text-slate-500 text-sm mt-1">{{ t('customers.referral.subtitle') }}</p>
    </header>

    <CustomersSubnav />

    <!-- Agent picker + generate button -->
    <div class="card p-4 flex flex-wrap items-end gap-3">
      <div class="flex-1 min-w-[240px]">
        <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('agents.hierarchy.selectAgent') }}</label>
        <select
          v-model="selectedAgentId"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        >
          <option v-for="a in agentStore.agents" :key="a.id" :value="a.id">
            {{ a.firstName }} {{ a.lastName }} ({{ a.agentCode }}) — {{ t(`agents.levelShort.${a.level}`) }}
          </option>
        </select>
      </div>
      <button
        @click="openGen"
        class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2"
      >
        <i class="pi pi-plus" />
        {{ t('customers.referral.generateLink') }}
      </button>
    </div>

    <!-- Active links -->
    <section>
      <h2 class="text-sm font-semibold text-slate-900 mb-3">ลิงก์ที่ใช้งานอยู่</h2>
      <div v-if="!myLinks.length" class="card p-8 text-center text-slate-400">
        <i class="pi pi-link text-3xl block mb-2" />
        <p class="text-sm">ยังไม่มีลิงก์ที่ใช้งานอยู่</p>
        <button
          @click="openGen"
          class="mt-3 px-4 py-2 bg-brand-600 text-white rounded-lg text-sm font-medium hover:bg-brand-700 transition inline-flex items-center gap-2"
        >
          <i class="pi pi-plus" />
          {{ t('customers.referral.generateLink') }}
        </button>
      </div>
      <div v-else class="space-y-3">
        <div v-for="l in myLinks" :key="l.id" class="card p-5">
          <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
            <div>
              <div class="flex items-center gap-2 text-xs text-slate-500 mb-1">
                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-brand-50 text-brand-700 font-medium">
                  <i class="pi pi-tag text-[10px] mr-1" />
                  {{ l.campaign }}
                </span>
                <span class="text-slate-400">·</span>
                <span>{{ productName(l.productId) }}</span>
              </div>
              <code class="text-sm text-slate-700 font-mono">{{ linkUrl(l.token) }}</code>
            </div>
            <div class="text-xs text-slate-400 text-right">
              <div>{{ t('customers.referral.generatedAt') }}: {{ l.generatedAt }}</div>
            </div>
          </div>

          <!-- Stats -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="text-xs text-slate-500">{{ t('customers.referral.stats.clicks') }}</div>
              <div class="text-lg font-semibold text-slate-900 mt-0.5">{{ l.clicks }}</div>
            </div>
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="text-xs text-slate-500">{{ t('customers.referral.stats.leads') }}</div>
              <div class="text-lg font-semibold text-amber-600 mt-0.5">{{ l.leads }}</div>
            </div>
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="text-xs text-slate-500">{{ t('customers.referral.stats.policies') }}</div>
              <div class="text-lg font-semibold text-emerald-600 mt-0.5">{{ l.policies }}</div>
            </div>
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="text-xs text-slate-500">{{ t('customers.referral.stats.conversionRate') }}</div>
              <div class="text-lg font-semibold text-brand-600 mt-0.5">{{ conversionRateOf(l).toFixed(1) }}%</div>
            </div>
          </div>

          <div class="flex items-center gap-2 mt-4 pt-3 border-t border-slate-100">
            <button
              @click="copyLink(l)"
              class="px-3 py-1.5 border border-slate-300 rounded-lg hover:bg-slate-50 transition flex items-center gap-1.5 text-sm"
            >
              <i :class="copiedFor === l.id ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" />
              <span>{{ copiedFor === l.id ? t('customers.referral.copied') : t('customers.referral.copyLink') }}</span>
            </button>
            <button
              @click="revokeTarget = l"
              class="px-3 py-1.5 text-sm text-rose-600 border border-rose-200 rounded-lg hover:bg-rose-50 transition flex items-center gap-1.5 ml-auto"
            >
              <i class="pi pi-ban" />
              {{ t('customers.referral.revoke') }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- Referred customers -->
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <div class="card overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
            <i class="pi pi-users text-slate-400" />
            {{ t('customers.referral.referredCustomers') }}
          </h3>
          <span class="text-xs text-slate-400">{{ referredCustomers.length }}</span>
        </div>
        <div v-if="!referredCustomers.length" class="px-5 py-10 text-center text-sm text-slate-400 italic">
          {{ t('customers.referral.noReferred') }}
        </div>
        <div v-else class="divide-y divide-slate-100">
          <div v-for="c in referredCustomers.slice(0, 8)" :key="c.id" class="px-5 py-3 flex items-center gap-3 hover:bg-slate-50/50">
            <div class="w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs font-medium shrink-0">
              {{ c.firstName.charAt(0) }}{{ c.lastName.charAt(0) }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-slate-900 truncate">{{ c.firstName }} {{ c.lastName }}</div>
              <div class="text-xs text-slate-500">
                <span class="font-mono">{{ c.customerCode }}</span>
                <span class="mx-1">·</span>
                <span>{{ c.activePolicyCount }} กรมธรรม์</span>
                <span class="mx-1">·</span>
                <span>{{ c.registeredAt }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Link history -->
      <div class="card overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
            <i class="pi pi-history text-slate-400" />
            ประวัติลิงก์ทั้งหมด
          </h3>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="text-left px-4 py-2 font-medium">แคมเปญ</th>
              <th class="text-right px-4 py-2 font-medium">คลิก</th>
              <th class="text-right px-4 py-2 font-medium">กรมธรรม์</th>
              <th class="text-left px-4 py-2 font-medium">{{ t('common.status') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="l in allLinksForAgent" :key="l.id">
              <td class="px-4 py-2">
                <div class="text-xs text-slate-900 font-medium">{{ l.campaign }}</div>
                <div class="text-[10px] text-slate-400">{{ l.generatedAt }}</div>
              </td>
              <td class="px-4 py-2 text-right text-xs">{{ l.clicks }}</td>
              <td class="px-4 py-2 text-right text-xs text-emerald-600 font-medium">{{ l.policies }}</td>
              <td class="px-4 py-2">
                <span
                  :class="[
                    'inline-flex px-2 py-0.5 rounded-md text-[11px] font-medium',
                    l.revoked ? 'bg-slate-100 text-slate-500' : 'bg-emerald-50 text-emerald-700',
                  ]"
                >
                  {{ l.revoked ? 'ยกเลิกแล้ว' : 'ใช้งาน' }}
                </span>
              </td>
            </tr>
            <tr v-if="!allLinksForAgent.length">
              <td colspan="4" class="px-4 py-8 text-center text-slate-400 text-sm italic">ยังไม่มีลิงก์</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Generate dialog -->
    <div v-if="showGen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <h3 class="font-semibold text-slate-900">{{ t('customers.referral.generateLink') }}</h3>
          <button @click="showGen = false" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="px-5 py-5 space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.referral.campaign') }} <span class="text-rose-500">*</span></label>
            <input
              v-model="genForm.campaign"
              type="text"
              required
              :placeholder="t('customers.referral.campaignPlaceholder')"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
            <p class="text-xs text-slate-500 mt-1">ใช้ตั้งชื่อเพื่อแยกแคมเปญและคำนวณ ROI</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.referral.productOptional') }}</label>
            <select
              v-model="genForm.productId"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            >
              <option :value="null">{{ t('customers.referral.anyProduct') }}</option>
              <option v-for="p in products" :key="p.id" :value="p.id">
                {{ p.code }} · {{ p.name }}
              </option>
            </select>
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="showGen = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">{{ t('common.cancel') }}</button>
          <button @click="submitGen" :disabled="!genValid" class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-50">
            {{ t('customers.referral.generateLink') }}
          </button>
        </footer>
      </div>
    </div>

    <!-- Revoke confirm -->
    <div v-if="revokeTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" @click.self="revokeTarget = null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-5 py-5">
          <div class="w-10 h-10 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mb-3">
            <i class="pi pi-ban" />
          </div>
          <h3 class="font-semibold text-slate-900">{{ t('customers.referral.revoke') }}</h3>
          <p class="text-sm text-slate-500 mt-1.5">การยกเลิกลิงก์จะหยุดรับ lead ใหม่ทันที</p>
          <div class="mt-3 p-3 bg-slate-50 border border-slate-100 rounded-lg text-sm">
            <div class="font-medium text-slate-900">{{ revokeTarget.campaign }}</div>
            <div class="text-xs text-slate-500 font-mono mt-1">{{ revokeTarget.token }}</div>
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="revokeTarget = null" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">{{ t('common.cancel') }}</button>
          <button @click="confirmRevoke" class="px-4 py-2 text-sm rounded-lg bg-rose-600 text-white font-medium hover:bg-rose-700">
            {{ t('customers.referral.revoke') }}
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>
