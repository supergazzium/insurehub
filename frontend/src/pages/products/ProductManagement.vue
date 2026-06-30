<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type ProductType =
  | 'lifeWhole'
  | 'lifeTerm'
  | 'endowment'
  | 'annuity'
  | 'ul'
  | 'health'
  | 'accident'
  | 'criticalIllness'
  | 'motor'
  | 'home'
  | 'travel'

type PremiumMode = 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single'
type Gender = 'all' | 'maleOnly' | 'femaleOnly'
type OccClass = 'class1' | 'class2' | 'class3' | 'class4'

interface CarrierRef {
  id: string
  code: string
  name: string
}

interface Product {
  id: string
  code: string
  name: string
  nameEn: string
  carrierId: string
  type: ProductType
  summary: string
  coverage: number
  durationYears: number
  payYears: number
  premiumMode: PremiumMode
  minPremium: number
  maxPremium: number
  minAge: number
  maxAge: number
  gender: Gender
  requireMedical: boolean
  smokerAccepted: boolean
  preexistingExcluded: boolean
  occupationClasses: OccClass[]
  notes: string
  active: boolean
}

// ── Carrier reference (mirrors carriers from Section 3) ────────────────────
const carriers: CarrierRef[] = [
  { id: 'c1', code: 'AIA', name: 'บริษัท เอไอเอ จำกัด' },
  { id: 'c2', code: 'TLI', name: 'บริษัท ไทยประกันชีวิต จำกัด (มหาชน)' },
  { id: 'c3', code: 'MTI', name: 'บริษัท เมืองไทยประกันชีวิต จำกัด (มหาชน)' },
  { id: 'c4', code: 'BLA', name: 'บริษัท กรุงเทพประกันชีวิต จำกัด (มหาชน)' },
  { id: 'c5', code: 'VIB', name: 'บริษัท วิริยะประกันภัย จำกัด (มหาชน)' },
  { id: 'c6', code: 'DHA', name: 'บริษัท ทิพยประกันภัย จำกัด (มหาชน)' },
  { id: 'c8', code: 'ALL', name: 'บริษัท อลิอันซ์ อยุธยา ประกันชีวิต จำกัด (มหาชน)' },
]
function carrierById(id: string) {
  return carriers.find((c) => c.id === id)
}

