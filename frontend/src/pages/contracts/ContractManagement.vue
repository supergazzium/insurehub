<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface CarrierRef {
  id: string
  code: string
  name: string
}
interface ProductRef {
  id: string
  code: string
  name: string
  carrierId: string
}

interface ScheduleRow {
  productId: string
  firstYearRate: number
  renewalRate: number
}

interface Contract {
  id: string
  contractNo: string
  carrierId: string
  effectiveFrom: string
  effectiveTo: string | null
  schedule: ScheduleRow[]
  notes: string
  active: boolean
}

// ── Refs (mirror Sections 3 & 4) ──────────────────────────────────────────
const carriers: CarrierRef[] = [
  { id: 'c1', code: 'AIA', name: 'บริษัท เอไอเอ จำกัด' },
  { id: 'c2', code: 'TLI', name: 'บริษัท ไทยประกันชีวิต จำกัด (มหาชน)' },
  { id: 'c3', code: 'MTI', name: 'บริษัท เมืองไทยประกันชีวิต จำกัด (มหาชน)' },
  { id: 'c4', code: 'BLA', name: 'บริษัท กรุงเทพประกันชีวิต จำกัด (มหาชน)' },
  { id: 'c5', code: 'VIB', name: 'บริษัท วิริยะประกันภัย จำกัด (มหาชน)' },
  { id: 'c6', code: 'DHA', name: 'บริษัท ทิพยประกันภัย จำกัด (มหาชน)' },
  { id: 'c8', code: 'ALL', name: 'บริษัท อลิอันซ์ อยุธยา ประกันชีวิต จำกัด (มหาชน)' },
]
const allProducts: ProductRef[] = [
  { id: 'p1', code: 'AIA-WL100', name: 'เอไอเอ ตลอดชีพ 100', carrierId: 'c1' },
  { id: 'p2', code: 'AIA-EN20', name: 'เอไอเอ สะสมทรัพย์ 20/10', carrierId: 'c1' },
  { id: 'p3', code: 'AIA-HEALTH+', name: 'เอไอเอ เฮลธ์ พลัส', carrierId: 'c1' },
  { id: 'p4', code: 'TLI-RET65', name: 'บำนาญ มั่นคง 65', carrierId: 'c2' },
  { id: 'p5', code: 'TLI-CI+', name: 'ไทยประกันชีวิต โรคร้ายแรง พรีเมียม', carrierId: 'c2' },
  { id: 'p6', code: 'MTI-UL', name: 'เมืองไทย ยูนิตลิงก์', carrierId: 'c3' },
  { id: 'p7', code: 'BLA-PA', name: 'กรุงเทพ PA สบายใจ', carrierId: 'c4' },
  { id: 'p8', code: 'VIB-MOTOR1', name: 'วิริยะ ประกันรถยนต์ ชั้น 1', carrierId: 'c5' },
  { id: 'p9', code: 'DHA-HOME', name: 'ทิพย โฮม เซฟ', carrierId: 'c6' },
  { id: 'p10', code: 'DHA-TRAVEL', name: 'ทิพย ทราเวล พลัส', carrierId: 'c6' },
  { id: 'p11', code: 'ALL-TERM10', name: 'อลิอันซ์ เทอม 10', carrierId: 'c8' },
]
function carrierById(id: string) {
  return carriers.find((c) => c.id === id)
}
function productById(id: string) {
  return allProducts.find((p) => p.id === id)
}
function productsForCarrier(carrierId: string) {
  return allProducts.filter((p) => p.carrierId === carrierId)
}

