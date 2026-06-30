<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgentStore, type Agent, type AgentLevel } from '../../stores/agents'
import AgentsSubnav from './AgentsSubnav.vue'

const { t } = useI18n()
const store = useAgentStore()

onMounted(() => {
  void store.load()
})

// Today: 2026-06-05 (matches CLAUDE.md reference date)
const TODAY = new Date('2026-06-05')

// Convert TH Buddhist-era date string ('25XX-MM-DD') to JS Date (CE)
function thaiToDate(thaiDate: string | null): Date | null {
  if (!thaiDate) return null
  const [y, m, d] = thaiDate.split('-').map(Number)
  if (!y || !m || !d) return null
  return new Date(y - 543, m - 1, d)
}

function licenseStatusOf(a: Agent): 'valid' | 'expiringSoon' | 'expired' | 'missing' {
  if (!a.licenseExpiry) return 'missing'
  const exp = thaiToDate(a.licenseExpiry)
  if (!exp) return 'missing'
  const diff = (exp.getTime() - TODAY.getTime()) / 86_400_000
  if (diff < 0) return 'expired'
  if (diff <= 90) return 'expiringSoon'
  return 'valid'
}

function licenseBadgeClass(s: ReturnType<typeof licenseStatusOf>): string {
  return {
    valid: 'bg-emerald-50 text-emerald-700',
    expiringSoon: 'bg-amber-50 text-amber-700',
    expired: 'bg-rose-50 text-rose-700',
    missing: 'bg-slate-100 text-slate-500',
  }[s]
}

const levelOrder: AgentLevel[] = ['l1', 'l2', 'l3', 'l4', 'l5']
function levelBadgeClass(lv: AgentLevel) {
  return {
    l1: 'bg-slate-100 text-slate-600',
    l2: 'bg-sky-50 text-sky-700',
    l3: 'bg-violet-50 text-violet-700',
    l4: 'bg-amber-50 text-amber-700',
    l5: 'bg-rose-50 text-rose-700',
  }[lv]
}

// ── Filters ───────────────────────────────────────────────────────────────
const search = ref('')
const levelFilter = ref<'all' | AgentLevel>('all')
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')
const licenseFilter = ref<'all' | 'valid' | 'expiringSoon' | 'expired' | 'missing'>('all')

