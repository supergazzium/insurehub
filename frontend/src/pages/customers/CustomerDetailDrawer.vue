<script setup lang="ts">
// Full-detail drawer for a customer. Fetches:
//   - Full customer record via customerStore.ensureDetail(id)
//   - This customer's policies via fetchPolicyList({ customerId })
// Displays: profile, national ID + passport, occupation + income,
// contact info, registered address, mailing address, contact person,
// KYC docs, assignment history, and the customer's policies.

import { computed, ref, watch } from 'vue'
import { useCustomerStore } from '../../stores/customers'
import { fetchPolicyList, type PolicyListRow } from '../../api/policies'
import EditableField from '../../components/EditableField.vue'
import DeleteConfirmDialog from '../../components/DeleteConfirmDialog.vue'
import { api, ApiError } from '../../api/client'

const CUSTOMER_TYPE_OPTIONS = [
  { value: 'individual', label: 'Individual' },
  { value: 'corporate', label: 'Corporate' },
]
const GENDER_OPTIONS = [
  { value: 'ชาย', label: 'ชาย' },
  { value: 'หญิง', label: 'หญิง' },
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
]
const MARITAL_OPTIONS = [
  { value: 'single', label: 'Single' },
  { value: 'married', label: 'Married' },
  { value: 'divorced', label: 'Divorced' },
  { value: 'widowed', label: 'Widowed' },
]

