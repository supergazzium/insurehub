<script setup lang="ts">
// Full-page customer view/edit (was CustomerDetailDrawer). Reads the id from
// the route (customers/:id/edit). Inline-edits every field via EditableField
// and lists the customer's policies. Same sections as the old drawer, laid
// out on a full page like the policy edit screen.

import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCustomerStore } from '../../stores/customers'
import { fetchPolicyList, type PolicyListRow } from '../../api/policies'
import EditableField from '../../components/EditableField.vue'
import AddressPicker from '../../components/AddressPicker.vue'
import DeleteConfirmDialog from '../../components/DeleteConfirmDialog.vue'
import { api, ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'
import { customerDisplayName, customerTypeLabel } from '../../utils/customerDisplay'

// EditableField uses the `value` as the option's stored form; `label` is what
// renders in the select. Keep every enum value the backend accepts here or
// the drawer will render the raw enum string next to the field.
const CUSTOMER_TYPE_OPTIONS = [
  { value: 'individual', label: 'บุคคลธรรมดา' },
  { value: 'foreign_individual', label: 'ชาวต่างชาติ' },
  { value: 'corporate', label: 'นิติบุคคล' },
]
// Gender is stored as single-letter codes (M/F) — matches the create form
// and the backend `in:M,F` rule. Older values were migrated in 2027_02_11.
const GENDER_OPTIONS = [
  { value: 'M', label: 'ชาย' },
  { value: 'F', label: 'หญิง' },
]
const MARITAL_OPTIONS = [
  { value: 'single', label: 'โสด' },
  { value: 'married', label: 'สมรส' },
  { value: 'divorced', label: 'หย่า' },
  { value: 'widowed', label: 'หม้าย' },
]
const YES_NO_OPTIONS = [
  { value: 'yes', label: 'ใช่' },
  { value: 'no', label: 'ไม่ใช่' },
]

const route = useRoute()
const router = useRouter()
// The customer id comes from the route param (customers/:id/edit).
const customerId = computed<string | null>(() => (route.params.id as string) ?? null)
function goBack(): void { router.push({ name: 'customers' }) }

const customerStore = useCustomerStore()
const loading = ref(false)
const errorMsg = ref<string | null>(null)
const policies = ref<PolicyListRow[]>([])
const policiesLoading = ref(false)

// ── Delete ────────────────────────────────────────────────────────────────
const showDelete = ref(false)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

async function doDelete(): Promise<void> {
  if (!customerId.value) return
  deleting.value = true
  deleteError.value = null
  try {
    await api.delete(`customers/${customerId.value}`)
    await customerStore.loadPage({})
    showDelete.value = false
    goBack()
  } catch (e: unknown) {
    deleteError.value = e instanceof ApiError ? e.message : 'ลบไม่สำเร็จ'
  } finally {
    deleting.value = false
  }
}

// Optimistic apply — patch a top-level or dotted-path key on the in-memory customer.
function apply(pathKey: string, v: unknown): void {
  if (!customer.value) return
  const parts = pathKey.split('.')
  let obj: Record<string, unknown> = customer.value as unknown as Record<string, unknown>
  for (let i = 0; i < parts.length - 1; i++) {
    const next = obj[parts[i]]
    if (next && typeof next === 'object') {
      obj = next as Record<string, unknown>
    } else {
      return
    }
  }
  obj[parts[parts.length - 1]] = v
}

const customer = computed(() => (customerId.value ? customerStore.getCustomer(customerId.value) : null))

// Which fields a customer shows depends on their type — mirrors the create
// page. Person types (individual + foreign) get name/identity/gender fields;
// corporate gets juristic name + tax id. Thai national ID is individual-only;
// passport is foreign-only.
const isIndividual = computed(() => customer.value?.customerType === 'individual')
const isForeign = computed(() => customer.value?.customerType === 'foreign_individual')
const isPerson = computed(() => isIndividual.value || isForeign.value)
const isCorporate = computed(() => customer.value?.customerType === 'corporate')

watch(
  () => customerId.value,
  async (id) => {
    if (!id) {
      policies.value = []
      return
    }
    loading.value = true
    errorMsg.value = null
    policies.value = []
    try {
      await customerStore.ensureDetail(id, true)
      policiesLoading.value = true
      const res = await fetchPolicyList({ customerId: id, perPage: 50 })
      policies.value = res.data
    } catch (e: unknown) {
      errorMsg.value = e instanceof Error ? e.message : 'โหลดข้อมูลลูกค้าไม่สำเร็จ'
    } finally {
      loading.value = false
      policiesLoading.value = false
    }
  },
  { immediate: true },
)

function fmtBaht(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 0 }).format(n)
}

