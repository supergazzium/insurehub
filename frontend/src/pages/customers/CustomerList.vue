<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCustomerStore, type Customer, type KycDocType } from '../../stores/customers'
import { useAgentStore } from '../../stores/agents'
import { usePolicyStore, type PolicyStatus } from '../../stores/policies'
import CustomersSubnav from './CustomersSubnav.vue'

const { t } = useI18n()
const customerStore = useCustomerStore()
const agentStore = useAgentStore()
const policyStore = usePolicyStore()

onMounted(() => {
  void customerStore.load()
  void agentStore.load()
  void policyStore.load()
})

function policyStatusBadgeClass(s: PolicyStatus): string {
  return {
    quote: 'bg-slate-100 text-slate-600',
    application: 'bg-amber-50 text-amber-700',
    submitted: 'bg-amber-50 text-amber-700',
    issued: 'bg-sky-50 text-sky-700',
    active: 'bg-emerald-50 text-emerald-700',
    lapsed: 'bg-rose-50 text-rose-700',
    cancelled: 'bg-slate-100 text-slate-500',
    reinstated: 'bg-violet-50 text-violet-700',
    expired: 'bg-slate-100 text-slate-500',
  }[s]
}

const productNameMap: Record<string, string> = {
  p1: 'เอไอเอ ตลอดชีพ 100',
  p2: 'เอไอเอ สะสมทรัพย์ 20/10',
  p3: 'เอไอเอ เฮลธ์ พลัส',
  p4: 'บำนาญ มั่นคง 65',
  p5: 'ไทยประกันชีวิต โรคร้ายแรง พรีเมียม',
  p6: 'เมืองไทย ยูนิตลิงก์',
  p7: 'กรุงเทพ PA สบายใจ',
  p8: 'วิริยะ ประกันรถยนต์ ชั้น 1',
  p9: 'ทิพย โฮม เซฟ',
  p10: 'ทิพย ทราเวล พลัส',
  p11: 'อลิอันซ์ เทอม 10',
}

// "Current user" for demo — assume admin
const CURRENT_USER_ID = 'u1'
const CURRENT_AGENT_ID = 'a4' // pretend "I am" agent jiraporn

// ── Filters ───────────────────────────────────────────────────────────────
const search = ref('')
const view = ref<'all' | 'mine' | 'unassigned'>('all')
const kycFilter = ref<'all' | 'complete' | 'partial' | 'missing'>('all')
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')