const products = ref<Product[]>([
  {
    id: 'p1', code: 'AIA-WL100', name: 'เอไอเอ ตลอดชีพ 100', nameEn: 'AIA Whole Life 100',
    carrierId: 'c1', type: 'lifeWhole',
    summary: 'ความคุ้มครองตลอดชีพถึงอายุ 99 ปี พร้อมเงินคืนทุก 5 ปี',
    coverage: 2_000_000, durationYears: 99, payYears: 20, premiumMode: 'annual',
    minPremium: 25_000, maxPremium: 500_000, minAge: 1, maxAge: 65, gender: 'all',
    requireMedical: true, smokerAccepted: true, preexistingExcluded: true,
    occupationClasses: ['class1', 'class2', 'class3'], notes: '', active: true,
  },
  {
    id: 'p2', code: 'AIA-EN20', name: 'เอไอเอ สะสมทรัพย์ 20/10', nameEn: 'AIA Endowment 20/10',
    carrierId: 'c1', type: 'endowment',
    summary: 'สะสมทรัพย์ 20 ปี ชำระเบี้ย 10 ปี เงินคืนปีละ 3% ของทุน',
    coverage: 1_000_000, durationYears: 20, payYears: 10, premiumMode: 'annual',
    minPremium: 18_000, maxPremium: 300_000, minAge: 1, maxAge: 60, gender: 'all',
    requireMedical: false, smokerAccepted: true, preexistingExcluded: false,
    occupationClasses: ['class1', 'class2', 'class3', 'class4'], notes: '', active: true,
  },
  {
    id: 'p3', code: 'AIA-HEALTH+', name: 'เอไอเอ เฮลธ์ พลัส', nameEn: 'AIA Health Plus',
    carrierId: 'c1', type: 'health',
    summary: 'ค่ารักษาพยาบาลแบบเหมาจ่ายสูงสุด 30 ล้าน ครอบคลุม OPD',
    coverage: 30_000_000, durationYears: 1, payYears: 1, premiumMode: 'annual',
    minPremium: 12_000, maxPremium: 180_000, minAge: 15, maxAge: 80, gender: 'all',
    requireMedical: true, smokerAccepted: true, preexistingExcluded: true,
    occupationClasses: ['class1', 'class2', 'class3'], notes: '', active: true,
  },
  {
    id: 'p4', code: 'TLI-RET65', name: 'บำนาญ มั่นคง 65', nameEn: 'Thai Life Retirement 65',
    carrierId: 'c2', type: 'annuity',
    summary: 'รับเงินบำนาญทุกปีจากอายุ 60 จนถึง 85 ปี',
    coverage: 3_000_000, durationYears: 25, payYears: 20, premiumMode: 'annual',
    minPremium: 30_000, maxPremium: 1_000_000, minAge: 20, maxAge: 50, gender: 'all',
    requireMedical: false, smokerAccepted: true, preexistingExcluded: false,
    occupationClasses: ['class1', 'class2'], notes: '', active: true,
  },
  {
    id: 'p5', code: 'TLI-CI+', name: 'ไทยประกันชีวิต โรคร้ายแรง พรีเมียม', nameEn: 'TLI Critical Illness Premium',
    carrierId: 'c2', type: 'criticalIllness',
    summary: 'คุ้มครอง 75 โรคร้ายแรง จ่ายเงินก้อนทันทีเมื่อตรวจพบ',
    coverage: 5_000_000, durationYears: 20, payYears: 10, premiumMode: 'annual',
    minPremium: 15_000, maxPremium: 300_000, minAge: 18, maxAge: 60, gender: 'all',
    requireMedical: true, smokerAccepted: false, preexistingExcluded: true,
    occupationClasses: ['class1', 'class2', 'class3'], notes: '', active: true,
  },
  {
    id: 'p6', code: 'MTI-UL', name: 'เมืองไทย ยูนิตลิงก์', nameEn: 'Muang Thai Unit Linked',
    carrierId: 'c3', type: 'ul',
    summary: 'ความคุ้มครองชีวิตควบการลงทุน เลือกกองทุนได้ 12 กองทุน',
    coverage: 5_000_000, durationYears: 99, payYears: 99, premiumMode: 'annual',
    minPremium: 36_000, maxPremium: 2_000_000, minAge: 18, maxAge: 65, gender: 'all',
    requireMedical: true, smokerAccepted: true, preexistingExcluded: true,
    occupationClasses: ['class1', 'class2'], notes: '', active: true,
  },
  {
    id: 'p7', code: 'BLA-PA', name: 'กรุงเทพ PA สบายใจ', nameEn: 'Bangkok PA Sabaijai',
    carrierId: 'c4', type: 'accident',
    summary: 'อุบัติเหตุส่วนบุคคล คุ้มครอง 24 ชั่วโมงทั่วโลก',
    coverage: 1_000_000, durationYears: 1, payYears: 1, premiumMode: 'annual',
    minPremium: 1_500, maxPremium: 20_000, minAge: 15, maxAge: 70, gender: 'all',
    requireMedical: false, smokerAccepted: true, preexistingExcluded: false,
    occupationClasses: ['class1', 'class2', 'class3', 'class4'], notes: '', active: true,
  },
  {
    id: 'p8', code: 'VIB-MOTOR1', name: 'วิริยะ ประกันรถยนต์ ชั้น 1', nameEn: 'Viriyah Motor Class 1',
    carrierId: 'c5', type: 'motor',
    summary: 'ประกันรถยนต์ชั้น 1 ซ่อมห้าง คุ้มครองรถยนต์และทรัพย์สิน',
    coverage: 5_000_000, durationYears: 1, payYears: 1, premiumMode: 'annual',
    minPremium: 14_000, maxPremium: 60_000, minAge: 18, maxAge: 80, gender: 'all',
    requireMedical: false, smokerAccepted: true, preexistingExcluded: false,
    occupationClasses: ['class1', 'class2', 'class3', 'class4'], notes: 'รับรถยนต์อายุไม่เกิน 10 ปี', active: true,
  },
  {
    id: 'p9', code: 'DHA-HOME', name: 'ทิพย โฮม เซฟ', nameEn: 'Dhipaya Home Safe',
    carrierId: 'c6', type: 'home',
    summary: 'คุ้มครองบ้านพร้อมทรัพย์สินจากไฟไหม้ น้ำท่วม โจรกรรม',
    coverage: 3_000_000, durationYears: 1, payYears: 1, premiumMode: 'annual',
    minPremium: 2_500, maxPremium: 30_000, minAge: 20, maxAge: 80, gender: 'all',
    requireMedical: false, smokerAccepted: true, preexistingExcluded: false,
    occupationClasses: ['class1', 'class2', 'class3', 'class4'], notes: '', active: true,
  },
  {
    id: 'p10', code: 'DHA-TRAVEL', name: 'ทิพย ทราเวล พลัส', nameEn: 'Dhipaya Travel Plus',
    carrierId: 'c6', type: 'travel',
    summary: 'ประกันการเดินทางต่างประเทศ คุ้มครองค่ารักษาและเที่ยวบินยกเลิก',
    coverage: 5_000_000, durationYears: 1, payYears: 1, premiumMode: 'single',
    minPremium: 350, maxPremium: 5_000, minAge: 1, maxAge: 85, gender: 'all',
    requireMedical: false, smokerAccepted: true, preexistingExcluded: false,
    occupationClasses: ['class1', 'class2', 'class3', 'class4'], notes: '', active: true,
  },
  {
    id: 'p11', code: 'ALL-TERM10', name: 'อลิอันซ์ เทอม 10', nameEn: 'Allianz Term 10',
    carrierId: 'c8', type: 'lifeTerm',
    summary: 'ประกันชีวิตชั่วระยะ 10 ปี เบี้ยถูก ความคุ้มครองสูง',
    coverage: 10_000_000, durationYears: 10, payYears: 10, premiumMode: 'annual',
    minPremium: 8_000, maxPremium: 200_000, minAge: 20, maxAge: 55, gender: 'all',
    requireMedical: true, smokerAccepted: false, preexistingExcluded: true,
    occupationClasses: ['class1', 'class2'], notes: '', active: false,
  },
])