function statusBadge(s: string): string {
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
  }[s] ?? 'bg-slate-100 text-slate-600'
}
</script>

<template>
  <div class="space-y-6 max-w-4xl">
    <header class="flex items-start justify-between gap-3 flex-wrap">
      <div v-if="customer">
        <div class="flex items-center gap-2 text-xs uppercase text-slate-400">
          <RouterLink :to="{ name: 'customers' }" class="hover:text-brand-600 normal-case">ลูกค้า</RouterLink>
          <span>/</span>
          <span class="font-mono">{{ customer.customerCode }}</span>
          <span>·</span>
          <span>{{ customerTypeLabel(customer.customerType) }}</span>
          <span v-if="customer.legacyId">· รหัสเดิม {{ customer.legacyId }}</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900 mt-1">
          {{ customerDisplayName(customer) || '—' }}
          <span v-if="customer.nickname" class="text-sm text-slate-500 ml-1">({{ customer.nickname }})</span>
        </h1>
        <div v-if="customer.firstNameEn || customer.lastNameEn" class="text-xs text-slate-500 mt-0.5">
          {{ customer.titleEn }} {{ customer.firstNameEn }} {{ customer.lastNameEn }}
        </div>
      </div>
      <div v-else class="text-slate-500 text-sm">กำลังโหลด…</div>
      <button type="button"
        class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100 flex items-center gap-1.5"
        @click="goBack">
        <i class="pi pi-arrow-left text-xs" /> กลับ
      </button>
    </header>

    <div v-if="errorMsg" class="p-4 bg-rose-50 border border-rose-200 rounded-lg text-rose-700 text-sm">
      {{ errorMsg }}
    </div>

    <div v-if="loading && !customer" class="card p-6 text-slate-500 text-sm">กำลังโหลด…</div>

    <template v-else-if="customer">
      <!-- Identity -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ประเภทและชื่อลูกค้า</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div><div class="text-xs text-slate-400">ประเภทลูกค้า</div>
            <EditableField entity="customers" :id="customer.id" field="customerType" type="select" :options="CUSTOMER_TYPE_OPTIONS" :value="customer.customerType" @update="v => apply('customerType', v)" /></div>
          <div><div class="text-xs text-slate-400">{{ isCorporate ? 'คำนำหน้า (นิติบุคคล)' : 'คำนำหน้า' }}</div>
            <EditableField entity="customers" :id="customer.id" field="titleTh" :value="customer.titleTh" @update="v => apply('titleTh', v)" /></div>
          <!-- Corporate: juristic name only -->
          <div v-if="isCorporate" class="md:col-span-2"><div class="text-xs text-slate-400">ชื่อนิติบุคคล</div>
            <EditableField entity="customers" :id="customer.id" field="juristicName" :value="customer.juristicName" @update="v => apply('juristicName', v)" /></div>
          <!-- Person: personal name fields -->
          <template v-if="isPerson">
            <div><div class="text-xs text-slate-400">{{ isForeign ? 'ชื่อ (First name)' : 'ชื่อ (ภาษาไทย)' }}</div>
              <EditableField entity="customers" :id="customer.id" field="firstName" :value="customer.firstName" @update="v => apply('firstName', v)" /></div>
            <div><div class="text-xs text-slate-400">{{ isForeign ? 'นามสกุล (Last name)' : 'นามสกุล (ภาษาไทย)' }}</div>
              <EditableField entity="customers" :id="customer.id" field="lastName" :value="customer.lastName" @update="v => apply('lastName', v)" /></div>
            <div><div class="text-xs text-slate-400">ชื่อเล่น</div>
              <EditableField entity="customers" :id="customer.id" field="nickname" :value="customer.nickname" @update="v => apply('nickname', v)" /></div>
            <div><div class="text-xs text-slate-400">คำนำหน้า (อังกฤษ)</div>
              <EditableField entity="customers" :id="customer.id" field="titleEn" :value="customer.titleEn" @update="v => apply('titleEn', v)" /></div>
            <div><div class="text-xs text-slate-400">ชื่อ (อังกฤษ)</div>
              <EditableField entity="customers" :id="customer.id" field="firstNameEn" :value="customer.firstNameEn" @update="v => apply('firstNameEn', v)" /></div>
            <div><div class="text-xs text-slate-400">นามสกุล (อังกฤษ)</div>
              <EditableField entity="customers" :id="customer.id" field="lastNameEn" :value="customer.lastNameEn" @update="v => apply('lastNameEn', v)" /></div>
          </template>
        </div>
      </section>

      <!-- Identity -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ข้อมูลตัวตน</h2>
        <!-- Corporate: only the tax id -->
        <div v-if="isCorporate" class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div><div class="text-xs text-slate-400">เลขประจำตัวผู้เสียภาษี</div>
            <EditableField entity="customers" :id="customer.id" field="taxId" :value="customer.taxId" @update="v => apply('taxId', v)" /></div>
        </div>
        <!-- Person: national ID (Thai) or passport (foreign) + demographics -->
        <div v-else class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <template v-if="isIndividual">
            <div><div class="text-xs text-slate-400">เลขบัตรประชาชน</div>
              <EditableField entity="customers" :id="customer.id" field="idCard" :value="customer.idCard" @update="v => apply('idCard', v)" /></div>
            <div><div class="text-xs text-slate-400">วันหมดอายุบัตร</div>
              <EditableField entity="customers" :id="customer.id" field="nationalIdExpiry" type="date" :value="customer.nationalIdExpiry" @update="v => apply('nationalIdExpiry', v)" /></div>
          </template>
          <template v-if="isForeign">
            <div><div class="text-xs text-slate-400">เลขที่หนังสือเดินทาง (Passport)</div>
              <EditableField entity="customers" :id="customer.id" field="passport" :value="customer.passport" @update="v => apply('passport', v)" /></div>
            <div><div class="text-xs text-slate-400">วันหมดอายุบัตร</div>
              <EditableField entity="customers" :id="customer.id" field="nationalIdExpiry" type="date" :value="customer.nationalIdExpiry" @update="v => apply('nationalIdExpiry', v)" /></div>
          </template>
          <div><div class="text-xs text-slate-400">วันเกิด</div>
            <EditableField entity="customers" :id="customer.id" field="birthDate" type="date" :value="customer.birthDate" @update="v => apply('birthDate', v)" /></div>
          <div><div class="text-xs text-slate-400">เพศ</div>
            <EditableField entity="customers" :id="customer.id" field="gender" type="select" :options="GENDER_OPTIONS" :value="customer.gender" @update="v => apply('gender', v)" /></div>
          <div><div class="text-xs text-slate-400">สถานภาพสมรส</div>
            <EditableField entity="customers" :id="customer.id" field="maritalStatus" type="select" :options="MARITAL_OPTIONS" :value="customer.maritalStatus" @update="v => apply('maritalStatus', v)" /></div>
          <div><div class="text-xs text-slate-400">{{ isForeign ? 'สัญชาติ (Nationality)' : 'สัญชาติ' }}</div>
            <EditableField entity="customers" :id="customer.id" field="nationality" :value="customer.nationality" @update="v => apply('nationality', v)" /></div>
          <div><div class="text-xs text-slate-400">เชื้อชาติ</div>
            <EditableField entity="customers" :id="customer.id" field="race" :value="customer.race" @update="v => apply('race', v)" /></div>
          <div><div class="text-xs text-slate-400">ศาสนา</div>
            <EditableField entity="customers" :id="customer.id" field="religion" :value="customer.religion" @update="v => apply('religion', v)" /></div>
        </div>
      </section>

      <!-- Occupation (persons only) -->
      <section v-if="isPerson" class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">อาชีพและรายได้</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div><div class="text-xs text-slate-400">อาชีพ</div>
            <EditableField entity="customers" :id="customer.id" field="occupation" :value="customer.occupation" @update="v => apply('occupation', v)" /></div>
          <div><div class="text-xs text-slate-400">ตำแหน่ง</div>
            <EditableField entity="customers" :id="customer.id" field="position" :value="customer.position" @update="v => apply('position', v)" /></div>
          <div class="md:col-span-2"><div class="text-xs text-slate-400">สถานที่ทำงาน</div>
            <EditableField entity="customers" :id="customer.id" field="employerName" :value="customer.employerName" @update="v => apply('employerName', v)" /></div>
          <div><div class="text-xs text-slate-400">รายได้ต่อเดือน</div>
            <EditableField entity="customers" :id="customer.id" field="monthlyIncome" type="currency" :value="customer.monthlyIncome" @update="v => apply('monthlyIncome', v)" /></div>
        </div>
      </section>

      <!-- Contact -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ช่องทางติดต่อ</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div><div class="text-xs text-slate-400">โทรศัพท์มือถือ</div>
            <EditableField entity="customers" :id="customer.id" field="phone" :value="customer.phone" @update="v => apply('phone', v)" /></div>
          <div><div class="text-xs text-slate-400">โทรศัพท์บ้าน</div>
            <EditableField entity="customers" :id="customer.id" field="telPhone" :value="customer.telPhone" @update="v => apply('telPhone', v)" /></div>
          <div><div class="text-xs text-slate-400">LINE ID</div>
            <EditableField entity="customers" :id="customer.id" field="lineId" :value="customer.lineId" @update="v => apply('lineId', v)" /></div>
          <div><div class="text-xs text-slate-400">Facebook</div>
            <EditableField entity="customers" :id="customer.id" field="facebookName" :value="customer.facebookName" @update="v => apply('facebookName', v)" /></div>
          <div><div class="text-xs text-slate-400">อีเมล</div>
            <EditableField entity="customers" :id="customer.id" field="email" :value="customer.email" @update="v => apply('email', v)" /></div>
          <div><div class="text-xs text-slate-400">อีเมล (สำรอง)</div>
            <EditableField entity="customers" :id="customer.id" field="email2" :value="customer.email2" @update="v => apply('email2', v)" /></div>
        </div>
      </section>

      <!-- Registered address -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ที่อยู่ตามทะเบียนบ้าน</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm mb-3">
          <div class="md:col-span-4"><div class="text-xs text-slate-400">ที่อยู่</div>
            <EditableField entity="customers" :id="customer.id" field="address" :value="customer.address" @update="v => apply('address', v)" /></div>
        </div>
        <!-- App `amphoe` is the district lookup; app `district` is the sub-district (tambon) lookup. -->
        <AddressPicker
          :key="`reg-${customer.id}`"
          entity="customers" :id="customer.id"
          :fields="{ province: 'province', district: 'amphoe', subDistrict: 'district', postcode: 'postcode' }"
          :province="customer.province" :district="customer.amphoe"
          :sub-district="customer.district" :postcode="customer.postcode"
          @update="(f, v) => apply(f, v)" />
      </section>

      <!-- Mailing address -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ที่อยู่จัดส่ง</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm mb-3">
          <div><div class="text-xs text-slate-400">ใช้ที่อยู่เดียวกับทะเบียนบ้าน</div>
            <EditableField entity="customers" :id="customer.id" field="mailingSameAsRegistered" type="select"
              :options="YES_NO_OPTIONS" :value="customer.mailingSameAsRegistered ? 'yes' : 'no'"
              @update="v => apply('mailingSameAsRegistered', v === 'yes')" /></div>
          <div class="md:col-span-3"><div class="text-xs text-slate-400">ที่อยู่</div>
            <EditableField entity="customers" :id="customer.id" field="mailing.address" :value="customer.mailing?.address ?? null" @update="v => apply('mailing.address', v)" /></div>
        </div>
        <template v-if="!customer.mailingSameAsRegistered">
          <AddressPicker
            :key="`mail-${customer.id}`"
            entity="customers" :id="customer.id"
            :fields="{ province: 'mailing.province', district: 'mailing.district', subDistrict: 'mailing.subDistrict', postcode: 'mailing.postcode' }"
            :province="customer.mailing?.province ?? null" :district="customer.mailing?.district ?? null"
            :sub-district="customer.mailing?.subDistrict ?? null" :postcode="customer.mailing?.postcode ?? null"
            @update="(f, v) => apply(f, v)" />
        </template>
        <p v-else class="text-xs text-slate-400">ใช้ที่อยู่ตามทะเบียนบ้าน — เปลี่ยน “ใช้ที่อยู่เดียวกับทะเบียนบ้าน” เป็น “ไม่” เพื่อระบุที่อยู่จัดส่งอื่น</p>
      </section>

      <!-- Contact person -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ผู้ติดต่อแทน</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div><div class="text-xs text-slate-400">ชื่อผู้ติดต่อแทน</div>
            <EditableField entity="customers" :id="customer.id" field="contactPerson.name" :value="customer.contactPerson?.name ?? null" @update="v => apply('contactPerson.name', v)" /></div>
          <div><div class="text-xs text-slate-400">ตำแหน่ง</div>
            <EditableField entity="customers" :id="customer.id" field="contactPerson.position" :value="customer.contactPerson?.position ?? null" @update="v => apply('contactPerson.position', v)" /></div>
          <div><div class="text-xs text-slate-400">เบอร์โทร</div>
            <EditableField entity="customers" :id="customer.id" field="contactPerson.phone" :value="customer.contactPerson?.phone ?? null" @update="v => apply('contactPerson.phone', v)" /></div>
          <div><div class="text-xs text-slate-400">อีเมล</div>
            <EditableField entity="customers" :id="customer.id" field="contactPerson.email" :value="customer.contactPerson?.email ?? null" @update="v => apply('contactPerson.email', v)" /></div>
        </div>
      </section>

      <!-- Assignment -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">การมอบหมาย</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div>
            <div class="text-xs text-slate-400">ตัวแทนที่ดูแล</div>
            <router-link v-if="customer.assignedAgentCode"
              :to="{ name: 'agents', query: { q: customer.assignedAgentCode } }"
              class="text-brand-600 hover:text-brand-800 hover:underline">
              {{ customer.assignedAgentCode }}<span v-if="customer.assignedAgentName" class="text-slate-500"> · {{ customer.assignedAgentName }}</span>
            </router-link>
            <span v-else class="text-slate-400">ยังไม่ได้มอบหมาย</span>
          </div>
          <div><div class="text-xs text-slate-400">สร้างโดย</div><div class="text-slate-900">{{ customer.createdByName || '—' }}</div></div>
          <div><div class="text-xs text-slate-400">วันที่ลงทะเบียน</div><div class="text-slate-900">{{ customer.registeredAt || '—' }}</div></div>
          <div><div class="text-xs text-slate-400">ติดต่อล่าสุด</div><div class="text-slate-900">{{ customer.lastContact || '—' }}</div></div>
          <div><div class="text-xs text-slate-400">กรมธรรม์ที่ใช้งาน</div><div class="font-medium text-slate-900">{{ customer.activePolicyCount }}</div></div>
          <div><div class="text-xs text-slate-400">กรมธรรม์ทั้งหมด</div><div class="text-slate-900">{{ customer.totalPolicyCount }}</div></div>
          <div><div class="text-xs text-slate-400">สถานะ</div>
            <span v-if="customer.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">ใช้งาน</span>
            <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">ไม่ใช้งาน</span>
          </div>
        </div>
      </section>

      <!-- KYC docs -->
      <section class="card p-5" v-if="customer.kycDocs && customer.kycDocs.length">
        <h2 class="font-semibold text-slate-900 mb-3">เอกสาร KYC <span class="text-slate-500 normal-case">({{ customer.kycDocs.length }})</span></h2>
        <div class="overflow-hidden rounded-lg border border-slate-200">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
              <tr>
                <th class="px-4 py-2 text-left">ประเภท</th>
                <th class="px-4 py-2 text-left">ไฟล์</th>
                <th class="px-4 py-2 text-left">วันที่อัปโหลด</th>
                <th class="px-4 py-2 text-left">การยืนยัน</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="d in customer.kycDocs" :key="d.id">
                <td class="px-4 py-2 text-slate-700">{{ d.type }}</td>
                <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ d.fileName }}</td>
                <td class="px-4 py-2 text-slate-700">{{ d.uploadedAt }}</td>
                <td class="px-4 py-2">
                  <span v-if="d.verified" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">ยืนยันแล้ว</span>
                  <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">รอตรวจสอบ</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Assignment history -->
      <section class="card p-5" v-if="customer.assignmentHistory && customer.assignmentHistory.length">
        <h2 class="font-semibold text-slate-900 mb-3">ประวัติการมอบหมาย <span class="text-slate-500 normal-case">({{ customer.assignmentHistory.length }})</span></h2>
        <div class="overflow-hidden rounded-lg border border-slate-200">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
              <tr>
                <th class="px-4 py-2 text-left">เมื่อ</th>
                <th class="px-4 py-2 text-left">จาก</th>
                <th class="px-4 py-2 text-left">ถึง</th>
                <th class="px-4 py-2 text-left">เหตุผล</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="h in customer.assignmentHistory" :key="h.id">
                <td class="px-4 py-2 text-slate-700">{{ h.at }}</td>
                <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ h.fromAgentId || '—' }}</td>
                <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ h.toAgentId || '—' }}</td>
                <td class="px-4 py-2 text-slate-700">{{ h.reason || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Policies for this customer -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">กรมธรรม์ของลูกค้ารายนี้ <span class="text-slate-500 font-normal">({{ policies.length }})</span></h2>
        <div v-if="policiesLoading" class="text-sm text-slate-500">กำลังโหลดกรมธรรม์…</div>
        <div v-else-if="!policies.length" class="text-sm text-slate-500">ไม่พบกรมธรรม์</div>
        <div v-else class="overflow-hidden rounded-lg border border-slate-200">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
              <tr>
                <th class="px-4 py-2 text-left">เลขที่ใบคำขอ</th>
                <th class="px-4 py-2 text-left">เลขกรมธรรม์</th>
                <th class="px-4 py-2 text-left">แบบประกัน / บริษัท</th>
                <th class="px-4 py-2 text-left">ตัวแทน</th>
                <th class="px-4 py-2 text-right">เบี้ย / ปี</th>
                <th class="px-4 py-2 text-left">วันเริ่มคุ้มครอง</th>
                <th class="px-4 py-2 text-left">สถานะ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="p in policies" :key="p.id" class="hover:bg-slate-50">
                <td class="px-4 py-2 font-mono text-xs">
                  <!-- Open policy detail in a NEW TAB so the operator
                       doesn't lose the customer context they're in.
                       Prev/next navigation between the customer's
                       policies would live in the policy drawer; the
                       quick win is a fresh tab per click. -->
                  <a v-if="p.applicationNo"
                    :href="`/insurehub/policies?open=${p.id}`"
                    target="_blank" rel="noopener"
                    class="text-brand-600 hover:text-brand-800 hover:underline inline-flex items-center gap-1">
                    {{ p.applicationNo }}
                    <i class="pi pi-external-link text-[10px] opacity-60" />
                  </a>
                  <span v-else class="text-slate-400">—</span>
                </td>
                <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ p.policyNo ?? '—' }}</td>
                <td class="px-4 py-2">
                  <router-link v-if="p.productId"
                    :to="{ name: 'products', query: { open: p.productId } }"
                    class="block text-brand-600 hover:text-brand-800 hover:underline truncate max-w-[200px]">
                    {{ p.productName ?? p.productCode }}
                  </router-link>
                  <span v-else class="block text-slate-900 truncate max-w-[200px]">{{ p.productName ?? p.productCode }}</span>
                  <router-link v-if="p.carrierId"
                    :to="{ name: 'carriers', query: { open: p.carrierId } }"
                    class="text-xs text-brand-600 hover:text-brand-800 hover:underline">
                    {{ p.carrierCode }}
                  </router-link>
                  <span v-else class="text-xs text-slate-500">{{ p.carrierCode }}</span>
                </td>
                <td class="px-4 py-2">
                  <router-link v-if="p.agentCode"
                    :to="{ name: 'agents', query: { q: p.agentCode } }"
                    class="text-brand-600 hover:text-brand-800 hover:underline">
                    {{ p.agentCode }}
                  </router-link>
                  <span v-else class="text-slate-400">—</span>
                </td>
                <td class="px-4 py-2 text-right text-slate-900">{{ fmtBaht(p.annualPremium) }}</td>
                <td class="px-4 py-2 text-slate-700">{{ fmtDate(p.effectiveDate) || '—' }}</td>
                <td class="px-4 py-2">
                  <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', statusBadge(p.status)]"
                    :title="p.status">{{ p.statusLabel || p.status }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Notes -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">บันทึก</h2>
        <div class="text-sm">
          <EditableField entity="customers" :id="customer.id" field="notes" type="textarea"
            :value="customer.notes" placeholder="ยังไม่มีบันทึก — คลิกเพื่อเพิ่ม"
            @update="v => apply('notes', v)" />
        </div>
      </section>

      <!-- Footer -->
      <footer class="border-t border-slate-200 pt-4 flex items-center justify-between">
        <div class="text-xs text-slate-400">
          คลิกที่ช่องใดก็ได้เพื่อแก้ไข · Enter บันทึก, Esc ยกเลิก
        </div>
        <button type="button"
          class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 text-sm flex items-center gap-1.5"
          @click="showDelete = true">
          <i class="pi pi-trash text-xs" /> ลบลูกค้า
        </button>
      </footer>
    </template>

    <DeleteConfirmDialog
      v-if="customer"
      :open="showDelete"
      :label="`ลูกค้า ${customer.customerCode}`"
      :confirm-token="customer.customerCode"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
      @cancel="showDelete = false"
    />
  </div>
</template>
