<script setup lang="ts">
// Full-detail drawer for a single policy. Fetches via
// policyStore.ensureDetail(id) → GET /api/v1/policies/{id}, then renders
// every block the PolicyResource ships: overview, premium, commission,
// installment, WHT, riders, beneficiaries, motor, property, mailing,
// cancellation, payments, rebate, data-quality flags.

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePolicyStore } from '../../stores/policies'
import EditableField from '../../components/EditableField.vue'
import DeleteConfirmDialog from '../../components/DeleteConfirmDialog.vue'
import { api, ApiError } from '../../api/client'
import { CURRENT_STATUSES } from '../../utils/policyStatus'

const { t } = useI18n()

// C-7 — status options come from CURRENT_STATUSES so a new code lands
// in one place. Click-to-edit on `status` writes via PATCH which the
// backend `in:` validator + PolicyEventController transition matrix
// gate — a mis-picked value returns 422.
const POLICY_STATUS_OPTIONS = computed(() =>
  CURRENT_STATUSES.map((code) => ({ value: code, label: t(`policies.status.${code}`) })),
)

const NEW_OR_RENEW_OPTIONS = [
  { value: 'new', label: 'New' },
  { value: 'renew', label: 'Renew' },
]

const PREMIUM_MODE_OPTIONS = [
  { value: 'monthly', label: 'Monthly' },
  { value: 'quarterly', label: 'Quarterly' },
  { value: 'semiannual', label: 'Semiannual' },
  { value: 'annual', label: 'Annual' },
  { value: 'single', label: 'Single' },
]