// ── Filters ───────────────────────────────────────────────────────────────
const search = ref('')
const carrierFilter = ref<'all' | string>('all')
const typeFilter = ref<'all' | ProductType>('all')
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')

const productTypes: ProductType[] = [
  'lifeWhole', 'lifeTerm', 'endowment', 'annuity', 'ul', 'health',
  'accident', 'criticalIllness', 'motor', 'home', 'travel',
]

const filtered = computed(() =>
  products.value.filter((p) => {
    if (carrierFilter.value !== 'all' && p.carrierId !== carrierFilter.value) return false
    if (typeFilter.value !== 'all' && p.type !== typeFilter.value) return false
    if (statusFilter.value === 'active' && !p.active) return false
    if (statusFilter.value === 'inactive' && p.active) return false
    if (search.value) {
      const q = search.value.toLowerCase()
      const hay = `${p.name} ${p.nameEn} ${p.code}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  }),
)

const stats = computed(() => ({
  total: products.value.length,
  active: products.value.filter((p) => p.active).length,
  carriers: new Set(products.value.map((p) => p.carrierId)).size,
}))

function typeBadgeClass(type: ProductType) {
  const lifeGroup: ProductType[] = ['lifeWhole', 'lifeTerm', 'endowment', 'annuity', 'ul']
  const healthGroup: ProductType[] = ['health', 'criticalIllness', 'accident']
  if (lifeGroup.includes(type)) return 'bg-sky-50 text-sky-700'
  if (healthGroup.includes(type)) return 'bg-rose-50 text-rose-700'
  return 'bg-emerald-50 text-emerald-700'
}

function formatTHB(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1) + ' ล้าน'
  if (n >= 1_000) return (n / 1_000).toFixed(0) + ' พัน'
  return String(n)
}

function formatFullTHB(n: number): string {
  return n.toLocaleString('th-TH')
}

// ── Create / edit dialog ──────────────────────────────────────────────────
const showForm = ref(false)
const editing = ref<Product | null>(null)
const isEdit = computed(() => !!editing.value)
const formTab = ref<'basic' | 'terms' | 'eligibility'>('basic')
const formSubmitting = ref(false)

const defaultForm = (): Product => ({
  id: '',
  code: '',
  name: '',
  nameEn: '',
  carrierId: carriers[0].id,
  type: 'lifeWhole',
  summary: '',
  coverage: 1_000_000,
  durationYears: 20,
  payYears: 10,
  premiumMode: 'annual',
  minPremium: 10_000,
  maxPremium: 100_000,
  minAge: 18,
  maxAge: 65,
  gender: 'all',
  requireMedical: false,
  smokerAccepted: true,
  preexistingExcluded: false,
  occupationClasses: ['class1', 'class2', 'class3'],
  notes: '',
  active: true,
})

const form = reactive<Product>(defaultForm())

function openCreate() {
  editing.value = null
  Object.assign(form, defaultForm())
  formTab.value = 'basic'
  showForm.value = true
}

function openEdit(p: Product) {
  editing.value = p
  Object.assign(form, { ...p, occupationClasses: [...p.occupationClasses] })
  formTab.value = 'basic'
  showForm.value = true
}

function closeForm() {
  showForm.value = false
}

const basicValid = computed(
  () =>
    form.code.trim().length >= 2 &&
    form.name.trim().length >= 3 &&
    !!form.carrierId,
)
const termsValid = computed(
  () =>
    form.coverage > 0 &&
    form.durationYears > 0 &&
    form.payYears > 0 &&
    form.payYears <= form.durationYears &&
    form.minPremium >= 0 &&
    form.maxPremium >= form.minPremium,
)
const eligibilityValid = computed(
  () =>
    form.minAge >= 0 &&
    form.maxAge >= form.minAge &&
    form.maxAge <= 100 &&
    form.occupationClasses.length > 0,
)
const formValid = computed(() => basicValid.value && termsValid.value && eligibilityValid.value)

function toggleOccClass(c: OccClass) {
  if (form.occupationClasses.includes(c)) {
    form.occupationClasses = form.occupationClasses.filter((x) => x !== c)
  } else {
    form.occupationClasses = [...form.occupationClasses, c]
  }
}

async function submitForm() {
  if (!formValid.value) return
  formSubmitting.value = true
  await new Promise((r) => setTimeout(r, 400))
  if (isEdit.value && editing.value) {
    const targetId = editing.value.id
    products.value = products.value.map((p) =>
      p.id === targetId ? { ...p, ...form, id: targetId } : p,
    )
  } else {
    products.value.unshift({ ...form, id: 'p' + Date.now() })
  }
  formSubmitting.value = false
  showForm.value = false
  editing.value = null
}

// ── Activate / deactivate ─────────────────────────────────────────────────
const toggleTarget = ref<Product | null>(null)
function confirmToggle() {
  if (!toggleTarget.value) return
  const id = toggleTarget.value.id
  products.value = products.value.map((p) => (p.id === id ? { ...p, active: !p.active } : p))
  toggleTarget.value = null
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.products.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.products.description') }}</p>
      </div>
      <button
        type="button"
        @click="openCreate"
        class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2 shrink-0"
      >
        <i class="pi pi-plus" />
        <span class="hidden sm:inline">{{ t('products.list.addNew') }}</span>
      </button>
    </header>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-3">
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('products.list.total') }}</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ stats.total }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('products.list.activeProducts') }}</div>
        <div class="text-2xl font-semibold text-emerald-600 mt-1">{{ stats.active }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('products.list.carriers') }}</div>
        <div class="text-2xl font-semibold text-brand-600 mt-1">{{ stats.carriers }}</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-[240px]">
        <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
        <input
          v-model="search"
          type="search"
          :placeholder="t('products.list.searchPlaceholder')"
          class="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-400"
        />
      </div>

      <select
        v-model="carrierFilter"
        class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400 max-w-[200px]"
      >
        <option value="all">{{ t('products.fields.carrier') }}: {{ t('common.all') }}</option>
        <option v-for="c in carriers" :key="c.id" :value="c.id">
          {{ c.code }} · {{ c.name }}
        </option>
      </select>

      <select
        v-model="typeFilter"
        class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400"
      >
        <option value="all">{{ t('products.cols.type') }}: {{ t('common.all') }}</option>
        <option v-for="ty in productTypes" :key="ty" :value="ty">
          {{ t(`products.types.${ty}`) }}
        </option>
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
              <th class="text-left px-4 py-3 font-medium">{{ t('products.cols.product') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('products.cols.carrier') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('products.cols.type') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('products.cols.coverage') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('products.cols.premium') }}</th>
              <th class="text-center px-4 py-3 font-medium">{{ t('products.cols.ageRange') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('products.cols.status') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('products.cols.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="p in filtered" :key="p.id" class="hover:bg-slate-50/50">
              <td class="px-4 py-3 max-w-xs">
                <div class="font-medium text-slate-900 truncate">{{ p.name }}</div>
                <div class="text-xs text-slate-500 truncate">
                  <span class="font-mono">{{ p.code }}</span>
                  <span v-if="p.summary" class="mx-1">·</span>
                  <span v-if="p.summary" class="truncate">{{ p.summary }}</span>
                </div>
              </td>
              <td class="px-4 py-3">
                <div class="text-slate-900 text-xs font-medium">{{ carrierById(p.carrierId)?.code }}</div>
                <div class="text-xs text-slate-500 truncate max-w-[180px]">{{ carrierById(p.carrierId)?.name }}</div>
              </td>
              <td class="px-4 py-3">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', typeBadgeClass(p.type)]">
                  {{ t(`products.types.${p.type}`) }}
                </span>
              </td>
              <td class="px-4 py-3 text-right font-medium text-slate-900 whitespace-nowrap">
                {{ formatTHB(p.coverage) }}
              </td>
              <td class="px-4 py-3 text-right text-slate-700 whitespace-nowrap text-xs">
                <div>{{ formatTHB(p.minPremium) }} – {{ formatTHB(p.maxPremium) }}</div>
                <div class="text-slate-400">{{ t(`products.premiumModes.${p.premiumMode}`) }}</div>
              </td>
              <td class="px-4 py-3 text-center text-slate-700 whitespace-nowrap text-xs">
                {{ p.minAge }} – {{ p.maxAge }} ปี
              </td>
              <td class="px-4 py-3">
                <span
                  :class="[
                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium',
                    p.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500',
                  ]"
                >
                  <span :class="['w-1.5 h-1.5 rounded-full', p.active ? 'bg-emerald-500' : 'bg-slate-400']" />
                  {{ p.active ? t('common.active') : t('common.inactive') }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    @click="openEdit(p)"
                    class="px-2 py-1 text-xs text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded transition"
                    :title="t('common.edit')"
                  >
                    <i class="pi pi-pencil" />
                  </button>
                  <button
                    type="button"
                    @click="toggleTarget = p"
                    :class="[
                      'px-2 py-1 text-xs rounded transition',
                      p.active ? 'text-rose-600 hover:bg-rose-50' : 'text-emerald-600 hover:bg-emerald-50',
                    ]"
                    :title="p.active ? t('products.confirm.deactivateTitle') : t('products.confirm.activateTitle')"
                  >
                    <i :class="p.active ? 'pi pi-ban' : 'pi pi-check-circle'" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filtered.length">
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
      <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[92vh] flex flex-col">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <h3 class="font-semibold text-slate-900">
            {{ isEdit ? t('products.dialog.editTitle') : t('products.dialog.createTitle') }}
          </h3>
          <button @click="closeForm" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>

        <!-- Form tabs -->
        <div class="border-b border-slate-100 px-5 flex items-center gap-1 shrink-0">
          <button
            v-for="tk in (['basic', 'terms', 'eligibility'] as const)"
            :key="tk"
            type="button"
            @click="formTab = tk"
            :class="[
              'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition flex items-center gap-2',
              formTab === tk
                ? 'border-brand-600 text-brand-700'
                : 'border-transparent text-slate-500 hover:text-slate-900',
            ]"
          >
            {{ t(`products.dialog.tabs.${tk}`) }}
            <i
              v-if="(tk === 'basic' && !basicValid) || (tk === 'terms' && !termsValid) || (tk === 'eligibility' && !eligibilityValid)"
              class="pi pi-exclamation-circle text-rose-400 text-xs"
            />
          </button>
        </div>

        <div class="px-5 py-5 overflow-y-auto flex-1">
          <!-- Basic tab -->
          <section v-if="formTab === 'basic'" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.code') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.code"
                  type="text"
                  required
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono uppercase focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.carrier') }} <span class="text-rose-500">*</span>
                </label>
                <select
                  v-model="form.carrierId"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option v-for="c in carriers" :key="c.id" :value="c.id">
                    {{ c.code }} · {{ c.name }}
                  </option>
                </select>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">
                {{ t('products.fields.name') }} <span class="text-rose-500">*</span>
              </label>
              <input
                v-model="form.name"
                type="text"
                required
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('products.fields.nameEn') }}</label>
              <input
                v-model="form.nameEn"
                type="text"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">
                {{ t('products.fields.type') }} <span class="text-rose-500">*</span>
              </label>
              <select
                v-model="form.type"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              >
                <option v-for="ty in productTypes" :key="ty" :value="ty">
                  {{ t(`products.types.${ty}`) }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('products.fields.summary') }}</label>
              <textarea
                v-model="form.summary"
                rows="2"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
              />
            </div>
          </section>

          <!-- Terms tab -->
          <section v-if="formTab === 'terms'" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div class="md:col-span-3">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.coverage') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="form.coverage"
                  type="number"
                  min="0"
                  step="100000"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
                <p class="text-xs text-slate-500 mt-1">≈ {{ formatTHB(form.coverage) }} บาท</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.durationYears') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="form.durationYears"
                  type="number"
                  min="1"
                  max="99"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.payYears') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="form.payYears"
                  type="number"
                  min="1"
                  :max="form.durationYears"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.premiumMode') }}
                </label>
                <select
                  v-model="form.premiumMode"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option v-for="m in (['monthly', 'quarterly', 'semiannual', 'annual', 'single'] as const)" :key="m" :value="m">
                    {{ t(`products.premiumModes.${m}`) }}
                  </option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-slate-100">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.minPremium') }}
                </label>
                <input
                  v-model.number="form.minPremium"
                  type="number"
                  min="0"
                  step="1000"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.maxPremium') }}
                </label>
                <input
                  v-model.number="form.maxPremium"
                  type="number"
                  :min="form.minPremium"
                  step="1000"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
          </section>

          <!-- Eligibility tab -->
          <section v-if="formTab === 'eligibility'" class="space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.minAge') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="form.minAge"
                  type="number"
                  min="0"
                  max="100"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.maxAge') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="form.maxAge"
                  type="number"
                  :min="form.minAge"
                  max="100"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('products.fields.genderRestriction') }}
                </label>
                <select
                  v-model="form.gender"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option v-for="g in (['all', 'maleOnly', 'femaleOnly'] as const)" :key="g" :value="g">
                    {{ t(`products.gender.${g}`) }}
                  </option>
                </select>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-4">
              <div class="text-sm font-medium text-slate-700 mb-2">{{ t('products.fields.occupationClasses') }} <span class="text-rose-500">*</span></div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <label
                  v-for="oc in (['class1', 'class2', 'class3', 'class4'] as OccClass[])"
                  :key="oc"
                  class="flex items-center gap-2 px-3 py-2 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50"
                >
                  <input
                    type="checkbox"
                    :checked="form.occupationClasses.includes(oc)"
                    @change="toggleOccClass(oc)"
                    class="w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                  />
                  <span class="text-sm text-slate-700">{{ t(`products.occupationClasses.${oc}`) }}</span>
                </label>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-4 space-y-3">
              <label class="flex items-start gap-3 cursor-pointer">
                <input
                  v-model="form.requireMedical"
                  type="checkbox"
                  class="mt-0.5 w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                />
                <div>
                  <div class="text-sm font-medium text-slate-700">{{ t('products.fields.requireMedical') }}</div>
                  <div class="text-xs text-slate-500">ผู้สมัครต้องผ่านการตรวจสุขภาพก่อน</div>
                </div>
              </label>

              <label class="flex items-start gap-3 cursor-pointer">
                <input
                  v-model="form.smokerAccepted"
                  type="checkbox"
                  class="mt-0.5 w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                />
                <div>
                  <div class="text-sm font-medium text-slate-700">{{ t('products.fields.smokerAccepted') }}</div>
                  <div class="text-xs text-slate-500">รับสมัครผู้สูบบุหรี่</div>
                </div>
              </label>

              <label class="flex items-start gap-3 cursor-pointer">
                <input
                  v-model="form.preexistingExcluded"
                  type="checkbox"
                  class="mt-0.5 w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                />
                <div>
                  <div class="text-sm font-medium text-slate-700">{{ t('products.fields.preexistingExcluded') }}</div>
                  <div class="text-xs text-slate-500">ไม่คุ้มครองโรคที่เป็นมาก่อน</div>
                </div>
              </label>
            </div>

            <div class="border-t border-slate-100 pt-4">
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('products.fields.notes') }}</label>
              <textarea
                v-model="form.notes"
                rows="2"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
              />
            </div>
          </section>
        </div>

        <!-- Summary strip -->
        <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 text-xs text-slate-600 grid grid-cols-2 md:grid-cols-4 gap-3 shrink-0">
          <div>
            <div class="text-slate-400">{{ t('products.fields.coverage') }}</div>
            <div class="font-medium text-slate-900">{{ formatFullTHB(form.coverage) }} บาท</div>
          </div>
          <div>
            <div class="text-slate-400">{{ t('products.fields.durationYears') }}</div>
            <div class="font-medium text-slate-900">{{ form.durationYears }} ปี / ชำระ {{ form.payYears }} ปี</div>
          </div>
          <div>
            <div class="text-slate-400">เบี้ย</div>
            <div class="font-medium text-slate-900">{{ formatFullTHB(form.minPremium) }} – {{ formatFullTHB(form.maxPremium) }}</div>
          </div>
          <div>
            <div class="text-slate-400">ช่วงอายุ</div>
            <div class="font-medium text-slate-900">{{ form.minAge }} – {{ form.maxAge }} ปี</div>
          </div>
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
            {{ toggleTarget.active ? t('products.confirm.deactivateTitle') : t('products.confirm.activateTitle') }}
          </h3>
          <p class="text-sm text-slate-500 mt-1.5">
            {{ toggleTarget.active ? t('products.confirm.deactivateMsg') : t('products.confirm.activateMsg') }}
          </p>
          <div class="mt-3 p-3 bg-slate-50 border border-slate-100 rounded-lg text-sm">
            <div class="font-medium text-slate-900">{{ toggleTarget.name }}</div>
            <div class="text-xs text-slate-500 font-mono">{{ toggleTarget.code }}</div>
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
