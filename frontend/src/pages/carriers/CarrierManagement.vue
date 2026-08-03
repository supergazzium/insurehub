<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  useCarrierContactsStore,
  DEPARTMENT_LABELS,
  INSURANCE_TYPE_LABELS,
  isAutoSeeded,
  type CarrierContactGroup,
  type ContactDepartment,
  type InsuranceType,
} from '../../stores/carrierContacts'

const { t } = useI18n()
const contactsStore = useCarrierContactsStore()

onMounted(() => {
  void contactsStore.load()
})

const DEPARTMENTS = Object.keys(DEPARTMENT_LABELS) as ContactDepartment[]
const INSURANCE_TYPES = Object.keys(INSURANCE_TYPE_LABELS) as InsuranceType[]

type CarrierType = 'life' | 'nonLife' | 'health' | 'mixed'

/** Detailed line-of-business — derived from old `Sub_Insurer_type` column.
 *  Lets the email-routing filter narrow groups beyond the high-level CarrierType.
 *  Empty = unspecified (matches all). */
type CarrierSubType = '' | 'life' | 'health' | 'motor' | 'fire' | 'travel' | 'pa' | 'ci' | 'mixed' | 'other'

interface Carrier {
  id: string
  code: string
  name: string
  nameEn: string
  /** Short Thai display name, e.g. "เอไอเอ", "ทิพย" — used in chat / tables.
   *  Mapped from Access DB's `INC_nickname`. */
  nicknameTh: string
  type: CarrierType
  subType: CarrierSubType
  /** Carrier's own internal company code (e.g. "1003"). From `Comp_insure_code`. */
  compInsureCode: string
  /** OIC member registration code (e.g. "850-00906"). From `OIC_InsureCom_Code`. */
  oicInsureComCode: string
  /** Our OIC operating license string (e.g. "L-2540-0001"). */
  oicLicense: string
  taxId: string
  phone: string
  email: string
  website: string
  address: string
  logoUrl?: string | null
  productCount: number
  contractCount: number
  since: string
  active: boolean
}

/** Fill any newly-added Carrier fields with sensible defaults so existing seeds
 *  stay valid without rewriting every literal. */
function withCarrierDefaults(
  partial: Partial<Carrier> & { id: string; code: string; name: string; type: CarrierType },
): Carrier {
  return {
    nameEn: '',
    nicknameTh: '',
    subType: '',
    compInsureCode: '',
    oicInsureComCode: '',
    oicLicense: '',
    taxId: '',
    phone: '',
    email: '',
    website: '',
    address: '',
    logoUrl: null,
    productCount: 0,
    contractCount: 0,
    since: '',
    active: true,
    ...partial,
  }
}