// Local helper: patch a field and update the in-memory policy object so the
// drawer re-renders without a full refetch. Kept generic so any nested key
// can be updated.
function apply(pathKey: string, v: unknown): void {
  if (!policy.value) return
  const parts = pathKey.split('.')
  let obj: Record<string, unknown> = policy.value as unknown as Record<string, unknown>
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

// ── Delete ────────────────────────────────────────────────────────────────
const showDelete = ref(false)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

async function doDelete(): Promise<void> {
  if (!props.policyId) return
  deleting.value = true
  deleteError.value = null
  try {
    await api.delete(`policies/${props.policyId}`)
    // Refresh the list & close
    await policyStore.loadPage({})
    showDelete.value = false
    emit('close')
  } catch (e: unknown) {
    deleteError.value = e instanceof ApiError ? e.message : 'Delete failed'
  } finally {
    deleting.value = false
  }
}

const props = defineProps<{ policyId: string | null }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const policyStore = usePolicyStore()
const loading = ref(false)
const errorMsg = ref<string | null>(null)

const policy = computed(() => (props.policyId ? policyStore.getPolicy(props.policyId) : null))

// Refetch whenever the policyId changes to a truthy value.
watch(
  () => props.policyId,
  async (id) => {
    if (!id) return
    loading.value = true
    errorMsg.value = null
    try {
      // Force = true so we always pull the fresh detail shape (list rows are lean).
      await policyStore.ensureDetail(id, true)
    } catch (e: unknown) {
      errorMsg.value = e instanceof Error ? e.message : 'Failed to load policy detail.'
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

function fmtBaht(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 2 }).format(n)
}

function fmtPct(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  // Rates in source can be either 0..1 or 0..100. Assume 0..1 if <= 1.
  const pct = n <= 1 ? n * 100 : n
  return pct.toFixed(2) + '%'
}

function fmtDate(s: string | null | undefined): string {
  return s || '—'
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
  <div v-if="props.policyId" class="fixed inset-0 bg-slate-900/40 flex justify-end z-50" @click.self="emit('close')">
    <div class="bg-white w-full max-w-4xl h-full overflow-y-auto shadow-xl flex flex-col">
      <!-- Header -->
      <header class="px-6 py-4 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white z-10">
        <div v-if="policy">
          <div class="flex items-center gap-2 text-xs uppercase text-slate-400">
            <span class="font-mono">{{ policy.applicationNo ?? '—' }}</span>
            <span>·</span>
            <span :class="['inline-flex px-2 py-0.5 rounded-md text-[10px] font-medium', statusBadge(policy.status)]"
              :title="policy.status">{{ policy.statusLabel || policy.status }}</span>
          </div>
          <div class="text-lg font-semibold text-slate-900 mt-1 font-mono">{{ policy.policyNo ?? '(no policy number)' }}</div>
        </div>
        <div v-else class="text-slate-500">Loading…</div>
        <div class="flex items-center gap-2">
          <RouterLink v-if="policy" :to="{ name: 'policy-edit', params: { id: policy.id } }"
            class="px-3 py-1.5 rounded-md bg-brand-600 text-white text-xs hover:bg-brand-700 flex items-center gap-1.5">
            <i class="pi pi-pencil text-[10px]" /> {{ $t('policyEdit.openFullEditor') }}
          </RouterLink>
          <button class="text-slate-400 hover:text-slate-700 p-2" @click="emit('close')">
            <i class="pi pi-times" />
          </button>
        </div>
      </header>

      <div v-if="errorMsg" class="m-6 p-4 bg-rose-50 border border-rose-200 rounded-lg text-rose-700 text-sm">
        {{ errorMsg }}
      </div>

      <div v-if="policy" class="flex-1 p-6 space-y-6">
        <!-- Overview + IDs (editable) -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Overview</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div><div class="text-xs text-slate-400">Application no</div>
              <EditableField entity="policies" :id="policy.id" field="applicationNo" :value="policy.applicationNo" @update="v => apply('applicationNo', v)" /></div>
            <div><div class="text-xs text-slate-400">Policy no</div>
              <EditableField entity="policies" :id="policy.id" field="policyNo" :value="policy.policyNo" @update="v => apply('policyNo', v)" /></div>
            <div><div class="text-xs text-slate-400">Quote no</div>
              <EditableField entity="policies" :id="policy.id" field="quoteNo" :value="policy.quoteNo" @update="v => apply('quoteNo', v)" /></div>
            <div><div class="text-xs text-slate-400">Notion no</div>
              <EditableField entity="policies" :id="policy.id" field="notionNo" :value="policy.notionNo" @update="v => apply('notionNo', v)" /></div>
            <div><div class="text-xs text-slate-400">App date</div>
              <EditableField entity="policies" :id="policy.id" field="appDate" type="date" :value="policy.appDate" @update="v => apply('appDate', v)" /></div>
            <div><div class="text-xs text-slate-400">Create date</div>
              <EditableField entity="policies" :id="policy.id" field="createDate" type="date" :value="policy.createDate" @update="v => apply('createDate', v)" /></div>
            <div><div class="text-xs text-slate-400">Effective</div>
              <EditableField entity="policies" :id="policy.id" field="effectiveDate" type="date" :value="policy.effectiveDate" @update="v => apply('effectiveDate', v)" /></div>
            <div><div class="text-xs text-slate-400">Expiry</div>
              <EditableField entity="policies" :id="policy.id" field="expiryDate" type="date" :value="policy.expiryDate" @update="v => apply('expiryDate', v)" /></div>
            <div><div class="text-xs text-slate-400">Issue date</div>
              <EditableField entity="policies" :id="policy.id" field="issueDate" type="date" :value="policy.issueDate" @update="v => apply('issueDate', v)" /></div>
            <div><div class="text-xs text-slate-400">Cancel date</div>
              <EditableField entity="policies" :id="policy.id" field="cancelDate" type="date" :value="policy.cancelDate" @update="v => apply('cancelDate', v)" /></div>
            <div><div class="text-xs text-slate-400">Period paid end</div>
              <div class="text-slate-500">{{ fmtDate(policy.periodPaidEnd) }}</div></div>
            <div><div class="text-xs text-slate-400">Policy end</div>
              <div class="text-slate-500">{{ fmtDate(policy.policyEnd) }}</div></div>
            <div><div class="text-xs text-slate-400">Policy year</div>
              <EditableField entity="policies" :id="policy.id" field="policyYear" type="number" :value="policy.policyYear" @update="v => apply('policyYear', v)" /></div>
            <div><div class="text-xs text-slate-400">Act year</div>
              <EditableField entity="policies" :id="policy.id" field="actYear" type="number" :value="policy.actYear" @update="v => apply('actYear', v)" /></div>
            <div><div class="text-xs text-slate-400">New / Renew</div>
              <EditableField entity="policies" :id="policy.id" field="newOrRenew" type="select" :options="NEW_OR_RENEW_OPTIONS" :value="policy.newOrRenew" @update="v => apply('newOrRenew', v)" /></div>
            <div><div class="text-xs text-slate-400">Status</div>
              <EditableField entity="policies" :id="policy.id" field="status" type="select" :options="POLICY_STATUS_OPTIONS" :value="policy.status" @update="v => apply('status', v)" /></div>
            <div><div class="text-xs text-slate-400">Premium mode</div>
              <EditableField entity="policies" :id="policy.id" field="premiumMode" type="select" :options="PREMIUM_MODE_OPTIONS" :value="policy.premiumMode" @update="v => apply('premiumMode', v)" /></div>
            <div><div class="text-xs text-slate-400">Coverage</div>
              <EditableField entity="policies" :id="policy.id" field="coverage" type="currency" :value="policy.coverage" @update="v => apply('coverage', v)" /></div>
            <div><div class="text-xs text-slate-400">Annual premium</div>
              <EditableField entity="policies" :id="policy.id" field="annualPremium" type="currency" :value="policy.annualPremium" @update="v => apply('annualPremium', v)" /></div>
          </div>
        </section>

        <!-- Premium breakdown -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Premium</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">Coverage</div><div class="font-medium text-slate-900">{{ fmtBaht(policy.coverage) }}</div></div>
            <div><div class="text-xs text-slate-400">Annual premium</div><div class="font-medium text-slate-900">{{ fmtBaht(policy.annualPremium) }}</div></div>
            <div><div class="text-xs text-slate-400">Main premium</div><div class="text-slate-900">{{ fmtBaht(policy.premium?.main) }}</div></div>
            <div><div class="text-xs text-slate-400">Net premium</div><div class="text-slate-900">{{ fmtBaht(policy.premium?.net) }}</div></div>
            <div><div class="text-xs text-slate-400">Duty stamp</div><div class="text-slate-900">{{ fmtBaht(policy.premium?.dutyStamp) }}</div></div>
            <div><div class="text-xs text-slate-400">VAT</div><div class="text-slate-900">{{ fmtBaht(policy.premium?.vat) }}</div></div>
            <div><div class="text-xs text-slate-400">Total paid</div><div class="font-medium text-slate-900">{{ fmtBaht(policy.premium?.totalPaid) }}</div></div>
            <div><div class="text-xs text-slate-400">Net customer paid</div><div class="text-slate-900">{{ fmtBaht(policy.premium?.netCustomerPaid) }}</div></div>
          </div>
          <div v-if="policy.premium?.check === 'mismatch'"
            class="mt-2 text-xs bg-amber-50 border border-amber-200 text-amber-700 rounded-lg p-2 flex items-center gap-2">
            <i class="pi pi-exclamation-triangle" />
            Premium components don't reconcile with total paid (|Δ| &gt; 1 THB) — flagged by importer.
          </div>
        </section>

        <!-- Main commission -->
        <section v-if="policy.mainCommission">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Main-product commission</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">InH rate</div><div class="text-slate-900">{{ fmtPct(policy.mainCommission.rateInh) }}</div></div>
            <div><div class="text-xs text-slate-400">InH amount</div><div class="text-slate-900">{{ fmtBaht(policy.mainCommission.amtInh) }}</div></div>
            <div><div class="text-xs text-slate-400">Agent rate</div><div class="text-slate-900">{{ fmtPct(policy.mainCommission.rateAg) }}</div></div>
            <div><div class="text-xs text-slate-400">Agent amount</div><div class="text-slate-900">{{ fmtBaht(policy.mainCommission.amtAg) }}</div></div>
          </div>
        </section>

        <!-- Installment / payment terms -->
        <section v-if="policy.installment">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Installment</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">Term</div><div class="text-slate-900">{{ policy.installment.term || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Type of paid</div><div class="text-slate-900">{{ policy.installment.typeOfPaid || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Type note</div><div class="text-slate-900">{{ policy.installment.typeOfPaidNote || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Finance company</div><div class="text-slate-900">{{ policy.installment.financeCompany || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">First due</div><div class="text-slate-900">{{ fmtBaht(policy.installment.firstDueAmount) }} — {{ fmtDate(policy.installment.firstDueDate) }}</div></div>
            <div><div class="text-xs text-slate-400">Next due</div><div class="text-slate-900">{{ fmtBaht(policy.installment.nextDueAmount) }}</div></div>
            <div><div class="text-xs text-slate-400">Last due date</div><div class="text-slate-900">{{ fmtDate(policy.installment.lastDueDate) }}</div></div>
            <div><div class="text-xs text-slate-400">Front-end fee</div><div class="text-slate-900">{{ fmtBaht(policy.installment.frontEndFee) }}</div></div>
            <div><div class="text-xs text-slate-400">Discount</div><div class="text-slate-900">{{ fmtBaht(policy.installment.discountAmount) }}</div></div>
            <div><div class="text-xs text-slate-400">Credit-card fee</div><div class="text-slate-900">{{ fmtPct(policy.installment.creditCardFee) }}</div></div>
            <div><div class="text-xs text-slate-400">Subsidy from agent</div><div class="text-slate-900">{{ fmtBaht(policy.installment.subsidyFromAgent) }}</div></div>
            <div><div class="text-xs text-slate-400">Subsidy to finance</div><div class="text-slate-900">{{ fmtBaht(policy.installment.subsidyToFinance) }}</div></div>
          </div>
        </section>

        <!-- WHT -->
        <section v-if="policy.wht && (policy.wht.status || policy.wht.amount !== null)">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Withholding tax</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">Status</div><div class="text-slate-900">{{ policy.wht.status || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Amount</div><div class="text-slate-900">{{ fmtBaht(policy.wht.amount) }}</div></div>
          </div>
        </section>

        <!-- Riders -->
        <section v-if="policy.riders && policy.riders.length">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">
            Riders <span class="text-slate-500 normal-case">({{ policy.riders.length }})</span>
          </h3>
          <div class="card overflow-hidden">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-4 py-2 text-left">Slot</th>
                  <th class="px-4 py-2 text-left">Product</th>
                  <th class="px-4 py-2 text-right">Premium</th>
                  <th class="px-4 py-2 text-right">InH rate</th>
                  <th class="px-4 py-2 text-right">InH amt</th>
                  <th class="px-4 py-2 text-right">AG rate</th>
                  <th class="px-4 py-2 text-right">AG amt</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="(r, i) in policy.riders" :key="r.id ?? i">
                  <td class="px-4 py-2 text-slate-600">{{ r.slot ?? '—' }}</td>
                  <td class="px-4 py-2 text-slate-900">{{ r.name }}</td>
                  <td class="px-4 py-2 text-right">{{ fmtBaht(r.premium) }}</td>
                  <td class="px-4 py-2 text-right">{{ fmtPct(r.commission?.rateInh) }}</td>
                  <td class="px-4 py-2 text-right">{{ fmtBaht(r.commission?.amtInh) }}</td>
                  <td class="px-4 py-2 text-right">{{ fmtPct(r.commission?.rateAg) }}</td>
                  <td class="px-4 py-2 text-right">{{ fmtBaht(r.commission?.amtAg) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Beneficiaries -->
        <section v-if="policy.beneficiaries && policy.beneficiaries.length">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">
            Beneficiaries <span class="text-slate-500 normal-case">({{ policy.beneficiaries.length }})</span>
          </h3>
          <div class="card overflow-hidden">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-4 py-2 text-left">Slot</th>
                  <th class="px-4 py-2 text-left">Name</th>
                  <th class="px-4 py-2 text-left">Relation</th>
                  <th class="px-4 py-2 text-right">Share</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="(b, i) in policy.beneficiaries" :key="b.id ?? i">
                  <td class="px-4 py-2 text-slate-600">{{ (b as any).slot ?? '—' }}</td>
                  <td class="px-4 py-2 text-slate-900">{{ b.name }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ b.relation || '—' }}</td>
                  <td class="px-4 py-2 text-right">{{ b.share }}%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Motor block -->
        <section v-if="policy.motor">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Motor</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">Brand</div><div class="text-slate-900">{{ policy.motor.vehicleBrand || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Model</div><div class="text-slate-900">{{ policy.motor.vehicleModel || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">License no</div><div class="font-mono text-slate-900">{{ policy.motor.licenseNo || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Register year</div><div class="text-slate-900">{{ policy.motor.registerYear || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Engine no</div><div class="font-mono text-slate-900">{{ policy.motor.engineNo || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Chassis no</div><div class="font-mono text-slate-900">{{ policy.motor.chassisNo || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Passengers</div><div class="text-slate-900">{{ policy.motor.noPassenger || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Driver type</div><div class="text-slate-900">{{ policy.motor.typeDriver || '—' }}</div></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Vehicle type</div><div class="text-slate-900">{{ policy.motor.typeVehicle || '—' }}</div></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Notes</div><div class="text-slate-700">{{ policy.motor.notes || '—' }}</div></div>
          </div>
          <div v-if="policy.dataQuality?.vehicleOnNonMotor"
            class="mt-2 text-xs bg-amber-50 border border-amber-200 text-amber-700 rounded-lg p-2 flex items-center gap-2">
            <i class="pi pi-exclamation-triangle" /> Vehicle data on a non-motor product (importer flag).
          </div>
        </section>

        <!-- Property block -->
        <section v-if="policy.property">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Property</h3>
          <div class="card p-4 grid grid-cols-2 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">Insured name</div><div class="text-slate-900">{{ policy.property.insuredName || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Insured address</div><div class="text-slate-700">{{ policy.property.insuredAddress || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Building coverage</div><div class="text-slate-900">{{ fmtBaht(policy.property.buildingCoverage) }}</div></div>
            <div><div class="text-xs text-slate-400">Furniture coverage</div><div class="text-slate-900">{{ fmtBaht(policy.property.furnitureCoverage) }}</div></div>
            <div><div class="text-xs text-slate-400">Stock coverage</div><div class="text-slate-900">{{ fmtBaht(policy.property.stockCoverage) }}</div></div>
            <div><div class="text-xs text-slate-400">Other coverage</div><div class="text-slate-900">{{ fmtBaht(policy.property.otherCoverage) }}</div></div>
            <div class="col-span-2"><div class="text-xs text-slate-400">Other detail</div><div class="text-slate-700">{{ policy.property.otherDetail || '—' }}</div></div>
            <div class="col-span-2"><div class="text-xs text-slate-400">Notes</div><div class="text-slate-700">{{ policy.property.notes || '—' }}</div></div>
          </div>
        </section>

        <!-- Payments -->
        <section v-if="policy.payments && policy.payments.length">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">
            Payments <span class="text-slate-500 normal-case">({{ policy.payments.length }})</span>
          </h3>
          <div class="card overflow-hidden">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-4 py-2 text-left">Date</th>
                  <th class="px-4 py-2 text-right">Amount</th>
                  <th class="px-4 py-2 text-left">Method</th>
                  <th class="px-4 py-2 text-left">Reference</th>
                  <th class="px-4 py-2 text-right">Slip count</th>
                  <th class="px-4 py-2 text-left">Validate</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="p in policy.payments" :key="p.id">
                  <td class="px-4 py-2 text-slate-700">{{ p.paymentDate }}</td>
                  <td class="px-4 py-2 text-right font-medium text-slate-900">{{ fmtBaht(p.amount) }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ p.method }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ p.reference || '—' }}</td>
                  <td class="px-4 py-2 text-right text-slate-700">{{ (p as any).countSlip ?? '—' }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ (p as any).validateAmount || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Cancellation -->
        <section v-if="policy.cancellation">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Cancellation / refund</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">Status</div><div class="text-slate-900">{{ policy.cancellation.status }}</div></div>
            <div><div class="text-xs text-slate-400">Refund premium</div><div class="text-slate-900">{{ fmtBaht(policy.cancellation.refundPremium) }}</div></div>
            <div><div class="text-xs text-slate-400">Refund VAT</div><div class="text-slate-900">{{ fmtBaht(policy.cancellation.refundVat) }}</div></div>
            <div><div class="text-xs text-slate-400">Refund total premium</div><div class="text-slate-900">{{ fmtBaht(policy.cancellation.refundTotalPremium) }}</div></div>
            <div><div class="text-xs text-slate-400">Refund discount</div><div class="text-slate-900">{{ fmtBaht(policy.cancellation.refundDiscount) }}</div></div>
            <div><div class="text-xs text-slate-400">Net refund</div><div class="font-medium text-slate-900">{{ fmtBaht(policy.cancellation.netRefundAmount) }}</div></div>
            <div><div class="text-xs text-slate-400">Rebate amt refund</div><div class="text-slate-900">{{ fmtBaht(policy.cancellation.refundRebateAmt) }}</div></div>
            <div><div class="text-xs text-slate-400">Rebate OV refund</div><div class="text-slate-900">{{ fmtBaht(policy.cancellation.refundRebateOv) }}</div></div>
          </div>
        </section>

        <!-- Rebate -->
        <section v-if="policy.rebate">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Rebate ledger</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">InH status</div><div class="text-slate-900">{{ policy.rebate.rebateStatus || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Earn date</div><div class="text-slate-900">{{ fmtDate(policy.rebate.earnDate) }}</div></div>
            <div><div class="text-xs text-slate-400">OV status</div><div class="text-slate-900">{{ policy.rebate.ovStatus || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">OV date</div><div class="text-slate-900">{{ fmtDate(policy.rebate.ovDate) }}</div></div>
            <div><div class="text-xs text-slate-400">InH calc</div><div class="text-slate-900">{{ fmtBaht(policy.rebate.calculatedAmount) }}</div></div>
            <div><div class="text-xs text-slate-400">InH actual</div><div class="text-slate-900">{{ fmtBaht(policy.rebate.actualAmount) }}</div></div>
            <div><div class="text-xs text-slate-400">OV calc</div><div class="text-slate-900">{{ fmtBaht(policy.rebate.calculatedOv) }}</div></div>
            <div><div class="text-xs text-slate-400">OV actual</div><div class="text-slate-900">{{ fmtBaht(policy.rebate.actualOv) }}</div></div>
            <div><div class="text-xs text-slate-400">AG status</div><div class="text-slate-900">{{ policy.rebate.agentRebateStatus || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">AG receive date</div><div class="text-slate-900">{{ fmtDate(policy.rebate.agentReceiveDate) }}</div></div>
            <div><div class="text-xs text-slate-400">AG calc</div><div class="text-slate-900">{{ fmtBaht(policy.rebate.calculatedAgentAmount) }}</div></div>
            <div><div class="text-xs text-slate-400">AG actual</div><div class="text-slate-900">{{ fmtBaht(policy.rebate.actualAgentAmount) }}</div></div>
          </div>
        </section>

        <!-- Mailing -->
        <section v-if="policy.mailing?.address || policy.mailing?.date || policy.mailing?.note">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Mailing</h3>
          <div class="card p-4 grid grid-cols-2 gap-4 text-sm">
            <div class="col-span-2"><div class="text-xs text-slate-400">Address</div><div class="text-slate-900">{{ policy.mailing.address || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Date</div><div class="text-slate-900">{{ fmtDate(policy.mailing.date) }}</div></div>
            <div><div class="text-xs text-slate-400">Note</div><div class="text-slate-700">{{ policy.mailing.note || '—' }}</div></div>
          </div>
        </section>

        <!-- Notes / status (editable) -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Notes</h3>
          <div class="card p-4 space-y-3 text-sm">
            <div><div class="text-xs text-slate-400">Notes</div>
              <EditableField entity="policies" :id="policy.id" field="notes" type="textarea"
                :value="policy.notes" placeholder="ยังไม่มีบันทึก — คลิกเพื่อเพิ่ม"
                @update="v => apply('notes', v)" /></div>
          </div>
        </section>

        <div v-if="loading" class="text-center text-slate-500 py-4">Refreshing…</div>
      </div>

      <!-- Footer -->
      <footer v-if="policy" class="border-t border-slate-200 px-6 py-3 flex items-center justify-between sticky bottom-0 bg-white">
        <div class="text-xs text-slate-400">
          Click any field to edit · Press Enter to save, Esc to cancel
        </div>
        <button type="button"
          class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 text-sm flex items-center gap-1.5"
          @click="showDelete = true">
          <i class="pi pi-trash text-xs" /> Delete
        </button>
      </footer>
    </div>

    <DeleteConfirmDialog
      v-if="policy"
      :open="showDelete"
      :label="`policy ${policy.applicationNo || policy.policyNo || policy.id}`"
      :confirm-token="policy.applicationNo || policy.policyNo || policy.id"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
      @cancel="showDelete = false"
    />
  </div>
</template>