const filteredCustomers = computed(() =>
  customerStore.customers.filter((c) => {
    if (view.value === 'mine' && c.assignedAgentId !== CURRENT_AGENT_ID) return false
    if (view.value === 'unassigned' && c.assignedAgentId !== null) return false
    if (kycFilter.value !== 'all' && customerStore.kycStatus(c) !== kycFilter.value) return false
    if (statusFilter.value === 'active' && !c.active) return false
    if (statusFilter.value === 'inactive' && c.active) return false
    if (search.value) {
      const q = search.value.toLowerCase()
      const hay = `${c.firstName} ${c.lastName} ${c.nickname} ${c.customerCode} ${c.idCard} ${c.phone} ${c.email}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  }),
)

const stats = computed(() => ({
  total: customerStore.customers.length,
  mine: customerStore.customers.filter((c) => c.assignedAgentId === CURRENT_AGENT_ID).length,
  unassigned: customerStore.unassignedCustomers.length,
  withPolicies: customerStore.customers.filter((c) => c.activePolicyCount > 0).length,
}))

function kycBadgeClass(s: 'complete' | 'partial' | 'missing') {
  return {
    complete: 'bg-emerald-50 text-emerald-700',
    partial: 'bg-amber-50 text-amber-700',
    missing: 'bg-rose-50 text-rose-700',
  }[s]
}

// Selection (for bulk assign)
const selected = ref<Set<string>>(new Set())
function toggleRow(id: string) {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selected.value = next
}
function toggleAll() {
  if (selected.value.size === filteredCustomers.value.length) {
    selected.value = new Set()
  } else {
    selected.value = new Set(filteredCustomers.value.map((c) => c.id))
  }
}

// ── Create / Edit dialog ──────────────────────────────────────────────────
const showForm = ref(false)
const editing = ref<Customer | null>(null)
const isEdit = computed(() => !!editing.value)
const formTab = ref<'personal' | 'contact' | 'corporate' | 'attribution'>('personal')

const defaultForm = (): Omit<Customer, 'id' | 'customerCode' | 'kycDocs' | 'assignmentHistory'> => ({
  customerType: 'individual',
  titleTh: '',
  titleEn: '',
  firstName: '',
  lastName: '',
  nickname: '',
  firstNameEn: '',
  lastNameEn: '',
  juristicName: '',
  taxId: '',
  idCard: '',
  nationalIdExpiry: null,
  passport: '',
  nationality: 'ไทย',
  religion: '',
  birthDate: '',
  gender: 'male',
  maritalStatus: 'single',
  occupation: '',
  position: '',
  employerName: '',
  monthlyIncome: 0,
  email: '',
  phone: '',
  lineId: '',
  address: '',
  district: '',
  amphoe: '',
  province: '',
  postcode: '',
  mailingSameAsRegistered: true,
  mailing: { address: '', subDistrict: '', district: '', province: '', postcode: '' },
  contactPerson: { name: '', phone: '', email: '', position: '' },
  createdByAgentId: CURRENT_AGENT_ID,
  assignedAgentId: CURRENT_AGENT_ID,
  assignedAgentCode: null,
  assignedAgentName: null,
  registeredAt: '2569-06-05',
  lastContact: null,
  notes: '',
  activePolicyCount: 0,
  totalPolicyCount: 0,
  active: true,
})

const form = reactive(defaultForm())

function openCreate() {
  editing.value = null
  Object.assign(form, defaultForm())
  formTab.value = 'personal'
  showForm.value = true
}

function openEdit(c: Customer) {
  editing.value = c
  // Copy all editable customer fields into the reactive form, dropping the
  // store-managed ones (id, customerCode, kycDocs, assignmentHistory).
  const { id: _id, customerCode: _code, kycDocs: _kd, assignmentHistory: _ah, ...editable } = c
  void _id; void _code; void _kd; void _ah
  Object.assign(form, editable, {
    // Ensure nested objects are deep-copied so editing doesn't mutate the store.
    mailing: { ...c.mailing },
    contactPerson: { ...c.contactPerson },
  })
  formTab.value = 'personal'
  showForm.value = true
}

const personalValid = computed(
  () =>
    form.firstName.trim().length > 0 &&
    form.lastName.trim().length > 0 &&
    /^\d{13}$/.test(form.idCard),
)
const contactValid = computed(
  () =>
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email) &&
    form.phone.trim().length >= 9,
)
const attributionValid = computed(() => !!form.createdByAgentId)
const formValid = computed(() => personalValid.value && contactValid.value && attributionValid.value)

const formSubmitting = ref(false)

async function submitForm() {
  if (!formValid.value) return
  formSubmitting.value = true
  try {
    if (isEdit.value && editing.value) {
      await customerStore.updateCustomer(editing.value.id, form)
    } else {
      await customerStore.createCustomer({
        ...form,
        kycDocs: [],
        assignmentHistory: [
          {
            id: 'h' + Date.now(),
            fromAgentId: null,
            toAgentId: form.assignedAgentId,
            reason: 'สร้างลูกค้าใหม่',
            byUserId: CURRENT_USER_ID,
            at: '2569-06-05',
          },
        ],
      })
    }
    showForm.value = false
  } finally {
    formSubmitting.value = false
  }
}

// ── Assign dialog ─────────────────────────────────────────────────────────
const assignTarget = ref<Customer | null>(null)
const assignBulk = ref(false)
const assignNewAgentId = ref<string | null>(null)
const assignReason = ref('')

function openAssign(c: Customer) {
  assignTarget.value = c
  assignBulk.value = false
  assignNewAgentId.value = c.assignedAgentId
  assignReason.value = ''
}

function openBulkAssign() {
  assignTarget.value = null
  assignBulk.value = true
  assignNewAgentId.value = null
  assignReason.value = ''
}

async function submitAssign() {
  if (assignBulk.value) {
    if (!assignReason.value.trim()) return
    await Promise.all(
      Array.from(selected.value).map((id) =>
        customerStore.assignCustomer(id, assignNewAgentId.value, assignReason.value, CURRENT_USER_ID),
      ),
    )
    selected.value = new Set()
    assignBulk.value = false
  } else if (assignTarget.value) {
    if (!assignReason.value.trim()) return
    await customerStore.assignCustomer(
      assignTarget.value.id,
      assignNewAgentId.value,
      assignReason.value,
      CURRENT_USER_ID,
    )
    assignTarget.value = null
  }
}

const closeAssign = () => {
  assignTarget.value = null
  assignBulk.value = false
}

// ── Merge dialog ──────────────────────────────────────────────────────────
const showMerge = ref(false)
const mergePrimaryId = ref<string>('')
const mergeDuplicateId = ref<string>('')
function openMerge() {
  mergePrimaryId.value = ''
  mergeDuplicateId.value = ''
  showMerge.value = true
}
async function submitMerge() {
  if (!mergePrimaryId.value || !mergeDuplicateId.value || mergePrimaryId.value === mergeDuplicateId.value) return
  await customerStore.mergeCustomers(mergePrimaryId.value, mergeDuplicateId.value)
  showMerge.value = false
}

// ── Activate / deactivate ────────────────────────────────────────────────
const toggleTarget = ref<Customer | null>(null)
async function confirmToggle() {
  if (!toggleTarget.value) return
  const target = toggleTarget.value
  toggleTarget.value = null
  await customerStore.setActive(target.id, !target.active)
}

// ── Detail drawer ─────────────────────────────────────────────────────────
const detailId = ref<string | null>(null)
const detailTab = ref<'profile' | 'policies' | 'kyc' | 'assignmentHistory' | 'notes'>('profile')
const detail = computed(() => (detailId.value ? customerStore.getCustomer(detailId.value) : null))

function openDetail(c: Customer) {
  detailId.value = c.id
  detailTab.value = 'profile'
}
function closeDetail() {
  detailId.value = null
}

// KYC upload (simulated)
const kycFileInput = ref<HTMLInputElement | null>(null)
const kycDocTypeSelect = ref<KycDocType>('idCard')
function triggerKycUpload() {
  kycFileInput.value?.click()
}
async function onKycFileChange(e: Event) {
  if (!detail.value) return
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  try {
    await customerStore.uploadKycDoc(detail.value.id, {
      type: kycDocTypeSelect.value,
      fileName: file.name,
      uploadedByAgentId: CURRENT_AGENT_ID,
      verified: false,
    })
  } finally {
    input.value = ''
  }
}

function agentNameById(id: string | null) {
  if (!id) return null
  const a = agentStore.getAgent(id)
  return a ? `${a.firstName} ${a.lastName}` : null
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.customers.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.customers.description') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button
          type="button"
          @click="openMerge"
          class="px-3 py-2.5 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition flex items-center gap-2"
        >
          <i class="pi pi-clone" />
          <span class="hidden sm:inline">{{ t('customers.merge.title') }}</span>
        </button>
        <button
          type="button"
          @click="openCreate"
          class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2"
        >
          <i class="pi pi-user-plus" />
          <span class="hidden sm:inline">{{ t('customers.list.addNew') }}</span>
        </button>
      </div>
    </header>

    <CustomersSubnav />

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <button
        type="button"
        @click="view = 'all'"
        :class="['card p-4 text-left transition', view === 'all' && 'ring-2 ring-brand-500 border-brand-200']"
      >
        <div class="text-xs text-slate-500">{{ t('customers.list.total') }}</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ stats.total }}</div>
      </button>
      <button
        type="button"
        @click="view = 'mine'"
        :class="['card p-4 text-left transition', view === 'mine' && 'ring-2 ring-brand-500 border-brand-200']"
      >
        <div class="text-xs text-slate-500">{{ t('customers.list.assignedToMe') }}</div>
        <div class="text-2xl font-semibold text-brand-600 mt-1">{{ stats.mine }}</div>
      </button>
      <button
        type="button"
        @click="view = 'unassigned'"
        :class="['card p-4 text-left transition', view === 'unassigned' && 'ring-2 ring-brand-500 border-brand-200']"
      >
        <div class="text-xs text-slate-500 flex items-center gap-1.5">
          <i class="pi pi-exclamation-triangle text-amber-500" />
          {{ t('customers.list.unassigned') }}
        </div>
        <div class="text-2xl font-semibold text-amber-600 mt-1">{{ stats.unassigned }}</div>
      </button>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('customers.list.activePolicies') }}</div>
        <div class="text-2xl font-semibold text-emerald-600 mt-1">{{ stats.withPolicies }}</div>
      </div>
    </div>

    <!-- Filters + bulk action bar -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-[240px]">
        <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
        <input
          v-model="search"
          type="search"
          :placeholder="t('customers.list.searchPlaceholder')"
          class="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-400"
        />
      </div>
      <select
        v-model="kycFilter"
        class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400"
      >
        <option value="all">{{ t('customers.cols.kyc') }}: {{ t('common.all') }}</option>
        <option value="complete">{{ t('customers.kycStatus.complete') }}</option>
        <option value="partial">{{ t('customers.kycStatus.partial') }}</option>
        <option value="missing">{{ t('customers.kycStatus.missing') }}</option>
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
      <div v-if="selected.size > 0" class="flex items-center gap-2 ml-auto bg-violet-50 text-violet-700 px-3 py-1.5 rounded-lg text-sm font-medium">
        <span>{{ t('customers.assign.selected') }}: {{ selected.size }}</span>
        <button
          @click="openBulkAssign"
          class="px-2 py-1 bg-violet-600 text-white rounded text-xs hover:bg-violet-700 transition"
        >
          {{ t('customers.assign.bulkAssign') }}
        </button>
        <button
          @click="selected = new Set()"
          class="text-violet-400 hover:text-violet-700"
        >
          <i class="pi pi-times text-xs" />
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="w-10 px-3 py-3">
                <input
                  type="checkbox"
                  :checked="filteredCustomers.length > 0 && selected.size === filteredCustomers.length"
                  :indeterminate.prop="selected.size > 0 && selected.size < filteredCustomers.length"
                  @change="toggleAll"
                  class="w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                />
              </th>
              <th class="text-left px-4 py-3 font-medium">{{ t('customers.cols.customer') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('customers.cols.contact') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('customers.cols.assignedAgent') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('customers.cols.policies') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('customers.cols.kyc') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('customers.cols.status') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('customers.cols.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in filteredCustomers" :key="c.id" class="hover:bg-slate-50/50">
              <td class="px-3 py-3">
                <input
                  type="checkbox"
                  :checked="selected.has(c.id)"
                  @change="toggleRow(c.id)"
                  class="w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                  @click.stop
                />
              </td>
              <td class="px-4 py-3 cursor-pointer" @click="openDetail(c)">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs font-medium shrink-0">
                    {{ c.firstName.charAt(0) }}{{ c.lastName.charAt(0) }}
                  </div>
                  <div class="min-w-0">
                    <div class="font-medium text-slate-900 truncate">
                      {{ c.firstName }} {{ c.lastName }}
                      <span v-if="c.nickname" class="text-slate-400 font-normal">({{ c.nickname }})</span>
                    </div>
                    <div class="text-xs text-slate-500 truncate">
                      <span class="font-mono">{{ c.customerCode }}</span>
                      <span class="mx-1">·</span>
                      <span>{{ customerStore.thaiAge(c.birthDate) }} ปี</span>
                      <span class="mx-1">·</span>
                      <span>{{ c.occupation }}</span>
                    </div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-600">
                <div class="flex items-center gap-1.5">
                  <i class="pi pi-phone text-slate-400" />
                  <span>{{ c.phone }}</span>
                </div>
                <div class="flex items-center gap-1.5 mt-0.5">
                  <i class="pi pi-envelope text-slate-400" />
                  <span class="truncate">{{ c.email }}</span>
                </div>
              </td>
              <td class="px-4 py-3 text-xs">
                <template v-if="c.assignedAgentId">
                  <div class="text-slate-900">{{ agentNameById(c.assignedAgentId) }}</div>
                  <div class="text-slate-400 font-mono">{{ agentStore.getAgent(c.assignedAgentId)?.agentCode }}</div>
                </template>
                <span v-else class="inline-flex items-center gap-1 text-amber-600">
                  <i class="pi pi-exclamation-triangle text-[10px]" />
                  {{ t('customers.list.unassigned') }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <div class="text-sm font-medium text-slate-900">{{ c.activePolicyCount }}</div>
                <div class="text-xs text-slate-400">/ {{ c.totalPolicyCount }} ทั้งหมด</div>
              </td>
              <td class="px-4 py-3">
                <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium', kycBadgeClass(customerStore.kycStatus(c))]">
                  <i :class="{
                    complete: 'pi pi-check-circle',
                    partial: 'pi pi-clock',
                    missing: 'pi pi-minus-circle',
                  }[customerStore.kycStatus(c)]" class="text-[10px]" />
                  {{ t(`customers.kycStatus.${customerStore.kycStatus(c)}`) }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span
                  :class="[
                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium',
                    c.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500',
                  ]"
                >
                  <span :class="['w-1.5 h-1.5 rounded-full', c.active ? 'bg-emerald-500' : 'bg-slate-400']" />
                  {{ c.active ? t('common.active') : t('common.inactive') }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    @click.stop="openDetail(c)"
                    class="px-2 py-1 text-xs text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded transition"
                    :title="t('common.view')"
                  >
                    <i class="pi pi-eye" />
                  </button>
                  <button
                    type="button"
                    @click.stop="openEdit(c)"
                    class="px-2 py-1 text-xs text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded transition"
                    :title="t('common.edit')"
                  >
                    <i class="pi pi-pencil" />
                  </button>
                  <button
                    type="button"
                    @click.stop="openAssign(c)"
                    class="px-2 py-1 text-xs text-violet-600 hover:bg-violet-50 rounded transition"
                    :title="c.assignedAgentId ? t('customers.assign.reassignTitle') : t('customers.assign.assignTitle')"
                  >
                    <i class="pi pi-user-edit" />
                  </button>
                  <button
                    type="button"
                    @click.stop="toggleTarget = c"
                    :class="['px-2 py-1 text-xs rounded transition', c.active ? 'text-rose-600 hover:bg-rose-50' : 'text-emerald-600 hover:bg-emerald-50']"
                    :title="c.active ? t('customers.confirm.deactivateTitle') : t('customers.confirm.activateTitle')"
                  >
                    <i :class="c.active ? 'pi pi-ban' : 'pi pi-check-circle'" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredCustomers.length">
              <td colspan="8" class="px-4 py-10 text-center text-slate-400 text-sm">{{ t('common.noData') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create / edit dialog -->
    <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[92vh] flex flex-col">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <h3 class="font-semibold text-slate-900">
            {{ isEdit ? t('customers.dialog.editTitle') : t('customers.dialog.createTitle') }}
          </h3>
          <button @click="showForm = false" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>

        <div class="border-b border-slate-100 px-5 flex items-center gap-1 shrink-0">
          <button
            v-for="tk in (form.customerType === 'corporate' ? ['personal', 'contact', 'corporate', 'attribution'] as const : ['personal', 'contact', 'attribution'] as const)"
            :key="tk"
            type="button"
            @click="formTab = tk"
            :class="[
              'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition flex items-center gap-2',
              formTab === tk ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
            ]"
          >
            {{ t(`customers.dialog.tabs.${tk}`) }}
            <i
              v-if="(tk === 'personal' && !personalValid) || (tk === 'contact' && !contactValid) || (tk === 'attribution' && !attributionValid)"
              class="pi pi-exclamation-circle text-rose-400 text-xs"
            />
          </button>
        </div>

        <div class="px-5 py-5 overflow-y-auto flex-1">
          <!-- Personal tab -->
          <section v-if="formTab === 'personal'" class="space-y-4">
            <!-- Customer type toggle -->
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">ประเภทลูกค้า</label>
              <div class="inline-flex border border-slate-200 bg-white rounded-lg p-0.5">
                <button
                  type="button"
                  @click="form.customerType = 'individual'"
                  :class="[
                    'px-3 py-1.5 text-xs font-medium rounded transition',
                    form.customerType === 'individual' ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-900',
                  ]"
                >
                  บุคคลธรรมดา
                </button>
                <button
                  type="button"
                  @click="form.customerType = 'corporate'"
                  :class="[
                    'px-3 py-1.5 text-xs font-medium rounded transition',
                    form.customerType === 'corporate' ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-900',
                  ]"
                >
                  นิติบุคคล / บริษัท
                </button>
              </div>
            </div>

            <!-- Names (Thai) -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">คำนำหน้า</label>
                <input v-model="form.titleTh" type="text" placeholder="นาย / นาง / น.ส." class="w-full px-3 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.firstName') }} <span class="text-rose-500">*</span></label>
                <input v-model="form.firstName" type="text" required class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.lastName') }} <span class="text-rose-500">*</span></label>
                <input v-model="form.lastName" type="text" required class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.nickname') }}</label>
                <input v-model="form.nickname" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>

            <!-- Names (English) -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Title (EN)</label>
                <input v-model="form.titleEn" type="text" placeholder="Mr. / Mrs. / Ms." class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">First name (EN)</label>
                <input v-model="form.firstNameEn" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Last name (EN)</label>
                <input v-model="form.lastNameEn" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>

            <!-- IDs -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.idCard') }} <span class="text-rose-500">*</span></label>
                <input v-model="form.idCard" type="text" required maxlength="13" inputmode="numeric" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">บัตรประชาชนหมดอายุ</label>
                <input v-model="form.nationalIdExpiry" type="text" placeholder="25xx-mm-dd" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">หนังสือเดินทาง (Passport)</label>
                <input v-model="form.passport" type="text" placeholder="ถ้ามี" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>

            <!-- Demographic -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.birthDate') }}</label>
                <input v-model="form.birthDate" type="text" placeholder="25xx-mm-dd" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.gender') }}</label>
                <select v-model="form.gender" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100">
                  <option value="male">{{ t('customers.gender.male') }}</option>
                  <option value="female">{{ t('customers.gender.female') }}</option>
                  <option value="other">{{ t('customers.gender.other') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.maritalStatus') }}</label>
                <select v-model="form.maritalStatus" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100">
                  <option value="single">{{ t('customers.marital.single') }}</option>
                  <option value="married">{{ t('customers.marital.married') }}</option>
                  <option value="divorced">{{ t('customers.marital.divorced') }}</option>
                  <option value="widowed">{{ t('customers.marital.widowed') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">สัญชาติ</label>
                <input v-model="form.nationality" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>

            <!-- Religion / occupation -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">ศาสนา</label>
                <input v-model="form.religion" type="text" placeholder="พุทธ / คริสต์ / อิสลาม ..." class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.occupation') }}</label>
                <input v-model="form.occupation" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.monthlyIncome') }}</label>
                <input v-model.number="form.monthlyIncome" type="number" min="0" step="1000" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>

            <!-- Employer / position -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">ตำแหน่ง</label>
                <input v-model="form.position" type="text" placeholder="เช่น ผู้จัดการฝ่ายขาย" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อบริษัท / ผู้ว่าจ้าง</label>
                <input v-model="form.employerName" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>
          </section>

          <!-- Contact tab -->
          <section v-if="formTab === 'contact'" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.email') }} <span class="text-rose-500">*</span></label>
                <input v-model="form.email" type="email" required class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.phone') }} <span class="text-rose-500">*</span></label>
                <input v-model="form.phone" type="tel" required placeholder="08x-xxx-xxxx" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.lineId') }}</label>
              <input v-model="form.lineId" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.address') }}</label>
              <textarea v-model="form.address" rows="2" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none" />
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('customers.fields.district') }}</label>
                <input v-model="form.district" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('customers.fields.amphoe') }}</label>
                <input v-model="form.amphoe" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('customers.fields.province') }}</label>
                <input v-model="form.province" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('customers.fields.postcode') }}</label>
                <input v-model="form.postcode" type="text" maxlength="5" inputmode="numeric" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>

            <!-- Mailing address -->
            <div class="pt-3 border-t border-slate-100 space-y-3">
              <div class="flex items-center justify-between">
                <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-500">ที่อยู่จัดส่งกรมธรรม์</h5>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                  <input
                    v-model="form.mailingSameAsRegistered"
                    type="checkbox"
                    class="w-3.5 h-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                  />
                  <span class="text-xs text-slate-600">ใช้ที่อยู่เดียวกับด้านบน</span>
                </label>
              </div>
              <template v-if="!form.mailingSameAsRegistered">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">
                    ที่อยู่ส่งไปรษณีย์
                    <span class="font-normal text-slate-400">(บ้านเลขที่ / ซอย / ถนน)</span>
                  </label>
                  <textarea v-model="form.mailing.address" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none" />
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">แขวง / ตำบล</label>
                    <input v-model="form.mailing.subDistrict" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">เขต / อำเภอ</label>
                    <input v-model="form.mailing.district" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">จังหวัด</label>
                    <input v-model="form.mailing.province" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">รหัสไปรษณีย์</label>
                    <input v-model="form.mailing.postcode" type="text" maxlength="5" inputmode="numeric" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                  </div>
                </div>
              </template>
            </div>
          </section>

          <!-- Corporate tab (only visible when customerType === 'corporate') -->
          <section v-if="formTab === 'corporate'" class="space-y-4">
            <div class="bg-blue-50 border border-blue-200 text-blue-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              ข้อมูลนิติบุคคล + ผู้ติดต่อ — ใช้สำหรับลูกค้าประเภทบริษัท / ห้างหุ้นส่วน
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อนิติบุคคล</label>
                <input v-model="form.juristicName" type="text" placeholder="เช่น บจก. พัฒนชัย เคมิคอล" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">เลขประจำตัวผู้เสียภาษี</label>
                <input v-model="form.taxId" type="text" maxlength="13" inputmode="numeric" placeholder="13 หลัก" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>

            <div class="pt-3 border-t border-slate-100 space-y-3">
              <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-500">ผู้ติดต่อรับกรมธรรม์</h5>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อผู้ติดต่อ</label>
                  <input v-model="form.contactPerson.name" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-1.5">ตำแหน่ง</label>
                  <input v-model="form.contactPerson.position" type="text" placeholder="เช่น HR / Admin" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-1.5">เบอร์โทรผู้ติดต่อ</label>
                  <input v-model="form.contactPerson.phone" type="tel" placeholder="08x-xxx-xxxx" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-1.5">อีเมลผู้ติดต่อ</label>
                  <input v-model="form.contactPerson.email" type="email" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
              </div>
            </div>
          </section>

          <!-- Attribution tab -->
          <section v-if="formTab === 'attribution'" class="space-y-4">
            <div class="bg-sky-50 border border-sky-200 text-sky-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              <div>
                "สร้างโดยตัวแทน" จะถูกล็อกหลังจากบันทึก แต่ "มอบหมายให้ตัวแทน" สามารถเปลี่ยนได้ภายหลัง
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.createdByAgent') }} <span class="text-rose-500">*</span></label>
              <select v-model="form.createdByAgentId" :disabled="isEdit" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 disabled:bg-slate-50 disabled:text-slate-500">
                <option v-for="a in agentStore.agents" :key="a.id" :value="a.id">
                  {{ a.firstName }} {{ a.lastName }} ({{ a.agentCode }})
                </option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.assignedToAgent') }}</label>
              <select v-model="form.assignedAgentId" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100">
                <option :value="null">{{ t('customers.list.unassigned') }}</option>
                <option v-for="a in agentStore.agents" :key="a.id" :value="a.id">
                  {{ a.firstName }} {{ a.lastName }} ({{ a.agentCode }})
                </option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.fields.notes') }}</label>
              <textarea v-model="form.notes" rows="2" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none" />
            </div>
          </section>
        </div>

        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-white rounded-b-xl shrink-0">
          <button @click="showForm = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
            {{ t('common.cancel') }}
          </button>
          <button @click="submitForm" :disabled="!formValid || formSubmitting" class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-50 flex items-center gap-2">
            <i v-if="formSubmitting" class="pi pi-spin pi-spinner" />
            <span>{{ isEdit ? t('common.save') : t('common.create') }}</span>
          </button>
        </footer>
      </div>
    </div>

    <!-- Assign dialog (single + bulk) -->
    <div v-if="assignTarget || assignBulk" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" @click.self="closeAssign">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <h3 class="font-semibold text-slate-900">
            {{ assignBulk ? t('customers.assign.bulkAssign') : (assignTarget?.assignedAgentId ? t('customers.assign.reassignTitle') : t('customers.assign.assignTitle')) }}
          </h3>
          <button @click="closeAssign" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="px-5 py-5 space-y-4">
          <div v-if="assignBulk" class="bg-violet-50 border border-violet-200 text-violet-800 text-sm rounded-lg px-3 py-2">
            จะมอบหมายลูกค้า <strong>{{ selected.size }} คน</strong> พร้อมกัน
          </div>
          <div v-else-if="assignTarget" class="card p-3 bg-slate-50 border-slate-100">
            <div class="text-xs text-slate-500">ลูกค้า</div>
            <div class="font-medium text-slate-900">{{ assignTarget.firstName }} {{ assignTarget.lastName }}</div>
            <div class="text-xs text-slate-400 font-mono">{{ assignTarget.customerCode }}</div>
          </div>

          <div v-if="!assignBulk && assignTarget">
            <div class="text-xs text-slate-500 mb-1">{{ t('customers.assign.currentAgent') }}</div>
            <div class="text-sm text-slate-700">
              <template v-if="assignTarget.assignedAgentId">
                {{ agentNameById(assignTarget.assignedAgentId) }} ({{ agentStore.getAgent(assignTarget.assignedAgentId)?.agentCode }})
              </template>
              <span v-else class="text-slate-400 italic">{{ t('customers.list.unassigned') }}</span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.assign.newAgent') }}</label>
            <select v-model="assignNewAgentId" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100">
              <option :value="null">{{ t('customers.list.unassigned') }} (เข้าพูล)</option>
              <option v-for="a in agentStore.agents.filter(x => x.active)" :key="a.id" :value="a.id">
                {{ a.firstName }} {{ a.lastName }} ({{ a.agentCode }}) — {{ t(`agents.levelShort.${a.level}`) }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('customers.assign.reason') }} <span class="text-rose-500">*</span></label>
            <textarea v-model="assignReason" rows="2" required :placeholder="t('customers.assign.reasonPlaceholder')" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none" />
          </div>

          <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
            <i class="pi pi-info-circle mt-0.5" />
            <span>{{ t('customers.assign.warning') }}</span>
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="closeAssign" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">{{ t('common.cancel') }}</button>
          <button @click="submitAssign" :disabled="!assignReason.trim()" class="px-4 py-2 text-sm rounded-lg bg-violet-600 text-white font-medium hover:bg-violet-700 disabled:opacity-50">
            {{ t('customers.assign.submit') }}
          </button>
        </footer>
      </div>
    </div>

    <!-- Merge dialog -->
    <div v-if="showMerge" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <h3 class="font-semibold text-slate-900">{{ t('customers.merge.title') }}</h3>
          <button @click="showMerge = false" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="px-5 py-5 space-y-4">
          <p class="text-sm text-slate-500">{{ t('customers.merge.subtitle') }}</p>
          <div>
            <label class="block text-sm font-medium text-emerald-700 mb-1.5">
              <i class="pi pi-check-circle text-xs mr-1" />{{ t('customers.merge.primary') }}
            </label>
            <select v-model="mergePrimaryId" class="w-full px-3.5 py-2.5 border border-emerald-300 rounded-lg text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
              <option value="">— เลือก —</option>
              <option v-for="c in customerStore.customers" :key="c.id" :value="c.id">
                {{ c.firstName }} {{ c.lastName }} ({{ c.customerCode }})
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-rose-700 mb-1.5">
              <i class="pi pi-times-circle text-xs mr-1" />{{ t('customers.merge.duplicate') }} (จะถูกลบ)
            </label>
            <select v-model="mergeDuplicateId" class="w-full px-3.5 py-2.5 border border-rose-300 rounded-lg text-sm focus:outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
              <option value="">— เลือก —</option>
              <option v-for="c in customerStore.customers.filter(x => x.id !== mergePrimaryId)" :key="c.id" :value="c.id">
                {{ c.firstName }} {{ c.lastName }} ({{ c.customerCode }})
              </option>
            </select>
          </div>
          <div class="bg-rose-50 border border-rose-200 text-rose-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
            <i class="pi pi-exclamation-triangle mt-0.5" />
            <span>{{ t('customers.merge.warning') }}</span>
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="showMerge = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">{{ t('common.cancel') }}</button>
          <button @click="submitMerge" :disabled="!mergePrimaryId || !mergeDuplicateId || mergePrimaryId === mergeDuplicateId" class="px-4 py-2 text-sm rounded-lg bg-rose-600 text-white font-medium hover:bg-rose-700 disabled:opacity-50">
            {{ t('customers.merge.submit') }}
          </button>
        </footer>
      </div>
    </div>

    <!-- Toggle confirm -->
    <div v-if="toggleTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" @click.self="toggleTarget = null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-5 py-5">
          <div :class="['w-10 h-10 rounded-full flex items-center justify-center mb-3', toggleTarget.active ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600']">
            <i :class="toggleTarget.active ? 'pi pi-exclamation-triangle' : 'pi pi-check'" />
          </div>
          <h3 class="font-semibold text-slate-900">
            {{ toggleTarget.active ? t('customers.confirm.deactivateTitle') : t('customers.confirm.activateTitle') }}
          </h3>
          <p class="text-sm text-slate-500 mt-1.5">
            {{ toggleTarget.active ? t('customers.confirm.deactivateMsg') : t('customers.confirm.activateMsg') }}
          </p>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="toggleTarget = null" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">{{ t('common.cancel') }}</button>
          <button @click="confirmToggle" :class="['px-4 py-2 text-sm rounded-lg text-white font-medium', toggleTarget.active ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700']">
            {{ toggleTarget.active ? t('carriers.confirm.deactivate') : t('carriers.confirm.activate') }}
          </button>
        </footer>
      </div>
    </div>

    <!-- Detail drawer -->
    <div v-if="detail" class="fixed inset-0 z-40 flex" @click.self="closeDetail">
      <div class="flex-1 bg-slate-900/40" @click="closeDetail" />
      <aside class="w-full max-w-2xl bg-white shadow-2xl flex flex-col overflow-hidden">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-sm font-medium">
              {{ detail.firstName.charAt(0) }}{{ detail.lastName.charAt(0) }}
            </div>
            <div>
              <h3 class="font-semibold text-slate-900">{{ detail.firstName }} {{ detail.lastName }}</h3>
              <div class="text-xs text-slate-500 font-mono">{{ detail.customerCode }}</div>
            </div>
          </div>
          <button @click="closeDetail" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>

        <div class="border-b border-slate-100 px-5 flex items-center gap-1 overflow-x-auto shrink-0">
          <button
            v-for="tk in (['profile', 'policies', 'kyc', 'assignmentHistory', 'notes'] as const)"
            :key="tk"
            type="button"
            @click="detailTab = tk"
            :class="[
              'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition whitespace-nowrap',
              detailTab === tk ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
            ]"
          >
            {{ t(`customers.detail.tabs.${tk}`) }}
            <span
              v-if="tk === 'kyc'"
              class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-slate-100 text-slate-500"
            >{{ detail.kycDocs.length }}</span>
            <span
              v-else-if="tk === 'assignmentHistory'"
              class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-slate-100 text-slate-500"
            >{{ detail.assignmentHistory.length }}</span>
          </button>
        </div>

        <div class="px-5 py-5 overflow-y-auto flex-1">
          <!-- Profile -->
          <div v-if="detailTab === 'profile'" class="space-y-5">
            <section>
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">ข้อมูลส่วนตัว</h4>
              <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">ชื่อ-นามสกุล</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.firstName }} {{ detail.lastName }} ({{ detail.nickname || '–' }})</dd>
                <dt class="text-slate-500">{{ t('customers.fields.idCard') }}</dt>
                <dd class="col-span-2 font-mono text-slate-900">{{ detail.idCard }}</dd>
                <dt class="text-slate-500">{{ t('customers.fields.birthDate') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.birthDate }} ({{ customerStore.thaiAge(detail.birthDate) }} ปี)</dd>
                <dt class="text-slate-500">{{ t('customers.fields.gender') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ t(`customers.gender.${detail.gender}`) }} · {{ t(`customers.marital.${detail.maritalStatus}`) }}</dd>
                <dt class="text-slate-500">{{ t('customers.fields.occupation') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.occupation || '–' }}</dd>
                <dt class="text-slate-500">{{ t('customers.fields.monthlyIncome') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.monthlyIncome.toLocaleString('th-TH') }} บาท</dd>
              </dl>
            </section>
            <section class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">ติดต่อ</h4>
              <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">{{ t('customers.fields.phone') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.phone }}</dd>
                <dt class="text-slate-500">{{ t('customers.fields.email') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.email }}</dd>
                <dt class="text-slate-500">{{ t('customers.fields.lineId') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.lineId || '–' }}</dd>
                <dt class="text-slate-500">{{ t('customers.fields.address') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.address }} {{ detail.district }} {{ detail.amphoe }} {{ detail.province }} {{ detail.postcode }}</dd>
              </dl>
            </section>
            <section class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">ตัวแทน</h4>
              <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">{{ t('customers.fields.createdByAgent') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ agentNameById(detail.createdByAgentId) || '–' }}</dd>
                <dt class="text-slate-500">{{ t('customers.fields.assignedToAgent') }}</dt>
                <dd class="col-span-2">
                  <span v-if="detail.assignedAgentId" class="text-slate-900">{{ agentNameById(detail.assignedAgentId) }}</span>
                  <span v-else class="text-amber-600 font-medium">{{ t('customers.list.unassigned') }}</span>
                </dd>
                <dt class="text-slate-500">{{ t('customers.fields.registeredAt') }}</dt>
                <dd class="col-span-2 text-slate-900 font-mono">{{ detail.registeredAt }}</dd>
                <dt class="text-slate-500">{{ t('customers.fields.lastContact') }}</dt>
                <dd class="col-span-2 text-slate-900 font-mono">{{ detail.lastContact ?? '–' }}</dd>
              </dl>
            </section>
          </div>

          <!-- Policies (placeholder for Section 8) -->
          <div v-if="detailTab === 'policies'">
            <div v-if="!policyStore.policiesForCustomer(detail.id).length" class="text-center py-12 text-slate-400">
              <i class="pi pi-file text-3xl block mb-2" />
              <p class="text-sm">{{ t('customers.detail.noPolicies') }}</p>
            </div>
            <div v-else class="space-y-3">
              <div class="grid grid-cols-2 gap-3 mb-3">
                <div class="card p-3">
                  <div class="text-xs text-slate-500">ใช้งาน</div>
                  <div class="text-lg font-semibold text-emerald-600 mt-0.5">
                    {{ policyStore.policiesForCustomer(detail.id).filter(p => p.status === 'active').length }}
                  </div>
                </div>
                <div class="card p-3">
                  <div class="text-xs text-slate-500">ทั้งหมด</div>
                  <div class="text-lg font-semibold text-slate-900 mt-0.5">
                    {{ policyStore.policiesForCustomer(detail.id).length }}
                  </div>
                </div>
              </div>
              <RouterLink
                v-for="p in policyStore.policiesForCustomer(detail.id)"
                :key="p.id"
                to="/policies"
                class="card p-3 flex items-start gap-3 hover:border-brand-300 hover:bg-brand-50/30 transition"
              >
                <div class="w-10 h-10 rounded-lg bg-brand-100 text-brand-700 flex items-center justify-center shrink-0">
                  <i class="pi pi-file" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-mono text-slate-900 truncate">{{ p.policyNo ?? p.applicationNo ?? p.quoteNo }}</div>
                  <div class="text-xs text-slate-500 truncate mt-0.5">{{ productNameMap[p.productId] }}</div>
                  <div class="text-[11px] text-slate-400 mt-1">
                    ทุน {{ p.coverage.toLocaleString('th-TH') }} · เบี้ย {{ p.annualPremium.toLocaleString('th-TH') }}/ปี
                  </div>
                </div>
                <span :class="['inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-medium shrink-0', policyStatusBadgeClass(p.status)]">
                  {{ t(`policies.status.${p.status}`) }}
                </span>
              </RouterLink>
            </div>
          </div>

          <!-- KYC -->
          <div v-if="detailTab === 'kyc'" class="space-y-3">
            <div class="flex items-center gap-2 mb-2">
              <select v-model="kycDocTypeSelect" class="flex-1 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500">
                <option v-for="dt in (['idCard', 'houseReg', 'bankBook', 'income', 'medical', 'photo', 'signature', 'other'] as KycDocType[])" :key="dt" :value="dt">
                  {{ t(`customers.kyc.docTypes.${dt}`) }}
                </option>
              </select>
              <input ref="kycFileInput" type="file" class="hidden" @change="onKycFileChange" />
              <button @click="triggerKycUpload" class="px-3 py-2 bg-brand-600 text-white rounded-lg text-sm font-medium hover:bg-brand-700 transition flex items-center gap-1.5">
                <i class="pi pi-upload" />
                <span class="hidden sm:inline">{{ t('customers.detail.uploadDocument') }}</span>
              </button>
            </div>

            <div v-if="!detail.kycDocs.length" class="text-center py-10 text-slate-400">
              <i class="pi pi-folder-open text-3xl block mb-2" />
              <p class="text-sm">ยังไม่มีเอกสาร KYC</p>
            </div>
            <div v-else class="space-y-2">
              <div v-for="d in detail.kycDocs" :key="d.id" class="border border-slate-200 rounded-lg p-3 flex items-center gap-3 hover:bg-slate-50/50">
                <div class="w-10 h-10 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center shrink-0">
                  <i class="pi pi-file" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium text-slate-900 truncate">{{ t(`customers.kyc.docTypes.${d.type}`) }}</div>
                  <div class="text-xs text-slate-500 truncate">{{ d.fileName }}</div>
                  <div class="text-[10px] text-slate-400 mt-0.5">
                    {{ t('customers.kyc.uploadedAt') }}: {{ d.uploadedAt }} · {{ agentNameById(d.uploadedByAgentId) || '—' }}
                  </div>
                </div>
                <span
                  :class="[
                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium shrink-0',
                    d.verified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700',
                  ]"
                >
                  <i :class="d.verified ? 'pi pi-check' : 'pi pi-clock'" class="text-[10px]" />
                  {{ d.verified ? t('customers.kyc.verified') : t('customers.kyc.pending') }}
                </span>
                <div class="flex items-center gap-1">
                  <button v-if="!d.verified" @click="customerStore.verifyKycDoc(detail.id, d.id)" class="px-2 py-1 text-xs text-emerald-600 hover:bg-emerald-50 rounded transition" :title="t('customers.kyc.verify')">
                    <i class="pi pi-check-circle" />
                  </button>
                  <button @click="customerStore.removeKycDoc(detail.id, d.id)" class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded transition" :title="t('customers.kyc.remove')">
                    <i class="pi pi-trash" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Assignment history -->
          <div v-if="detailTab === 'assignmentHistory'">
            <div v-if="!detail.assignmentHistory.length" class="text-center py-12 text-slate-400">
              <i class="pi pi-clock text-3xl block mb-2" />
              <p class="text-sm">ยังไม่มีประวัติ</p>
            </div>
            <ol v-else class="relative border-l-2 border-slate-200 ml-3 space-y-5">
              <li v-for="(h, i) in [...detail.assignmentHistory].reverse()" :key="h.id" class="ml-6">
                <span class="absolute -left-[9px] w-4 h-4 rounded-full bg-brand-500 border-4 border-white" />
                <div class="card p-3">
                  <div class="flex items-center justify-between gap-2">
                    <div class="text-xs text-slate-500 font-mono">{{ h.at }}</div>
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">#{{ detail.assignmentHistory.length - i }}</span>
                  </div>
                  <div class="mt-2 flex items-center gap-2 text-sm">
                    <span class="text-slate-500">{{ t('customers.history.from') }}</span>
                    <span class="text-slate-900 font-medium">{{ agentNameById(h.fromAgentId) ?? '(ไม่มี)' }}</span>
                    <i class="pi pi-arrow-right text-slate-300 text-xs" />
                    <span class="text-slate-500">{{ t('customers.history.to') }}</span>
                    <span :class="['font-medium', h.toAgentId ? 'text-slate-900' : 'text-amber-600']">
                      {{ agentNameById(h.toAgentId) ?? t('customers.list.unassigned') }}
                    </span>
                  </div>
                  <div class="text-xs text-slate-600 mt-2">
                    <span class="text-slate-400">{{ t('customers.history.reason') }}:</span> {{ h.reason }}
                  </div>
                </div>
              </li>
            </ol>
          </div>

          <!-- Notes -->
          <div v-if="detailTab === 'notes'">
            <textarea
              :value="detail.notes"
              @input="customerStore.updateCustomer(detail.id, { notes: ($event.target as HTMLTextAreaElement).value })"
              rows="8"
              placeholder="บันทึกข้อความเกี่ยวกับลูกค้า..."
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
            />
            <p class="text-xs text-slate-400 mt-2">บันทึกจะถูกบันทึกอัตโนมัติ</p>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>