const carriers = ref<Carrier[]>(([
  {
    id: 'c1',
    code: 'AIA',
    name: 'บริษัท เอไอเอ จำกัด',
    nameEn: 'AIA (Thailand) Co., Ltd.',
    type: 'life',
    oicLicense: 'L-2540-0001',
    taxId: '0107536000226',
    phone: '02-783-8888',
    email: 'contact@aia.co.th',
    website: 'https://www.aia.co.th',
    address: '181 ถนนสุรวงศ์ เขตบางรัก กรุงเทพมหานคร 10500',
    logoUrl: null,
    productCount: 24,
    contractCount: 12,
    since: '2540',
    active: true,
  },
  {
    id: 'c2',
    code: 'TLI',
    name: 'บริษัท ไทยประกันชีวิต จำกัด (มหาชน)',
    nameEn: 'Thai Life Insurance PCL',
    type: 'life',
    oicLicense: 'L-2485-0002',
    taxId: '0107551000037',
    phone: '02-247-0247',
    email: 'contact@thailife.com',
    website: 'https://www.thailife.com',
    address: '123 ถนนรัชดาภิเษก เขตดินแดง กรุงเทพมหานคร 10400',
    logoUrl: null,
    productCount: 18,
    contractCount: 9,
    since: '2485',
    active: true,
  },
  {
    id: 'c3',
    code: 'MTI',
    name: 'บริษัท เมืองไทยประกันชีวิต จำกัด (มหาชน)',
    nameEn: 'Muang Thai Life Assurance PCL',
    type: 'life',
    oicLicense: 'L-2494-0003',
    taxId: '0107551000186',
    phone: '02-274-9400',
    email: 'info@muangthai.co.th',
    website: 'https://www.muangthai.co.th',
    address: '250 ถนนรัชดาภิเษก ห้วยขวาง กรุงเทพมหานคร 10310',
    logoUrl: null,
    productCount: 21,
    contractCount: 11,
    since: '2494',
    active: true,
  },
  {
    id: 'c4',
    code: 'BLA',
    name: 'บริษัท กรุงเทพประกันชีวิต จำกัด (มหาชน)',
    nameEn: 'Bangkok Life Assurance PCL',
    type: 'life',
    oicLicense: 'L-2494-0004',
    taxId: '0107550000244',
    phone: '02-777-8888',
    email: 'info@bla.co.th',
    website: 'https://www.bla.co.th',
    address: '23/115-121 ถนนรัชดาภิเษก คลองเตย กรุงเทพมหานคร 10110',
    logoUrl: null,
    productCount: 16,
    contractCount: 7,
    since: '2494',
    active: true,
  },
  {
    id: 'c5',
    code: 'VIB',
    name: 'บริษัท วิริยะประกันภัย จำกัด (มหาชน)',
    nameEn: 'Viriyah Insurance PCL',
    type: 'nonLife',
    oicLicense: 'N-2490-0005',
    taxId: '0107550000139',
    phone: '02-129-7777',
    email: 'info@viriyah.co.th',
    website: 'https://www.viriyah.co.th',
    address: '121/28 ถนนรัชดาภิเษก ดินแดง กรุงเทพมหานคร 10400',
    logoUrl: null,
    productCount: 28,
    contractCount: 14,
    since: '2490',
    active: true,
  },
  {
    id: 'c6',
    code: 'DHA',
    name: 'บริษัท ทิพยประกันภัย จำกัด (มหาชน)',
    nameEn: 'Dhipaya Insurance PCL',
    type: 'nonLife',
    oicLicense: 'N-2494-0006',
    taxId: '0107551000054',
    phone: '02-239-2200',
    email: 'contact@dhipaya.co.th',
    website: 'https://www.dhipaya.co.th',
    address: '63 ถนนพระราม 9 ห้วยขวาง กรุงเทพมหานคร 10310',
    logoUrl: null,
    productCount: 22,
    contractCount: 10,
    since: '2494',
    active: true,
  },
  {
    id: 'c7',
    code: 'BUI',
    name: 'บริษัท กรุงเทพประกันภัย จำกัด (มหาชน)',
    nameEn: 'Bangkok Insurance PCL',
    type: 'nonLife',
    oicLicense: 'N-2490-0007',
    taxId: '0107550000089',
    phone: '02-285-8888',
    email: 'info@bangkokinsurance.com',
    website: 'https://www.bangkokinsurance.com',
    address: '25 ถนนสาทรใต้ สาทร กรุงเทพมหานคร 10120',
    logoUrl: null,
    productCount: 19,
    contractCount: 8,
    since: '2490',
    active: false,
  },
  {
    id: 'c8',
    code: 'ALL',
    name: 'บริษัท อลิอันซ์ อยุธยา ประกันชีวิต จำกัด (มหาชน)',
    nameEn: 'Allianz Ayudhya Assurance PCL',
    type: 'life',
    oicLicense: 'L-2494-0008',
    taxId: '0107551000178',
    phone: '02-305-7000',
    email: 'contact@allianz.co.th',
    website: 'https://www.allianz.co.th',
    address: '898 ถนนเพลินจิต ลุมพินี กรุงเทพมหานคร 10330',
    logoUrl: null,
    productCount: 15,
    contractCount: 6,
    since: '2494',
    active: true,
  },
] as Array<Partial<Carrier> & { id: string; code: string; name: string; type: CarrierType }>).map(withCarrierDefaults))

// ── Filters ───────────────────────────────────────────────────────────────
const search = ref('')
const typeFilter = ref<'all' | CarrierType>('all')
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')