const props = defineProps<{ customerId: string | null }>()
const emit = defineEmits<{ (e: 'close'): void }>()

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
  if (!props.customerId) return
  deleting.value = true
  deleteError.value = null
  try {
    await api.delete(`customers/${props.customerId}`)
    await customerStore.loadPage({})
    showDelete.value = false
    emit('close')
  } catch (e: unknown) {
    deleteError.value = e instanceof ApiError ? e.message : 'Delete failed'
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

const customer = computed(() => (props.customerId ? customerStore.getCustomer(props.customerId) : null))

watch(
  () => props.customerId,
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
      errorMsg.value = e instanceof Error ? e.message : 'Failed to load customer detail.'
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
  <div v-if="props.customerId" class="fixed inset-0 bg-slate-900/40 flex justify-end z-50" @click.self="emit('close')">
    <div class="bg-white w-full max-w-4xl h-full overflow-y-auto shadow-xl flex flex-col">
      <header class="px-6 py-4 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white z-10">
        <div v-if="customer">
          <div class="flex items-center gap-2 text-xs uppercase text-slate-400">
            <span class="font-mono">{{ customer.customerCode }}</span>
            <span>·</span>
            <span>{{ customer.customerType }}</span>
            <span v-if="customer.legacyId">· legacy id {{ customer.legacyId }}</span>
          </div>
          <div class="text-lg font-semibold text-slate-900 mt-1">
            {{ customer.titleTh }} {{ customer.firstName }} {{ customer.lastName }}
            <span v-if="customer.nickname" class="text-sm text-slate-500 ml-1">({{ customer.nickname }})</span>
          </div>
          <div v-if="customer.firstNameEn || customer.lastNameEn" class="text-xs text-slate-500 mt-0.5">
            {{ customer.titleEn }} {{ customer.firstNameEn }} {{ customer.lastNameEn }}
          </div>
        </div>
        <div v-else class="text-slate-500">Loading…</div>
        <button class="text-slate-400 hover:text-slate-700 p-2" @click="emit('close')">
          <i class="pi pi-times" />
        </button>
      </header>

      <div v-if="errorMsg" class="m-6 p-4 bg-rose-50 border border-rose-200 rounded-lg text-rose-700 text-sm">
        {{ errorMsg }}
      </div>

      <div v-if="customer" class="flex-1 p-6 space-y-6">
        <!-- Identity -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Name &amp; type</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div><div class="text-xs text-slate-400">Customer type</div>
              <EditableField entity="customers" :id="customer.id" field="customerType" type="select" :options="CUSTOMER_TYPE_OPTIONS" :value="customer.customerType" @update="v => apply('customerType', v)" /></div>
            <div><div class="text-xs text-slate-400">Title (Thai)</div>
              <EditableField entity="customers" :id="customer.id" field="titleTh" :value="customer.titleTh" @update="v => apply('titleTh', v)" /></div>
            <div><div class="text-xs text-slate-400">First name</div>
              <EditableField entity="customers" :id="customer.id" field="firstName" :value="customer.firstName" @update="v => apply('firstName', v)" /></div>
            <div><div class="text-xs text-slate-400">Last name</div>
              <EditableField entity="customers" :id="customer.id" field="lastName" :value="customer.lastName" @update="v => apply('lastName', v)" /></div>
            <div><div class="text-xs text-slate-400">Nickname</div>
              <EditableField entity="customers" :id="customer.id" field="nickname" :value="customer.nickname" @update="v => apply('nickname', v)" /></div>
            <div><div class="text-xs text-slate-400">Title (English)</div>
              <EditableField entity="customers" :id="customer.id" field="titleEn" :value="customer.titleEn" @update="v => apply('titleEn', v)" /></div>
            <div><div class="text-xs text-slate-400">First name (EN)</div>
              <EditableField entity="customers" :id="customer.id" field="firstNameEn" :value="customer.firstNameEn" @update="v => apply('firstNameEn', v)" /></div>
            <div><div class="text-xs text-slate-400">Last name (EN)</div>
              <EditableField entity="customers" :id="customer.id" field="lastNameEn" :value="customer.lastNameEn" @update="v => apply('lastNameEn', v)" /></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Juristic name</div>
              <EditableField entity="customers" :id="customer.id" field="juristicName" :value="customer.juristicName" @update="v => apply('juristicName', v)" /></div>
          </div>
        </section>

        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Identity</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div><div class="text-xs text-slate-400">ID card</div>
              <EditableField entity="customers" :id="customer.id" field="idCard" :value="customer.idCard" @update="v => apply('idCard', v)" /></div>
            <div><div class="text-xs text-slate-400">ID expiry</div>
              <EditableField entity="customers" :id="customer.id" field="nationalIdExpiry" type="date" :value="customer.nationalIdExpiry" @update="v => apply('nationalIdExpiry', v)" /></div>
            <div><div class="text-xs text-slate-400">Passport</div>
              <EditableField entity="customers" :id="customer.id" field="passport" :value="customer.passport" @update="v => apply('passport', v)" /></div>
            <div><div class="text-xs text-slate-400">Tax ID</div>
              <EditableField entity="customers" :id="customer.id" field="taxId" :value="customer.taxId" @update="v => apply('taxId', v)" /></div>
            <div><div class="text-xs text-slate-400">Birth date</div>
              <EditableField entity="customers" :id="customer.id" field="birthDate" type="date" :value="customer.birthDate" @update="v => apply('birthDate', v)" /></div>
            <div><div class="text-xs text-slate-400">Gender</div>
              <EditableField entity="customers" :id="customer.id" field="gender" type="select" :options="GENDER_OPTIONS" :value="customer.gender" @update="v => apply('gender', v)" /></div>
            <div><div class="text-xs text-slate-400">Marital</div>
              <EditableField entity="customers" :id="customer.id" field="maritalStatus" type="select" :options="MARITAL_OPTIONS" :value="customer.maritalStatus" @update="v => apply('maritalStatus', v)" /></div>
            <div><div class="text-xs text-slate-400">Nationality</div>
              <EditableField entity="customers" :id="customer.id" field="nationality" :value="customer.nationality" @update="v => apply('nationality', v)" /></div>
            <div><div class="text-xs text-slate-400">Religion</div>
              <EditableField entity="customers" :id="customer.id" field="religion" :value="customer.religion" @update="v => apply('religion', v)" /></div>
          </div>
        </section>

        <!-- Occupation -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Occupation &amp; income</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div><div class="text-xs text-slate-400">Occupation</div>
              <EditableField entity="customers" :id="customer.id" field="occupation" :value="customer.occupation" @update="v => apply('occupation', v)" /></div>
            <div><div class="text-xs text-slate-400">Position</div>
              <EditableField entity="customers" :id="customer.id" field="position" :value="customer.position" @update="v => apply('position', v)" /></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Employer</div>
              <EditableField entity="customers" :id="customer.id" field="employerName" :value="customer.employerName" @update="v => apply('employerName', v)" /></div>
            <div><div class="text-xs text-slate-400">Monthly income</div>
              <EditableField entity="customers" :id="customer.id" field="monthlyIncome" type="currency" :value="customer.monthlyIncome" @update="v => apply('monthlyIncome', v)" /></div>
          </div>
        </section>

        <!-- Contact -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Contact</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div><div class="text-xs text-slate-400">Phone</div>
              <EditableField entity="customers" :id="customer.id" field="phone" :value="customer.phone" @update="v => apply('phone', v)" /></div>
            <div><div class="text-xs text-slate-400">Line ID</div>
              <EditableField entity="customers" :id="customer.id" field="lineId" :value="customer.lineId" @update="v => apply('lineId', v)" /></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Email</div>
              <EditableField entity="customers" :id="customer.id" field="email" :value="customer.email" @update="v => apply('email', v)" /></div>
          </div>
        </section>

        <!-- Registered address -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Registered address</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Address</div>
              <EditableField entity="customers" :id="customer.id" field="address" :value="customer.address" @update="v => apply('address', v)" /></div>
            <div><div class="text-xs text-slate-400">Sub-district</div>
              <EditableField entity="customers" :id="customer.id" field="district" :value="customer.district" @update="v => apply('district', v)" /></div>
            <div><div class="text-xs text-slate-400">Amphoe / District</div>
              <EditableField entity="customers" :id="customer.id" field="amphoe" :value="customer.amphoe" @update="v => apply('amphoe', v)" /></div>
            <div><div class="text-xs text-slate-400">Province</div>
              <EditableField entity="customers" :id="customer.id" field="province" :value="customer.province" @update="v => apply('province', v)" /></div>
            <div><div class="text-xs text-slate-400">Postcode</div>
              <EditableField entity="customers" :id="customer.id" field="postcode" :value="customer.postcode" @update="v => apply('postcode', v)" /></div>
          </div>
        </section>

        <!-- Mailing address -->
        <section v-if="!customer.mailingSameAsRegistered && customer.mailing && (customer.mailing.address || customer.mailing.province)">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Mailing address</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Address</div><div class="text-slate-900">{{ customer.mailing.address || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Sub-district</div><div class="text-slate-900">{{ customer.mailing.subDistrict || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">District</div><div class="text-slate-900">{{ customer.mailing.district || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Province</div><div class="text-slate-900">{{ customer.mailing.province || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Postcode</div><div class="text-slate-900">{{ customer.mailing.postcode || '—' }}</div></div>
          </div>
        </section>

        <!-- Corporate contact -->
        <section v-if="customer.contactPerson && (customer.contactPerson.name || customer.contactPerson.email || customer.contactPerson.phone)">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Contact person</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">Name</div><div class="text-slate-900">{{ customer.contactPerson.name || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Position</div><div class="text-slate-900">{{ customer.contactPerson.position || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Phone</div><div class="text-slate-900">{{ customer.contactPerson.phone || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Email</div><div class="text-slate-900">{{ customer.contactPerson.email || '—' }}</div></div>
          </div>
        </section>

        <!-- Assignment -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Assignment</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">Assigned agent</div><div class="text-slate-900">{{ customer.assignedAgentId || 'unassigned' }}</div></div>
            <div><div class="text-xs text-slate-400">Created by</div><div class="text-slate-900">{{ customer.createdByAgentId || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Registered</div><div class="text-slate-900">{{ customer.registeredAt || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Last contact</div><div class="text-slate-900">{{ customer.lastContact || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Active policies</div><div class="font-medium text-slate-900">{{ customer.activePolicyCount }}</div></div>
            <div><div class="text-xs text-slate-400">Total policies</div><div class="text-slate-900">{{ customer.totalPolicyCount }}</div></div>
            <div><div class="text-xs text-slate-400">Status</div>
              <span v-if="customer.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">active</span>
              <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">inactive</span>
            </div>
          </div>
        </section>

        <!-- KYC docs -->
        <section v-if="customer.kycDocs && customer.kycDocs.length">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">
            KYC documents <span class="text-slate-500 normal-case">({{ customer.kycDocs.length }})</span>
          </h3>
          <div class="card overflow-hidden">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-4 py-2 text-left">Type</th>
                  <th class="px-4 py-2 text-left">File</th>
                  <th class="px-4 py-2 text-left">Uploaded</th>
                  <th class="px-4 py-2 text-left">Verified</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="d in customer.kycDocs" :key="d.id">
                  <td class="px-4 py-2 text-slate-700">{{ d.type }}</td>
                  <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ d.fileName }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ d.uploadedAt }}</td>
                  <td class="px-4 py-2">
                    <span v-if="d.verified" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">verified</span>
                    <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">pending</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Assignment history -->
        <section v-if="customer.assignmentHistory && customer.assignmentHistory.length">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">
            Assignment history <span class="text-slate-500 normal-case">({{ customer.assignmentHistory.length }})</span>
          </h3>
          <div class="card overflow-hidden">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-4 py-2 text-left">At</th>
                  <th class="px-4 py-2 text-left">From</th>
                  <th class="px-4 py-2 text-left">To</th>
                  <th class="px-4 py-2 text-left">Reason</th>
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
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">
            Policies for this customer
            <span class="text-slate-500 normal-case">({{ policies.length }})</span>
          </h3>
          <div v-if="policiesLoading" class="card p-4 text-sm text-slate-500">Loading policies…</div>
          <div v-else-if="!policies.length" class="card p-4 text-sm text-slate-500">No policies found</div>
          <div v-else class="card overflow-hidden">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-4 py-2 text-left">Application</th>
                  <th class="px-4 py-2 text-left">Policy no</th>
                  <th class="px-4 py-2 text-left">Product / Carrier</th>
                  <th class="px-4 py-2 text-left">Agent</th>
                  <th class="px-4 py-2 text-right">Premium / yr</th>
                  <th class="px-4 py-2 text-left">Effective</th>
                  <th class="px-4 py-2 text-left">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="p in policies" :key="p.id">
                  <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ p.applicationNo ?? '—' }}</td>
                  <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ p.policyNo ?? '—' }}</td>
                  <td class="px-4 py-2">
                    <div class="text-slate-900 truncate max-w-[200px]">{{ p.productName ?? p.productCode }}</div>
                    <div class="text-xs text-slate-500">{{ p.carrierCode }}</div>
                  </td>
                  <td class="px-4 py-2 text-slate-700">{{ p.agentCode }}</td>
                  <td class="px-4 py-2 text-right text-slate-900">{{ fmtBaht(p.annualPremium) }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ p.effectiveDate ?? '—' }}</td>
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
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Notes</h3>
          <div class="card p-4 text-sm">
            <EditableField entity="customers" :id="customer.id" field="notes" type="textarea"
              :value="customer.notes" placeholder="ยังไม่มีบันทึก — คลิกเพื่อเพิ่ม"
              @update="v => apply('notes', v)" />
          </div>
        </section>

        <div v-if="loading" class="text-center text-slate-500 py-4">Loading…</div>
      </div>

      <!-- Footer -->
      <footer v-if="customer" class="border-t border-slate-200 px-6 py-3 flex items-center justify-between sticky bottom-0 bg-white">
        <div class="text-xs text-slate-400">
          Click any field to edit · Enter saves, Esc cancels
        </div>
        <button type="button"
          class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 text-sm flex items-center gap-1.5"
          @click="showDelete = true">
          <i class="pi pi-trash text-xs" /> Delete
        </button>
      </footer>
    </div>

    <DeleteConfirmDialog
      v-if="customer"
      :open="showDelete"
      :label="`customer ${customer.customerCode}`"
      :confirm-token="customer.customerCode"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
      @cancel="showDelete = false"
    />
  </div>
</template>