const filteredAgents = computed(() =>
  store.agents.filter((a) => {
    if (levelFilter.value !== 'all' && a.level !== levelFilter.value) return false
    if (statusFilter.value === 'active' && !a.active) return false
    if (statusFilter.value === 'inactive' && a.active) return false
    if (licenseFilter.value !== 'all' && licenseStatusOf(a) !== licenseFilter.value) return false
    if (search.value) {
      const q = search.value.toLowerCase()
      const hay = `${a.firstName} ${a.lastName} ${a.nickname} ${a.agentCode} ${a.email} ${a.licenseNumber}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  }),
)

const stats = computed(() => ({
  total: store.agents.length,
  active: store.agents.filter((a) => a.active).length,
  expiring: store.agents.filter((a) => {
    const s = licenseStatusOf(a)
    return s === 'expiringSoon' || s === 'expired'
  }).length,
}))

// ── Form (create / edit) ──────────────────────────────────────────────────
const showForm = ref(false)
const editing = ref<Agent | null>(null)
const isEdit = computed(() => !!editing.value)
const formTab = ref<'personal' | 'contract' | 'license' | 'bank'>('personal')
const formSubmitting = ref(false)

const defaultForm = (): Omit<Agent, 'id' | 'agentCode'> => ({
  firstName: '',
  lastName: '',
  nickname: '',
  firstNameEn: '',
  lastNameEn: '',
  gender: '',
  email: '',
  phone: '',
  lineId: '',
  idCard: '',
  birthDate: '',
  address: '',
  province: '',
  district: '',
  subDistrict: '',
  postcode: '',
  kind: 'individual',
  juristicName: '',
  taxId: '',
  vatType: '',
  bank: { bankName: '', accountNo: '', accountName: '' },
  parentAgentId: null,
  level: 'l1',
  commissionPct: store.LEVEL_PCT.l1,
  joinedAt: new Date().toISOString().slice(0, 10),
  licenseNumber: '',
  licenseIssuer: 'คปภ.',
  licenseExpiry: null,
  licenseLifeNo: '',
  licenseLifeExpiry: null,
  licenseNonLifeNo: '',
  licenseNonLifeExpiry: null,
  notes: '',
  active: true,
})

const form = reactive<Omit<Agent, 'id' | 'agentCode'>>(defaultForm())

function openCreate() {
  editing.value = null
  Object.assign(form, defaultForm())
  formTab.value = 'personal'
  showForm.value = true
}

function openEdit(a: Agent) {
  editing.value = a
  Object.assign(form, { ...a })
  formTab.value = 'personal'
  showForm.value = true
}

function closeForm() {
  showForm.value = false
}

function onLevelChange() {
  form.commissionPct = store.LEVEL_PCT[form.level]
}

const personalValid = computed(
  () =>
    form.firstName.trim().length > 0 &&
    form.lastName.trim().length > 0 &&
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email) &&
    form.phone.trim().length >= 9,
)
const contractValid = computed(() => !!form.joinedAt && form.commissionPct >= 0 && form.commissionPct <= 100)
const licenseValid = computed(() => true) // license is optional
const formValid = computed(() => personalValid.value && contractValid.value && licenseValid.value)

const uplineOptions = computed(() => store.eligibleUplines(editing.value?.id ?? null))

async function submitForm() {
  if (!formValid.value) return
  formSubmitting.value = true
  await new Promise((r) => setTimeout(r, 400))
  if (isEdit.value && editing.value) {
    store.updateAgent(editing.value.id, form)
  } else {
    store.createAgent(form)
  }
  formSubmitting.value = false
  showForm.value = false
  editing.value = null
}

// ── Transfer upline ───────────────────────────────────────────────────────
const transferTarget = ref<Agent | null>(null)
const transferNewUpline = ref<string | null>(null)
const transferReason = ref('')

function openTransfer(a: Agent) {
  transferTarget.value = a
  transferNewUpline.value = a.parentAgentId
  transferReason.value = ''
}

const transferUplineOptions = computed(() =>
  transferTarget.value ? store.eligibleUplines(transferTarget.value.id) : [],
)

function submitTransfer() {
  if (!transferTarget.value) return
  store.transferUpline(transferTarget.value.id, transferNewUpline.value)
  transferTarget.value = null
}

// ── Activate / deactivate ─────────────────────────────────────────────────
const toggleTarget = ref<Agent | null>(null)
function confirmToggle() {
  if (!toggleTarget.value) return
  store.setActive(toggleTarget.value.id, !toggleTarget.value.active)
  toggleTarget.value = null
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.agents.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.agents.description') }}</p>
      </div>
      <button
        type="button"
        @click="openCreate"
        class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2 shrink-0"
      >
        <i class="pi pi-user-plus" />
        <span class="hidden sm:inline">{{ t('agents.list.addNew') }}</span>
      </button>
    </header>

    <AgentsSubnav />

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-3">
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('agents.list.total') }}</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ stats.total }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('agents.list.activeAgents') }}</div>
        <div class="text-2xl font-semibold text-emerald-600 mt-1">{{ stats.active }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500 flex items-center gap-1.5">
          <i class="pi pi-exclamation-triangle text-amber-500" />
          {{ t('agents.list.expiringLicenses') }}
        </div>
        <div class="text-2xl font-semibold text-amber-600 mt-1">{{ stats.expiring }}</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-[240px]">
        <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
        <input
          v-model="search"
          type="search"
          :placeholder="t('agents.list.searchPlaceholder')"
          class="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-400"
        />
      </div>
      <select
        v-model="levelFilter"
        class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400"
      >
        <option value="all">{{ t('agents.cols.level') }}: {{ t('common.all') }}</option>
        <option v-for="lv in levelOrder" :key="lv" :value="lv">
          {{ t(`agents.levels.${lv}`) }}
        </option>
      </select>
      <select
        v-model="licenseFilter"
        class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400"
      >
        <option value="all">{{ t('agents.cols.license') }}: {{ t('common.all') }}</option>
        <option value="valid">{{ t('agents.licenseStatus.valid') }}</option>
        <option value="expiringSoon">{{ t('agents.licenseStatus.expiringSoon') }}</option>
        <option value="expired">{{ t('agents.licenseStatus.expired') }}</option>
        <option value="missing">{{ t('agents.licenseStatus.missing') }}</option>
      </select>
      <div class="flex items-center gap-1 bg-white border border-slate-200 rounded-lg p-0.5">
        <button
          v-for="s in (['all', 'active', 'inactive'] as const)"
          :key="s"
          type="button"
          @click="statusFilter = s"
          :class="[
            'px-3 py-1.5 text-xs font-medium rounded transition',
            statusFilter === s ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-900',
          ]"
        >
          {{ s === 'all' ? t('common.all') : s === 'active' ? t('common.active') : t('common.inactive') }}
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="text-left px-4 py-3 font-medium">{{ t('agents.cols.agent') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('agents.cols.level') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('agents.cols.upline') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('agents.cols.directDownline') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('agents.cols.totalDownline') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('agents.cols.license') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('agents.cols.status') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('agents.cols.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="a in filteredAgents" :key="a.id" class="hover:bg-slate-50/50">
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-medium shrink-0">
                    {{ a.firstName.charAt(0) }}{{ a.lastName.charAt(0) }}
                  </div>
                  <div class="min-w-0">
                    <div class="font-medium text-slate-900 truncate">
                      {{ a.firstName }} {{ a.lastName }}
                      <span v-if="a.nickname" class="text-slate-400 font-normal">({{ a.nickname }})</span>
                    </div>
                    <div class="text-xs text-slate-500 truncate">
                      <span class="font-mono">{{ a.agentCode }}</span>
                      <span class="mx-1">·</span>
                      <span>{{ a.email }}</span>
                    </div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', levelBadgeClass(a.level)]">
                  {{ t(`agents.levelShort.${a.level}`) }}
                  <span class="ml-1 text-[10px] opacity-75">{{ a.commissionPct }}%</span>
                </span>
              </td>
              <td class="px-4 py-3 text-xs">
                <template v-if="store.getAgent(a.parentAgentId)">
                  <div class="text-slate-900">
                    {{ store.getAgent(a.parentAgentId)?.firstName }} {{ store.getAgent(a.parentAgentId)?.lastName }}
                  </div>
                  <div class="text-slate-400 font-mono">{{ store.getAgent(a.parentAgentId)?.agentCode }}</div>
                </template>
                <span v-else class="text-slate-400 italic">{{ t('agents.fields.noUpline') }}</span>
              </td>
              <td class="px-4 py-3 text-right font-medium text-slate-900">
                {{ store.getDirectDownline(a.id).length }}
              </td>
              <td class="px-4 py-3 text-right font-medium text-slate-900">
                {{ store.getAllDownline(a.id).length }}
              </td>
              <td class="px-4 py-3">
                <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium', licenseBadgeClass(licenseStatusOf(a))]">
                  <i :class="{
                    valid: 'pi pi-check-circle',
                    expiringSoon: 'pi pi-exclamation-triangle',
                    expired: 'pi pi-times-circle',
                    missing: 'pi pi-minus-circle',
                  }[licenseStatusOf(a)]" class="text-[10px]" />
                  {{ t(`agents.licenseStatus.${licenseStatusOf(a)}`) }}
                </span>
                <div v-if="a.licenseExpiry" class="text-[10px] text-slate-400 mt-0.5 font-mono">
                  {{ a.licenseExpiry }}
                </div>
              </td>
              <td class="px-4 py-3">
                <span
                  :class="[
                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium',
                    a.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500',
                  ]"
                >
                  <span :class="['w-1.5 h-1.5 rounded-full', a.active ? 'bg-emerald-500' : 'bg-slate-400']" />
                  {{ a.active ? t('common.active') : t('common.inactive') }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    @click="openEdit(a)"
                    class="px-2 py-1 text-xs text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded transition"
                    :title="t('common.edit')"
                  >
                    <i class="pi pi-pencil" />
                  </button>
                  <button
                    type="button"
                    @click="openTransfer(a)"
                    class="px-2 py-1 text-xs text-violet-600 hover:bg-violet-50 rounded transition"
                    :title="t('agents.transfer.title')"
                  >
                    <i class="pi pi-arrow-right-arrow-left" />
                  </button>
                  <button
                    type="button"
                    @click="toggleTarget = a"
                    :class="[
                      'px-2 py-1 text-xs rounded transition',
                      a.active ? 'text-rose-600 hover:bg-rose-50' : 'text-emerald-600 hover:bg-emerald-50',
                    ]"
                    :title="a.active ? t('agents.confirm.deactivateTitle') : t('agents.confirm.activateTitle')"
                  >
                    <i :class="a.active ? 'pi pi-ban' : 'pi pi-check-circle'" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredAgents.length">
              <td colspan="8" class="px-4 py-10 text-center text-slate-400 text-sm">
                {{ t('common.noData') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create / edit dialog -->
    <div
      v-if="showForm"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40"
      @click.self="closeForm"
    >
      <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[92vh] flex flex-col">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <h3 class="font-semibold text-slate-900">
            {{ isEdit ? t('agents.dialog.editTitle') : t('agents.dialog.onboardTitle') }}
          </h3>
          <button @click="closeForm" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>

        <div class="border-b border-slate-100 px-5 flex items-center gap-1 shrink-0">
          <button
            v-for="tk in (['personal', 'contract', 'license', 'bank'] as const)"
            :key="tk"
            type="button"
            @click="formTab = tk"
            :class="[
              'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition flex items-center gap-2',
              formTab === tk ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
            ]"
          >
            {{ t(`agents.dialog.tabs.${tk}`) }}
            <i
              v-if="(tk === 'personal' && !personalValid) || (tk === 'contract' && !contractValid)"
              class="pi pi-exclamation-circle text-rose-400 text-xs"
            />
          </button>
        </div>

        <div class="px-5 py-5 overflow-y-auto flex-1">
          <!-- Personal -->
          <section v-if="formTab === 'personal'" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('agents.fields.firstName') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.firstName"
                  type="text"
                  required
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('agents.fields.lastName') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.lastName"
                  type="text"
                  required
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.fields.nickname') }}</label>
                <input
                  v-model="form.nickname"
                  type="text"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อภาษาอังกฤษ</label>
                <input
                  v-model="form.firstNameEn"
                  type="text"
                  placeholder="First name (EN)"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">นามสกุลภาษาอังกฤษ</label>
                <input
                  v-model="form.lastNameEn"
                  type="text"
                  placeholder="Last name (EN)"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">เพศ</label>
                <select
                  v-model="form.gender"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option value="">— ไม่ระบุ —</option>
                  <option value="male">ชาย</option>
                  <option value="female">หญิง</option>
                  <option value="other">อื่น ๆ</option>
                </select>
              </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('agents.fields.email') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.email"
                  type="email"
                  required
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('agents.fields.phone') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.phone"
                  type="tel"
                  required
                  placeholder="08x-xxx-xxxx"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.fields.lineId') }}</label>
                <input
                  v-model="form.lineId"
                  type="text"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.fields.idCard') }}</label>
                <input
                  v-model="form.idCard"
                  type="text"
                  maxlength="13"
                  inputmode="numeric"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.fields.birthDate') }}</label>
                <input
                  v-model="form.birthDate"
                  type="text"
                  placeholder="25xx-mm-dd"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">
                {{ t('agents.fields.address') }}
                <span class="ml-1 font-normal text-slate-400 text-xs">(บ้านเลขที่ / ซอย / ถนน)</span>
              </label>
              <textarea
                v-model="form.address"
                rows="2"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
              />
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">แขวง / ตำบล</label>
                <input
                  v-model="form.subDistrict"
                  type="text"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">เขต / อำเภอ</label>
                <input
                  v-model="form.district"
                  type="text"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">จังหวัด</label>
                <input
                  v-model="form.province"
                  type="text"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">รหัสไปรษณีย์</label>
                <input
                  v-model="form.postcode"
                  type="text"
                  maxlength="5"
                  inputmode="numeric"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
          </section>

          <!-- Contract -->
          <section v-if="formTab === 'contract'" class="space-y-4">
            <!-- Agent kind toggle -->
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">ประเภทตัวแทน</label>
              <div class="inline-flex border border-slate-200 bg-white rounded-lg p-0.5">
                <button
                  type="button"
                  @click="form.kind = 'individual'"
                  :class="[
                    'px-3 py-1.5 text-xs font-medium rounded transition',
                    form.kind === 'individual' ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-900',
                  ]"
                >
                  บุคคลธรรมดา
                </button>
                <button
                  type="button"
                  @click="form.kind = 'corporate'"
                  :class="[
                    'px-3 py-1.5 text-xs font-medium rounded transition',
                    form.kind === 'corporate' ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-900',
                  ]"
                >
                  นิติบุคคล / บริษัท
                </button>
              </div>
            </div>

            <!-- Corporate-only fields -->
            <div v-if="form.kind === 'corporate'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อนิติบุคคล / บริษัท</label>
                <input
                  v-model="form.juristicName"
                  type="text"
                  placeholder="เช่น บจก. สมปอง โบรกเกอร์"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">เลขประจำตัวผู้เสียภาษี</label>
                <input
                  v-model="form.taxId"
                  type="text"
                  maxlength="13"
                  inputmode="numeric"
                  placeholder="13 หลัก"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">ภาษี / หัก ณ ที่จ่าย</label>
                <select
                  v-model="form.vatType"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option value="">— เลือก —</option>
                  <option value="none">ไม่หัก ณ ที่จ่าย</option>
                  <option value="vat7">VAT 7%</option>
                  <option value="wht1">หัก ณ ที่จ่าย 1%</option>
                  <option value="wht3">หัก ณ ที่จ่าย 3%</option>
                  <option value="wht5">หัก ณ ที่จ่าย 5%</option>
                </select>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.fields.upline') }}</label>
              <select
                v-model="form.parentAgentId"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              >
                <option :value="null">{{ t('agents.fields.noUpline') }}</option>
                <option v-for="u in uplineOptions" :key="u.id" :value="u.id">
                  {{ u.firstName }} {{ u.lastName }} ({{ u.agentCode }}) — {{ t(`agents.levelShort.${u.level}`) }}
                </option>
              </select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.fields.contractLevel') }}</label>
                <select
                  v-model="form.level"
                  @change="onLevelChange"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option v-for="lv in levelOrder" :key="lv" :value="lv">
                    {{ t(`agents.levels.${lv}`) }}
                  </option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('agents.fields.commissionPct') }}
                </label>
                <div class="relative">
                  <input
                    v-model.number="form.commissionPct"
                    type="number"
                    min="0"
                    max="100"
                    step="0.5"
                    class="w-full px-3.5 py-2.5 pr-9 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                  />
                  <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">%</span>
                </div>
                <p class="text-xs text-slate-500 mt-1">ค่าเริ่มต้นตามระดับ: {{ store.LEVEL_PCT[form.level] }}%</p>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.fields.joinedAt') }}</label>
              <input
                v-model="form.joinedAt"
                type="text"
                placeholder="25xx-mm-dd"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.fields.notes') }}</label>
              <textarea
                v-model="form.notes"
                rows="2"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
              />
            </div>
          </section>

          <!-- License -->
          <section v-if="formTab === 'license'" class="space-y-5">
            <!-- Life license -->
            <div class="space-y-3 p-4 border border-slate-200 rounded-lg bg-slate-50/30">
              <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-500 flex items-center gap-2">
                <i class="pi pi-heart text-rose-400" />
                ใบอนุญาตประกันชีวิต
              </h5>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">เลขที่ใบอนุญาต</label>
                  <input
                    v-model="form.licenseLifeNo"
                    type="text"
                    placeholder="IC-66xxxx"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                  />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">วันหมดอายุ</label>
                  <input
                    v-model="form.licenseLifeExpiry"
                    type="text"
                    placeholder="25xx-mm-dd"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                  />
                </div>
              </div>
            </div>

            <!-- Non-life license -->
            <div class="space-y-3 p-4 border border-slate-200 rounded-lg bg-slate-50/30">
              <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-500 flex items-center gap-2">
                <i class="pi pi-car text-blue-400" />
                ใบอนุญาตประกันวินาศภัย
              </h5>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">เลขที่ใบอนุญาต</label>
                  <input
                    v-model="form.licenseNonLifeNo"
                    type="text"
                    placeholder="IC-66xxxx"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                  />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">วันหมดอายุ</label>
                  <input
                    v-model="form.licenseNonLifeExpiry"
                    type="text"
                    placeholder="25xx-mm-dd"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                  />
                </div>
              </div>
            </div>

            <!-- Legacy single license — kept for back-compat -->
            <details class="border border-slate-200 rounded-lg">
              <summary class="px-4 py-2 text-xs text-slate-500 cursor-pointer hover:bg-slate-50">
                ใบอนุญาตเดิม (เพิ่มเติม) — ใช้สำหรับข้อมูลที่นำเข้ามาก่อนแยกประเภท
              </summary>
              <div class="p-4 space-y-3 border-t border-slate-100">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">{{ t('agents.fields.licenseNumber') }}</label>
                  <input
                    v-model="form.licenseNumber"
                    type="text"
                    placeholder="IC-66xxxx"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                  />
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">{{ t('agents.fields.licenseIssuer') }}</label>
                    <input
                      v-model="form.licenseIssuer"
                      type="text"
                      class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                    />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">{{ t('agents.fields.licenseExpiry') }}</label>
                    <input
                      v-model="form.licenseExpiry"
                      type="text"
                      placeholder="25xx-mm-dd"
                      class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                    />
                  </div>
                </div>
              </div>
            </details>

            <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              ระบบจะแจ้งเตือนเมื่อใบอนุญาตเหลือเวลาน้อยกว่า 90 วันก่อนหมดอายุ
            </div>
          </section>

          <!-- Bank -->
          <section v-if="formTab === 'bank'" class="space-y-4">
            <div class="bg-blue-50 border border-blue-200 text-blue-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              บัญชีนี้ใช้รับเงินรีเบต / ค่าคอมมิชชั่นจากระบบ
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">ธนาคาร</label>
              <input
                v-model="form.bank.bankName"
                type="text"
                placeholder="เช่น กสิกรไทย, ไทยพาณิชย์"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">เลขที่บัญชี</label>
                <input
                  v-model="form.bank.accountNo"
                  type="text"
                  inputmode="numeric"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อบัญชี</label>
                <input
                  v-model="form.bank.accountName"
                  type="text"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
          </section>
        </div>

        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-white rounded-b-xl shrink-0">
          <button
            type="button"
            @click="closeForm"
            class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            @click="submitForm"
            :disabled="!formValid || formSubmitting"
            class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-50 flex items-center gap-2"
          >
            <i v-if="formSubmitting" class="pi pi-spin pi-spinner" />
            <span>{{ isEdit ? t('common.save') : t('common.create') }}</span>
          </button>
        </footer>
      </div>
    </div>

    <!-- Transfer dialog -->
    <div
      v-if="transferTarget"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40"
      @click.self="transferTarget = null"
    >
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <h3 class="font-semibold text-slate-900">{{ t('agents.transfer.title') }}</h3>
          <button @click="transferTarget = null" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="px-5 py-5 space-y-4">
          <div class="card p-3 bg-slate-50 border-slate-100">
            <div class="text-xs text-slate-500">ตัวแทน</div>
            <div class="font-medium text-slate-900">{{ transferTarget.firstName }} {{ transferTarget.lastName }}</div>
            <div class="text-xs text-slate-400 font-mono">{{ transferTarget.agentCode }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">{{ t('agents.transfer.currentUpline') }}</div>
            <div class="text-sm text-slate-700">
              <template v-if="store.getAgent(transferTarget.parentAgentId)">
                {{ store.getAgent(transferTarget.parentAgentId)?.firstName }} {{ store.getAgent(transferTarget.parentAgentId)?.lastName }}
                ({{ store.getAgent(transferTarget.parentAgentId)?.agentCode }})
              </template>
              <span v-else class="text-slate-400 italic">{{ t('agents.fields.noUpline') }}</span>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.transfer.newUpline') }}</label>
            <select
              v-model="transferNewUpline"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            >
              <option :value="null">{{ t('agents.fields.noUpline') }}</option>
              <option v-for="u in transferUplineOptions" :key="u.id" :value="u.id">
                {{ u.firstName }} {{ u.lastName }} ({{ u.agentCode }}) — {{ t(`agents.levelShort.${u.level}`) }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agents.transfer.reason') }}</label>
            <textarea
              v-model="transferReason"
              rows="2"
              :placeholder="t('agents.transfer.reasonPlaceholder')"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
            />
          </div>
          <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
            <i class="pi pi-exclamation-triangle mt-0.5" />
            {{ t('agents.transfer.warning') }}
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="transferTarget = null" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
            {{ t('common.cancel') }}
          </button>
          <button
            @click="submitTransfer"
            class="px-4 py-2 text-sm rounded-lg bg-violet-600 text-white font-medium hover:bg-violet-700"
          >
            {{ t('agents.transfer.submit') }}
          </button>
        </footer>
      </div>
    </div>

    <!-- Activate / deactivate confirmation -->
    <div
      v-if="toggleTarget"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40"
      @click.self="toggleTarget = null"
    >
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-5 py-5">
          <div
            :class="[
              'w-10 h-10 rounded-full flex items-center justify-center mb-3',
              toggleTarget.active ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600',
            ]"
          >
            <i :class="toggleTarget.active ? 'pi pi-exclamation-triangle' : 'pi pi-check'" />
          </div>
          <h3 class="font-semibold text-slate-900">
            {{ toggleTarget.active ? t('agents.confirm.deactivateTitle') : t('agents.confirm.activateTitle') }}
          </h3>
          <p class="text-sm text-slate-500 mt-1.5">
            {{ toggleTarget.active ? t('agents.confirm.deactivateMsg') : t('agents.confirm.activateMsg') }}
          </p>
          <div class="mt-3 p-3 bg-slate-50 border border-slate-100 rounded-lg text-sm">
            <div class="font-medium text-slate-900">{{ toggleTarget.firstName }} {{ toggleTarget.lastName }}</div>
            <div class="text-xs text-slate-500 font-mono">{{ toggleTarget.agentCode }}</div>
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="toggleTarget = null" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
            {{ t('common.cancel') }}
          </button>
          <button
            @click="confirmToggle"
            :class="[
              'px-4 py-2 text-sm rounded-lg text-white font-medium',
              toggleTarget.active ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700',
            ]"
          >
            {{ toggleTarget.active ? t('carriers.confirm.deactivate') : t('carriers.confirm.activate') }}
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>