const filtered = computed(() =>
  carriers.value.filter((c) => {
    if (typeFilter.value !== 'all' && c.type !== typeFilter.value) return false
    if (statusFilter.value === 'active' && !c.active) return false
    if (statusFilter.value === 'inactive' && c.active) return false
    if (search.value) {
      const q = search.value.toLowerCase()
      const hay = `${c.name} ${c.nameEn} ${c.code} ${c.oicLicense}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  }),
)

const counts = computed(() => ({
  total: carriers.value.length,
  active: carriers.value.filter((c) => c.active).length,
  inactive: carriers.value.filter((c) => !c.active).length,
}))

function typeBadgeClass(type: CarrierType) {
  const map: Record<CarrierType, string> = {
    life: 'bg-sky-50 text-sky-700',
    nonLife: 'bg-emerald-50 text-emerald-700',
    health: 'bg-rose-50 text-rose-700',
    mixed: 'bg-violet-50 text-violet-700',
  }
  return map[type]
}

// ── Create / edit dialog ──────────────────────────────────────────────────
const editing = ref<Carrier | null>(null)
const showForm = ref(false)
const isEdit = computed(() => !!editing.value)

const blankCarrierForm = (): Carrier => ({
  id: '',
  code: '',
  name: '',
  nameEn: '',
  nicknameTh: '',
  type: 'life',
  subType: '',
  compInsureCode: '',
  oicInsureComCode: '',
  oicLicense: '',
  taxId: '',
  phone: '',
  email: '',
  website: '',
  address: '',
  logoUrl: null,
  productCount: 0,
  contractCount: 0,
  since: '',
  active: true,
})

const form = reactive<Carrier>(blankCarrierForm())
const formSubmitting = ref(false)
const logoFileInput = ref<HTMLInputElement | null>(null)

function resetForm() {
  Object.assign(form, blankCarrierForm())
}

function openCreate() {
  editing.value = null
  resetForm()
  draftGroups.value = []
  showForm.value = true
}

function openEdit(c: Carrier) {
  editing.value = c
  Object.assign(form, c)
  loadDraftGroups(c.code)
  showForm.value = true
}

function closeForm() {
  showForm.value = false
}

function pickLogo() {
  logoFileInput.value?.click()
}
function onLogoChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const reader = new FileReader()
  reader.onload = () => {
    form.logoUrl = reader.result as string
  }
  reader.readAsDataURL(file)
}

const formValid = computed(
  () =>
    form.code.trim().length >= 2 &&
    form.name.trim().length >= 3 &&
    form.oicLicense.trim().length >= 3 &&
    form.phone.trim().length >= 8,
)

async function submitForm() {
  if (!formValid.value) return
  formSubmitting.value = true
  await new Promise((r) => setTimeout(r, 400))
  if (isEdit.value && editing.value) {
    const targetId = editing.value.id
    carriers.value = carriers.value.map((c) => (c.id === targetId ? { ...c, ...form, id: targetId } : c))
  } else {
    carriers.value.unshift({ ...form, id: 'c' + Date.now() })
  }
  persistGroups(form.code.toUpperCase())
  formSubmitting.value = false
  showForm.value = false
  editing.value = null
  draftGroups.value = []
}

// ── Email contact groups (inside edit/create dialog) ──────────────────────
// Draft list edited inside the dialog; committed on submitForm().
interface DraftGroup extends Omit<CarrierContactGroup, 'id' | 'carrierCode'> {
  id: string | null // null = newly-added in this session, not yet persisted
}

const draftGroups = ref<DraftGroup[]>([])

function loadDraftGroups(carrierCode: string) {
  draftGroups.value = contactsStore
    .listForCarrier(carrierCode)
    .map((g) => ({
      id: g.id,
      name: g.name,
      emails: [...g.emails],
      department: g.department,
      insuranceTypes: [...g.insuranceTypes],
      isDefault: g.isDefault,
      notes: g.notes,
      active: g.active,
    }))
}

function addDraftGroup() {
  draftGroups.value = [
    ...draftGroups.value,
    {
      id: null,
      name: '',
      emails: [''],
      department: 'new_business',
      insuranceTypes: [],
      isDefault: false,
      active: true,
    },
  ]
}

function removeDraftGroup(idx: number) {
  draftGroups.value = draftGroups.value.filter((_, i) => i !== idx)
}

function addEmailToGroup(g: DraftGroup) {
  g.emails = [...g.emails, '']
}

function removeEmailFromGroup(g: DraftGroup, idx: number) {
  // Keep at least one input visible so the user has somewhere to type.
  if (g.emails.length <= 1) {
    g.emails = ['']
    return
  }
  g.emails = g.emails.filter((_, i) => i !== idx)
}

function toggleDraftType(g: DraftGroup, type: InsuranceType) {
  if (g.insuranceTypes.includes(type)) {
    g.insuranceTypes = g.insuranceTypes.filter((t) => t !== type)
  } else {
    g.insuranceTypes = [...g.insuranceTypes, type]
  }
}

/** Persist draftGroups to the store, scoped to one carrier. */
function persistGroups(carrierCode: string) {
  const existing = contactsStore.listForCarrier(carrierCode)
  const keptIds = new Set(draftGroups.value.map((g) => g.id).filter(Boolean) as string[])
  for (const e of existing) {
    if (!keptIds.has(e.id)) contactsStore.removeGroup(e.id)
  }
  for (const d of draftGroups.value) {
    const cleanedEmails = d.emails.map((e) => e.trim()).filter(Boolean)
    const payload = {
      carrierCode,
      name: d.name.trim() || `${carrierCode} — ${DEPARTMENT_LABELS[d.department]}`,
      emails: cleanedEmails,
      department: d.department,
      insuranceTypes: d.insuranceTypes,
      isDefault: d.isDefault,
      notes: d.notes,
      active: d.active,
    }
    if (!cleanedEmails.length) continue
    if (d.id) contactsStore.updateGroup(d.id, payload)
    else contactsStore.addGroup(payload)
  }
}

// ── Activate / deactivate confirmation ────────────────────────────────────
const toggleTarget = ref<Carrier | null>(null)

function confirmToggle() {
  if (!toggleTarget.value) return
  const id = toggleTarget.value.id
  carriers.value = carriers.value.map((c) => (c.id === id ? { ...c, active: !c.active } : c))
  toggleTarget.value = null
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.carriers.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.carriers.description') }}</p>
      </div>
      <button
        type="button"
        @click="openCreate"
        class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2 shrink-0"
      >
        <i class="pi pi-plus" />
        <span class="hidden sm:inline">{{ t('carriers.list.addNew') }}</span>
      </button>
    </header>

    <!-- Stat strip -->
    <div class="grid grid-cols-3 gap-3">
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('carriers.list.total') }}</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ counts.total }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('common.active') }}</div>
        <div class="text-2xl font-semibold text-emerald-600 mt-1">{{ counts.active }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('common.inactive') }}</div>
        <div class="text-2xl font-semibold text-slate-400 mt-1">{{ counts.inactive }}</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-[240px]">
        <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
        <input
          v-model="search"
          type="search"
          :placeholder="t('carriers.list.searchPlaceholder')"
          class="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-400"
        />
      </div>

      <select
        v-model="typeFilter"
        class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400"
      >
        <option value="all">{{ t('carriers.cols.type') }}: {{ t('common.all') }}</option>
        <option v-for="ty in (['life', 'nonLife', 'health', 'mixed'] as const)" :key="ty" :value="ty">
          {{ t(`carriers.types.${ty}`) }}
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
              <th class="text-left px-4 py-3 font-medium">{{ t('carriers.cols.carrier') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('carriers.cols.type') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('carriers.cols.contact') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('carriers.cols.products') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('carriers.cols.contracts') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('carriers.cols.status') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('carriers.cols.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in filtered" :key="c.id" class="hover:bg-slate-50/50">
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-lg bg-slate-100 overflow-hidden flex items-center justify-center text-slate-400 text-xs font-medium shrink-0">
                    <img v-if="c.logoUrl" :src="c.logoUrl" alt="logo" class="w-full h-full object-contain" />
                    <span v-else>{{ c.code }}</span>
                  </div>
                  <div class="min-w-0">
                    <div class="font-medium text-slate-900 truncate">{{ c.name }}</div>
                    <div class="text-xs text-slate-500 truncate">
                      <span class="font-mono">{{ c.code }}</span>
                      <span class="mx-1">·</span>
                      <span>{{ c.oicLicense }}</span>
                    </div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', typeBadgeClass(c.type)]">
                  {{ t(`carriers.types.${c.type}`) }}
                </span>
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
              <td class="px-4 py-3 text-right font-medium text-slate-900">{{ c.productCount }}</td>
              <td class="px-4 py-3 text-right font-medium text-slate-900">{{ c.contractCount }}</td>
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
                    @click="openEdit(c)"
                    class="px-2 py-1 text-xs text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded transition"
                    :title="t('common.edit')"
                  >
                    <i class="pi pi-pencil" />
                  </button>
                  <button
                    type="button"
                    @click="toggleTarget = c"
                    :class="[
                      'px-2 py-1 text-xs rounded transition',
                      c.active ? 'text-rose-600 hover:bg-rose-50' : 'text-emerald-600 hover:bg-emerald-50',
                    ]"
                    :title="c.active ? t('carriers.confirm.deactivate') : t('carriers.confirm.activate')"
                  >
                    <i :class="c.active ? 'pi pi-ban' : 'pi pi-check-circle'" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filtered.length">
              <td colspan="7" class="px-4 py-10 text-center text-slate-400 text-sm">
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
      <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <h3 class="font-semibold text-slate-900">
            {{ isEdit ? t('carriers.dialog.editTitle') : t('carriers.dialog.createTitle') }}
          </h3>
          <button @click="closeForm" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>

        <form class="px-5 py-5 space-y-6 overflow-y-auto" @submit.prevent="submitForm">
          <!-- Basic info -->
          <section class="space-y-4">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400">
              {{ t('carriers.dialog.basicInfo') }}
            </h4>

            <div class="flex items-start gap-4">
              <div class="w-20 h-20 rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 flex items-center justify-center overflow-hidden shrink-0">
                <img v-if="form.logoUrl" :src="form.logoUrl" alt="logo" class="w-full h-full object-contain" />
                <i v-else class="pi pi-image text-slate-300 text-2xl" />
              </div>
              <div class="flex-1">
                <div class="text-sm font-medium text-slate-700 mb-1.5">{{ t('carriers.fields.logo') }}</div>
                <input ref="logoFileInput" type="file" accept="image/*" class="hidden" @change="onLogoChange" />
                <button
                  type="button"
                  @click="pickLogo"
                  class="px-3 py-1.5 text-sm border border-slate-300 rounded-lg hover:bg-slate-50 transition"
                >
                  {{ form.logoUrl ? 'เปลี่ยนรูป' : 'อัปโหลด' }}
                </button>
                <p class="text-xs text-slate-500 mt-1.5">แนะนำ PNG / SVG ไม่เกิน 2 MB</p>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div class="md:col-span-1">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('carriers.fields.code') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.code"
                  type="text"
                  maxlength="6"
                  required
                  placeholder="AIA"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono uppercase focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('carriers.fields.type') }} <span class="text-rose-500">*</span>
                </label>
                <select
                  v-model="form.type"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option v-for="ty in (['life', 'nonLife', 'health', 'mixed'] as const)" :key="ty" :value="ty">
                    {{ t(`carriers.types.${ty}`) }}
                  </option>
                </select>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">
                {{ t('carriers.fields.name') }} <span class="text-rose-500">*</span>
              </label>
              <input
                v-model="form.name"
                type="text"
                required
                placeholder="บริษัท ABC ประกันชีวิต จำกัด (มหาชน)"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('carriers.fields.nameEn') }}
                </label>
                <input
                  v-model="form.nameEn"
                  type="text"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  ชื่อย่อ (ภาษาไทย)
                  <span class="ml-1 font-normal text-slate-400 text-xs">— ใช้ในตาราง / chat</span>
                </label>
                <input
                  v-model="form.nicknameTh"
                  type="text"
                  placeholder="เช่น เอไอเอ"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('carriers.fields.oicLicense') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.oicLicense"
                  type="text"
                  required
                  placeholder="L-25xx-00xx"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('carriers.fields.taxId') }}
                </label>
                <input
                  v-model="form.taxId"
                  type="text"
                  maxlength="13"
                  inputmode="numeric"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>

            <!-- Carrier-system codes & sub-type -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  รหัสบริษัทประกัน
                  <span class="ml-1 font-normal text-slate-400 text-xs">(internal)</span>
                </label>
                <input
                  v-model="form.compInsureCode"
                  type="text"
                  placeholder="เช่น 1003"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  รหัสสมาชิก คปภ.
                </label>
                <input
                  v-model="form.oicInsureComCode"
                  type="text"
                  placeholder="850-00xxx"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  ประเภทย่อย
                  <span class="ml-1 font-normal text-slate-400 text-xs">— ใช้กรองกลุ่มอีเมล</span>
                </label>
                <select
                  v-model="form.subType"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option value="">— ไม่ระบุ —</option>
                  <option value="life">ประกันชีวิต</option>
                  <option value="health">สุขภาพ</option>
                  <option value="motor">รถยนต์</option>
                  <option value="fire">อัคคีภัย / ทรัพย์สิน</option>
                  <option value="travel">เดินทาง</option>
                  <option value="pa">PA / อุบัติเหตุ</option>
                  <option value="ci">โรคร้ายแรง (CI)</option>
                  <option value="mixed">หลายประเภท</option>
                  <option value="other">อื่น ๆ</option>
                </select>
              </div>
            </div>
          </section>

          <!-- Contact info -->
          <section class="space-y-4 pt-2 border-t border-slate-100">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400">
              {{ t('carriers.dialog.contactInfo') }}
            </h4>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('carriers.fields.phone') }} <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="form.phone"
                  type="tel"
                  required
                  placeholder="02-xxx-xxxx"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  {{ t('carriers.fields.email') }}
                </label>
                <input
                  v-model="form.email"
                  type="email"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">
                {{ t('carriers.fields.website') }}
              </label>
              <input
                v-model="form.website"
                type="url"
                placeholder="https://"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">
                {{ t('carriers.fields.address') }}
              </label>
              <textarea
                v-model="form.address"
                rows="2"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
              />
            </div>
          </section>

          <!-- Email contact groups -->
          <section class="space-y-3 pt-2 border-t border-slate-100">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                  Email Groups
                </h4>
                <p class="text-xs text-slate-500 mt-1 leading-snug">
                  ตั้งค่ากลุ่มอีเมลของบริษัทประกัน — แบ่งตามแผนก / ประเภทประกัน เพื่อให้ระบบส่งอีเมลถูกปลายทาง
                </p>
              </div>
              <button
                type="button"
                @click="addDraftGroup"
                class="px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg hover:bg-slate-50 transition flex items-center gap-1.5 shrink-0"
              >
                <i class="pi pi-plus text-[10px]" />
                <span>เพิ่มกลุ่ม</span>
              </button>
            </div>

            <div v-if="!draftGroups.length" class="border border-dashed border-slate-200 rounded-lg px-4 py-6 text-center">
              <i class="pi pi-envelope text-slate-300 text-xl block mb-1" />
              <p class="text-xs text-slate-500">ยังไม่มีกลุ่มอีเมลสำหรับบริษัทนี้</p>
              <p class="text-[10px] text-slate-400 mt-0.5">กดปุ่ม "เพิ่มกลุ่ม" เพื่อเริ่ม</p>
            </div>

            <div v-else class="space-y-2.5">
              <div
                v-for="(g, idx) in draftGroups"
                :key="idx"
                :class="[
                  'border rounded-lg p-3 space-y-2.5',
                  isAutoSeeded(g) ? 'border-amber-200 bg-amber-50/40' : 'border-slate-200 bg-slate-50/30',
                ]"
              >
                <!-- Auto-seeded banner -->
                <div
                  v-if="isAutoSeeded(g)"
                  class="flex items-start gap-2 px-2.5 py-1.5 -mx-1 -mt-1 rounded bg-amber-100/70 border border-amber-200 text-amber-800"
                >
                  <i class="pi pi-exclamation-triangle text-amber-600 text-xs mt-0.5" />
                  <div class="flex-1 text-[11px]">
                    <strong class="font-semibold">ตรวจสอบรายชื่อผู้รับ</strong> —
                    กลุ่มนี้ถูกใส่ไว้ให้อัตโนมัติจากธรรมเนียมทั่วไป
                    ไม่ได้มาจาก Excel ของโบรกเกอร์โดยตรง โปรดยืนยันความถูกต้องของอีเมลแต่ละรายการ
                  </div>
                  <button
                    type="button"
                    @click="g.notes = ''"
                    class="text-[10px] text-amber-700 hover:text-amber-900 hover:underline shrink-0 mt-0.5"
                    title="ทำเครื่องหมายว่าตรวจแล้ว"
                  >
                    ตรวจแล้ว
                  </button>
                </div>

                <div>
                  <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">
                    ชื่อกลุ่ม
                  </label>
                  <input
                    v-model="g.name"
                    type="text"
                    placeholder="เช่น AIA Health — Quotation Team"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-100 bg-white"
                  />
                </div>

                <div>
                  <div class="flex items-center justify-between mb-1">
                    <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500">
                      อีเมล <span class="text-rose-500">*</span>
                      <span class="ml-1 normal-case font-normal text-slate-400">(อีเมลทุกรายชื่อจะอยู่ใน TO ของอีเมลฉบับเดียว)</span>
                    </label>
                    <button
                      type="button"
                      @click="addEmailToGroup(g)"
                      class="text-[10px] text-blue-600 hover:text-blue-700 hover:underline flex items-center gap-1"
                    >
                      <i class="pi pi-plus text-[9px]" />
                      เพิ่มอีเมล
                    </button>
                  </div>
                  <div class="space-y-1.5">
                    <div
                      v-for="(_, eidx) in g.emails"
                      :key="eidx"
                      class="flex items-center gap-2"
                    >
                      <input
                        v-model="g.emails[eidx]"
                        type="email"
                        :placeholder="eidx === 0 ? 'primary@carrier.co.th' : 'extra-member@carrier.co.th'"
                        class="flex-1 px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-100 bg-white"
                      />
                      <button
                        type="button"
                        @click="removeEmailFromGroup(g, eidx)"
                        :disabled="g.emails.length <= 1 && !g.emails[eidx]"
                        class="text-slate-400 hover:text-rose-600 disabled:opacity-30 disabled:cursor-not-allowed p-1.5 rounded hover:bg-rose-50 transition"
                        title="ลบอีเมลนี้"
                      >
                        <i class="pi pi-times text-xs" />
                      </button>
                    </div>
                  </div>
                </div>

                <div>
                  <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">
                    แผนก
                  </label>
                  <select
                    v-model="g.department"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-100 bg-white"
                  >
                    <option v-for="d in DEPARTMENTS" :key="d" :value="d">
                      {{ DEPARTMENT_LABELS[d] }}
                    </option>
                  </select>
                </div>

                <div>
                  <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">
                    ประเภทประกันที่กลุ่มนี้รับ
                    <span class="ml-1 normal-case font-normal text-slate-400">(ไม่เลือก = รับทุกประเภท)</span>
                  </label>
                  <div class="flex flex-wrap gap-1.5">
                    <button
                      v-for="ty in INSURANCE_TYPES"
                      :key="ty"
                      type="button"
                      @click="toggleDraftType(g, ty)"
                      :class="[
                        'px-2.5 py-1 text-xs rounded-md border transition',
                        g.insuranceTypes.includes(ty)
                          ? 'bg-brand-50 border-brand-300 text-brand-700'
                          : 'bg-white border-slate-200 text-slate-500 hover:bg-slate-50',
                      ]"
                    >
                      {{ INSURANCE_TYPE_LABELS[ty] }}
                    </button>
                  </div>
                </div>

                <div class="flex items-center justify-between pt-1">
                  <div class="flex items-center gap-3 text-xs">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                      <input
                        v-model="g.isDefault"
                        type="checkbox"
                        class="w-3.5 h-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                      />
                      <span class="text-slate-600">เลือกอัตโนมัติ</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                      <input
                        v-model="g.active"
                        type="checkbox"
                        class="w-3.5 h-3.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                      />
                      <span class="text-slate-600">ใช้งาน</span>
                    </label>
                  </div>
                  <button
                    type="button"
                    @click="removeDraftGroup(idx)"
                    class="text-xs text-rose-600 hover:text-rose-700 hover:bg-rose-50 px-2 py-1 rounded transition flex items-center gap-1"
                  >
                    <i class="pi pi-trash text-[10px]" />
                    ลบกลุ่ม
                  </button>
                </div>
              </div>
            </div>
          </section>
        </form>

        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl shrink-0">
          <button
            type="button"
            @click="closeForm"
            class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white"
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
            <span>{{ isEdit ? t('carriers.dialog.save') : t('carriers.dialog.create') }}</span>
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
            {{ toggleTarget.active ? t('carriers.confirm.deactivateTitle') : t('carriers.confirm.activateTitle') }}
          </h3>
          <p class="text-sm text-slate-500 mt-1.5">
            {{ toggleTarget.active ? t('carriers.confirm.deactivateMsg') : t('carriers.confirm.activateMsg') }}
          </p>
          <div class="mt-3 p-3 bg-slate-50 border border-slate-100 rounded-lg text-sm">
            <div class="font-medium text-slate-900">{{ toggleTarget.name }}</div>
            <div class="text-xs text-slate-500 font-mono">{{ toggleTarget.code }} · {{ toggleTarget.oicLicense }}</div>
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