// ── Seed contracts ────────────────────────────────────────────────────────
const contracts = ref<Contract[]>([
  {
    id: 'k1', contractNo: 'CT-AIA-2566-001', carrierId: 'c1',
    effectiveFrom: '2566-01-15', effectiveTo: null,
    schedule: [
      { productId: 'p1', firstYearRate: 55, renewalRate: 8 },
      { productId: 'p2', firstYearRate: 35, renewalRate: 5 },
      { productId: 'p3', firstYearRate: 20, renewalRate: 12 },
    ],
    notes: 'สัญญาแต่งตั้งเป็นตัวแทนทั่วประเทศ', active: true,
  },
  {
    id: 'k2', contractNo: 'CT-TLI-2566-014', carrierId: 'c2',
    effectiveFrom: '2566-03-01', effectiveTo: '2569-02-28',
    schedule: [
      { productId: 'p4', firstYearRate: 40, renewalRate: 6 },
      { productId: 'p5', firstYearRate: 45, renewalRate: 7 },
    ],
    notes: '', active: true,
  },
  {
    id: 'k3', contractNo: 'CT-MTI-2566-008', carrierId: 'c3',
    effectiveFrom: '2566-05-20', effectiveTo: null,
    schedule: [
      { productId: 'p6', firstYearRate: 30, renewalRate: 4 },
    ],
    notes: 'สัญญายูนิตลิงก์เฉพาะ', active: true,
  },
  {
    id: 'k4', contractNo: 'CT-BLA-2567-003', carrierId: 'c4',
    effectiveFrom: '2567-01-10', effectiveTo: null,
    schedule: [
      { productId: 'p7', firstYearRate: 25, renewalRate: 10 },
    ],
    notes: '', active: true,
  },
  {
    id: 'k5', contractNo: 'CT-VIB-2566-022', carrierId: 'c5',
    effectiveFrom: '2566-08-01', effectiveTo: null,
    schedule: [
      { productId: 'p8', firstYearRate: 15, renewalRate: 12 },
    ],
    notes: 'ประกันวินาศภัยรถยนต์', active: true,
  },
  {
    id: 'k6', contractNo: 'CT-DHA-2567-011', carrierId: 'c6',
    effectiveFrom: '2567-02-15', effectiveTo: null,
    schedule: [
      { productId: 'p9', firstYearRate: 18, renewalRate: 10 },
      { productId: 'p10', firstYearRate: 22, renewalRate: 22 },
    ],
    notes: '', active: true,
  },
  {
    id: 'k7', contractNo: 'CT-ALL-2565-005', carrierId: 'c8',
    effectiveFrom: '2565-06-01', effectiveTo: '2568-05-31',
    schedule: [
      { productId: 'p11', firstYearRate: 50, renewalRate: 7 },
    ],
    notes: 'สัญญาเดิม เตรียมต่ออายุ', active: false,
  },
])

// ── Filters ───────────────────────────────────────────────────────────────
const search = ref('')
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')

const filtered = computed(() =>
  contracts.value.filter((k) => {
    if (statusFilter.value === 'active' && !k.active) return false
    if (statusFilter.value === 'inactive' && k.active) return false
    if (search.value) {
      const q = search.value.toLowerCase()
      const carrier = carrierById(k.carrierId)
      const hay = `${k.contractNo} ${carrier?.name ?? ''} ${carrier?.code ?? ''}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  }),
)

const stats = computed(() => {
  const all = contracts.value
  const active = all.filter((k) => k.active)
  const productsCovered = new Set(active.flatMap((k) => k.schedule.map((s) => s.productId))).size
  return {
    total: all.length,
    active: active.length,
    productsCovered,
  }
})

function avgFirstYear(c: Contract) {
  if (!c.schedule.length) return 0
  return c.schedule.reduce((s, r) => s + r.firstYearRate, 0) / c.schedule.length
}
function avgRenewal(c: Contract) {
  if (!c.schedule.length) return 0
  return c.schedule.reduce((s, r) => s + r.renewalRate, 0) / c.schedule.length
}

// ── Create / edit dialog ──────────────────────────────────────────────────
const showForm = ref(false)
const editing = ref<Contract | null>(null)
const isEdit = computed(() => !!editing.value)
const formSubmitting = ref(false)

const defaultForm = (): Contract => ({
  id: '',
  contractNo: 'CT-' + new Date().getFullYear() + '-',
  carrierId: '',
  effectiveFrom: new Date().toISOString().slice(0, 10),
  effectiveTo: null,
  schedule: [],
  notes: '',
  active: true,
})

const form = reactive<Contract>(defaultForm())
const noEndDate = ref(true)
const newProductId = ref('')

function openCreate() {
  editing.value = null
  Object.assign(form, defaultForm())
  noEndDate.value = true
  newProductId.value = ''
  showForm.value = true
}

function openEdit(k: Contract) {
  editing.value = k
  Object.assign(form, { ...k, schedule: k.schedule.map((r) => ({ ...r })) })
  noEndDate.value = !k.effectiveTo
  newProductId.value = ''
  showForm.value = true
}

function closeForm() {
  showForm.value = false
}

const availableProducts = computed(() => {
  if (!form.carrierId) return []
  const used = new Set(form.schedule.map((r) => r.productId))
  return productsForCarrier(form.carrierId).filter((p) => !used.has(p.id))
})

function addProductRow() {
  const pid = newProductId.value
  if (!pid) return
  form.schedule = [...form.schedule, { productId: pid, firstYearRate: 0, renewalRate: 0 }]
  newProductId.value = ''
}

function removeProductRow(productId: string) {
  form.schedule = form.schedule.filter((r) => r.productId !== productId)
}

// When carrier changes, wipe schedule because products won't match
function onCarrierChange() {
  form.schedule = []
  newProductId.value = ''
}

const formValid = computed(
  () =>
    form.contractNo.trim().length >= 3 &&
    !!form.carrierId &&
    !!form.effectiveFrom &&
    form.schedule.length > 0 &&
    form.schedule.every(
      (r) => r.firstYearRate >= 0 && r.firstYearRate <= 100 && r.renewalRate >= 0 && r.renewalRate <= 100,
    ),
)

async function submitForm() {
  if (!formValid.value) return
  formSubmitting.value = true
  await new Promise((r) => setTimeout(r, 400))
  const final: Contract = {
    ...form,
    effectiveTo: noEndDate.value ? null : form.effectiveTo,
  }
  if (isEdit.value && editing.value) {
    const id = editing.value.id
    contracts.value = contracts.value.map((k) => (k.id === id ? { ...final, id } : k))
  } else {
    contracts.value.unshift({ ...final, id: 'k' + Date.now() })
  }
  formSubmitting.value = false
  showForm.value = false
  editing.value = null
}

// ── Activate / deactivate ─────────────────────────────────────────────────
const toggleTarget = ref<Contract | null>(null)
function confirmToggle() {
  if (!toggleTarget.value) return
  const id = toggleTarget.value.id
  contracts.value = contracts.value.map((k) => (k.id === id ? { ...k, active: !k.active } : k))
  toggleTarget.value = null
}

// ── View detail (expandable row) ──────────────────────────────────────────
const expandedRow = ref<string | null>(null)
function toggleExpand(id: string) {
  expandedRow.value = expandedRow.value === id ? null : id
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.contracts.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.contracts.description') }}</p>
      </div>
      <button
        type="button"
        @click="openCreate"
        class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2 shrink-0"
      >
        <i class="pi pi-plus" />
        <span class="hidden sm:inline">{{ t('contracts.list.addNew') }}</span>
      </button>
    </header>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-3">
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('contracts.list.total') }}</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ stats.total }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('contracts.list.activeContracts') }}</div>
        <div class="text-2xl font-semibold text-emerald-600 mt-1">{{ stats.active }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('contracts.list.productsCovered') }}</div>
        <div class="text-2xl font-semibold text-brand-600 mt-1">{{ stats.productsCovered }}</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-[240px]">
        <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
        <input
          v-model="search"
          type="search"
          :placeholder="t('contracts.list.searchPlaceholder')"
          class="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-400"
        />
      </div>

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
              <th class="w-8" />
              <th class="text-left px-4 py-3 font-medium">{{ t('contracts.cols.contract') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('contracts.cols.carrier') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('contracts.cols.effectivePeriod') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('contracts.cols.productCount') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('contracts.cols.avgFirstYear') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('contracts.cols.avgRenewal') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('contracts.cols.status') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('contracts.cols.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <template v-for="k in filtered" :key="k.id">
              <tr class="hover:bg-slate-50/50">
                <td class="px-2 py-3 text-center">
                  <button
                    @click="toggleExpand(k.id)"
                    class="w-7 h-7 rounded hover:bg-slate-100 flex items-center justify-center text-slate-400"
                  >
                    <i :class="expandedRow === k.id ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" class="text-xs" />
                  </button>
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-900">{{ k.contractNo }}</td>
                <td class="px-4 py-3">
                  <div class="font-medium text-slate-900 text-sm">{{ carrierById(k.carrierId)?.code }}</div>
                  <div class="text-xs text-slate-500 truncate max-w-[200px]">{{ carrierById(k.carrierId)?.name }}</div>
                </td>
                <td class="px-4 py-3 text-xs text-slate-700">
                  <div>{{ k.effectiveFrom }}</div>
                  <div class="text-slate-400">→ {{ k.effectiveTo ?? t('contracts.fields.noEndDate') }}</div>
                </td>
                <td class="px-4 py-3 text-right font-medium text-slate-900">{{ k.schedule.length }}</td>
                <td class="px-4 py-3 text-right text-slate-700 font-mono">{{ avgFirstYear(k).toFixed(1) }}%</td>
                <td class="px-4 py-3 text-right text-slate-700 font-mono">{{ avgRenewal(k).toFixed(1) }}%</td>
                <td class="px-4 py-3">
                  <span
                    :class="[
                      'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium',
                      k.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500',
                    ]"
                  >
                    <span :class="['w-1.5 h-1.5 rounded-full', k.active ? 'bg-emerald-500' : 'bg-slate-400']" />
                    {{ k.active ? t('common.active') : t('common.inactive') }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <div class="flex items-center justify-end gap-1">
                    <button
                      type="button"
                      @click="openEdit(k)"
                      class="px-2 py-1 text-xs text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded transition"
                      :title="t('common.edit')"
                    >
                      <i class="pi pi-pencil" />
                    </button>
                    <button
                      type="button"
                      @click="toggleTarget = k"
                      :class="[
                        'px-2 py-1 text-xs rounded transition',
                        k.active ? 'text-rose-600 hover:bg-rose-50' : 'text-emerald-600 hover:bg-emerald-50',
                      ]"
                      :title="k.active ? t('contracts.confirm.deactivateTitle') : t('contracts.confirm.activateTitle')"
                    >
                      <i :class="k.active ? 'pi pi-ban' : 'pi pi-check-circle'" />
                    </button>
                  </div>
                </td>
              </tr>
              <!-- Expanded schedule detail -->
              <tr v-if="expandedRow === k.id" class="bg-slate-50/40">
                <td colspan="9" class="px-12 py-4">
                  <div class="rounded-lg border border-slate-200 bg-white overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-100 flex items-center justify-between">
                      <h4 class="text-xs font-semibold text-slate-700 uppercase tracking-wider">
                        {{ t('contracts.fields.schedule') }}
                      </h4>
                      <span class="text-xs text-slate-400">{{ k.schedule.length }} ผลิตภัณฑ์</span>
                    </div>
                    <table class="w-full text-sm">
                      <thead class="text-xs text-slate-500 bg-slate-50">
                        <tr>
                          <th class="text-left px-4 py-2 font-medium">{{ t('contracts.dialog.productCol') }}</th>
                          <th class="text-right px-4 py-2 font-medium">{{ t('contracts.dialog.firstYearCol') }}</th>
                          <th class="text-right px-4 py-2 font-medium">{{ t('contracts.dialog.renewalCol') }}</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in k.schedule" :key="row.productId">
                          <td class="px-4 py-2">
                            <div class="text-slate-900 text-sm">{{ productById(row.productId)?.name }}</div>
                            <div class="text-xs text-slate-400 font-mono">{{ productById(row.productId)?.code }}</div>
                          </td>
                          <td class="px-4 py-2 text-right font-mono text-slate-900">{{ row.firstYearRate.toFixed(1) }}%</td>
                          <td class="px-4 py-2 text-right font-mono text-slate-700">{{ row.renewalRate.toFixed(1) }}%</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <div v-if="k.notes" class="mt-3 text-xs text-slate-600 px-1">
                    <span class="font-medium text-slate-500">{{ t('contracts.fields.notes') }}:</span> {{ k.notes }}
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!filtered.length">
              <td colspan="9" class="px-4 py-10 text-center text-slate-400 text-sm">
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
    >
      <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[92vh] flex flex-col">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <h3 class="font-semibold text-slate-900">
            {{ isEdit ? t('contracts.dialog.editTitle') : t('contracts.dialog.createTitle') }}
          </h3>
          <button @click="closeForm" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>

        <div class="px-5 py-5 overflow-y-auto flex-1 space-y-6">
          <!-- Header section -->
          <section class="space-y-4">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400">
              {{ t('contracts.dialog.header') }}
            </h4>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('contracts.fields.contractNo') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.contractNo"
                  type="text"
                  required
                  placeholder="CT-XXX-2567-001"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('contracts.fields.carrier') }} <span class="text-rose-500">*</span>
                </label>
                <select
                  v-model="form.carrierId"
                  @change="onCarrierChange"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option value="" disabled>เลือกบริษัทประกัน...</option>
                  <option v-for="c in carriers" :key="c.id" :value="c.id">
                    {{ c.code }} · {{ c.name }}
                  </option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('contracts.fields.effectiveFrom') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.effectiveFrom"
                  type="date"
                  required
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('contracts.fields.effectiveTo') }}
                </label>
                <div class="flex items-center gap-3">
                  <input
                    v-model="form.effectiveTo"
                    type="date"
                    :disabled="noEndDate"
                    class="flex-1 px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 disabled:bg-slate-50 disabled:text-slate-400"
                  />
                  <label class="inline-flex items-center gap-2 whitespace-nowrap">
                    <input
                      v-model="noEndDate"
                      type="checkbox"
                      class="w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    />
                    <span class="text-xs text-slate-600">{{ t('contracts.fields.noEndDate') }}</span>
                  </label>
                </div>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('contracts.fields.notes') }}</label>
              <textarea
                v-model="form.notes"
                rows="2"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
              />
            </div>
          </section>

          <!-- Schedule section -->
          <section class="space-y-3 pt-2 border-t border-slate-100">
            <div class="flex items-center justify-between">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                {{ t('contracts.dialog.schedule') }}
              </h4>
              <span class="text-xs text-slate-500">{{ form.schedule.length }} ผลิตภัณฑ์</span>
            </div>

            <!-- Empty hints -->
            <div
              v-if="!form.carrierId"
              class="text-sm text-slate-500 bg-slate-50 border border-dashed border-slate-200 rounded-lg p-6 text-center"
            >
              <i class="pi pi-info-circle text-slate-400 text-2xl mb-2 block" />
              {{ t('contracts.dialog.noProductsHint') }}
            </div>

            <div
              v-else-if="!form.schedule.length"
              class="text-sm text-slate-500 bg-slate-50 border border-dashed border-slate-200 rounded-lg p-6 text-center"
            >
              <i class="pi pi-list text-slate-400 text-2xl mb-2 block" />
              {{ t('contracts.dialog.noScheduleHint') }}
            </div>

            <!-- Schedule table -->
            <div v-else class="rounded-lg border border-slate-200 overflow-hidden">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wider">
                  <tr>
                    <th class="text-left px-3 py-2 font-medium">{{ t('contracts.dialog.productCol') }}</th>
                    <th class="text-left px-3 py-2 font-medium w-32">{{ t('contracts.dialog.firstYearCol') }}</th>
                    <th class="text-left px-3 py-2 font-medium w-32">{{ t('contracts.dialog.renewalCol') }}</th>
                    <th class="w-12" />
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="row in form.schedule" :key="row.productId">
                    <td class="px-3 py-2">
                      <div class="text-slate-900 text-sm">{{ productById(row.productId)?.name }}</div>
                      <div class="text-xs text-slate-400 font-mono">{{ productById(row.productId)?.code }}</div>
                    </td>
                    <td class="px-3 py-2">
                      <div class="relative">
                        <input
                          v-model.number="row.firstYearRate"
                          type="number"
                          min="0"
                          max="100"
                          step="0.5"
                          class="w-full px-2.5 py-1.5 pr-7 border border-slate-300 rounded-md text-sm font-mono text-right focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                        />
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs">%</span>
                      </div>
                    </td>
                    <td class="px-3 py-2">
                      <div class="relative">
                        <input
                          v-model.number="row.renewalRate"
                          type="number"
                          min="0"
                          max="100"
                          step="0.5"
                          class="w-full px-2.5 py-1.5 pr-7 border border-slate-300 rounded-md text-sm font-mono text-right focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                        />
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs">%</span>
                      </div>
                    </td>
                    <td class="px-2 py-2 text-center">
                      <button
                        type="button"
                        @click="removeProductRow(row.productId)"
                        class="w-7 h-7 text-rose-500 hover:bg-rose-50 rounded transition flex items-center justify-center"
                        :title="t('contracts.dialog.removeRow')"
                      >
                        <i class="pi pi-trash text-xs" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Add product row -->
            <div v-if="form.carrierId && availableProducts.length" class="flex items-center gap-2">
              <select
                v-model="newProductId"
                class="flex-1 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500"
              >
                <option value="" disabled>เลือกผลิตภัณฑ์เพื่อเพิ่ม...</option>
                <option v-for="p in availableProducts" :key="p.id" :value="p.id">
                  {{ p.code }} · {{ p.name }}
                </option>
              </select>
              <button
                type="button"
                @click="addProductRow"
                :disabled="!newProductId"
                class="px-3 py-2 text-sm bg-slate-900 text-white rounded-lg hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1.5"
              >
                <i class="pi pi-plus text-xs" />
                {{ t('contracts.dialog.addProduct') }}
              </button>
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
            {{ toggleTarget.active ? t('contracts.confirm.deactivateTitle') : t('contracts.confirm.activateTitle') }}
          </h3>
          <p class="text-sm text-slate-500 mt-1.5">
            {{ toggleTarget.active ? t('contracts.confirm.deactivateMsg') : t('contracts.confirm.activateMsg') }}
          </p>
          <div class="mt-3 p-3 bg-slate-50 border border-slate-100 rounded-lg text-sm">
            <div class="font-mono text-xs text-slate-500">{{ toggleTarget.contractNo }}</div>
            <div class="font-medium text-slate-900">{{ carrierById(toggleTarget.carrierId)?.name }}</div>
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button
            type="button"
            @click="toggleTarget = null"
            class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
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
