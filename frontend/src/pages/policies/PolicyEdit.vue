<script setup lang="ts">
// Phase 6 — Sectioned policy edit. Loads a policy by :id, hydrates 5 sections
// (Parties / Dates / Premium / Payment / Notes) into local reactive state,
// and PATCH /policies/:id/section/:name on each Save. Q4 lock-after-issued
// is enforced server-side; we mirror it client-side by disabling inputs.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { fetchPolicy, patchPolicySection, syncPolicyRiders, syncPolicyBeneficiaries,
  uploadPolicyDocument, deletePolicyDocument, recomputeCommission,
  type PolicySection, type RiderInput, type BeneficiaryInput } from '../../api/policies'
import { fetchEndorsements, createEndorsement, type Endorsement } from '../../api/endorsements'
import { ApiError } from '../../api/client'
import DateInput from '../../components/DateInput.vue'
import { statusBadgeClass, type PolicyStatus } from '../../utils/policyStatus'

const { t } = useI18n()
const route = useRoute()

// Full untyped policy — the server returns more than the TS interface knows.
const policy = ref<Record<string, unknown> | null>(null)
const loading = ref(false)
const savingSection = ref<PolicySection | null>(null)
const savingAll = ref(false)
const sectionMsg = ref<Record<string, { ok: boolean; text: string } | null>>({
  parties: null, dates: null, premium: null, payment: null, notes: null, identifiers: null, motor: null, commission: null,
})

// ── Section 1: Parties (READ-ONLY — set at quote conversion) ──────────────
// Displayed only; not editable in Phase 6.

// ── Section 2: Dates ──────────────
const dates = reactive({
  effectiveDate: '' as string | null, expiryDate: '' as string | null,
  policyEnd: '' as string | null, periodPaidEnd: '' as string | null,
  mailingDate: '' as string | null, appDate: '' as string | null,
  policyYear: 1, actYear: 1, newOrRenew: 'new',
})

// ── Section 3: Premium & tax ──────────────
const premium = reactive({
  netPremium: 0, mainPremium: 0, dutyStamp: 0, vat: 0, totalPremiumPaid: 0,
  annualPremium: 0, coverage: 0, creditCardFee: 0, discountAmount: 0,
  whtAmt: 0, whtStatus: '', frontEndFee: 0,
})

// ── Section 4: Payment plan ──────────────
const payment = reactive({
  paymentMethodId: null as number | null, typeOfPaid: '', typeOfPaidNote: '',
  financeCompany: '', installmentTerm: 0,
  firstDueInst: 0, nextDueInst: 0,
  firstDueInstDate: '' as string | null, lastDueInstDate: '' as string | null,
  premiumMode: 'annual', subsidiseFromAgent: 0, subsidiseToFinance: 0,
})

// ── Section 5: Notes ──────────────
const notes = reactive({ internalNote: '', mailingNote: '', statusNote: '' })

// ── Section 6: Identifiers (policy_no, notion_no) ──────────────
const identifiers = reactive({ policyNo: '', notionNo: '' })

// ── Section 12: Commission (Phase 9b) ──────────────
// Rates stored as decimal (0.12 = 12%). UI displays as percent.
const commission = reactive({
  mainComRateInh: 0, mainComAmtInh: 0,
  mainComRateAg: 0, mainComAmtAg: 0,
  comRecCheck: '' as string,
})

// ── Section 7: Motor (Phase 6b) ──────────────
const motor = reactive({
  motorTypeVehicle: '', motorTypeDriver: '',
  motorVehicleBrand: '', motorVehicleModel: '',
  motorLicenseNo: '', motorEngineNo: '', motorChassisNo: '',
  motorRegisterYear: '', motorNoPassenger: null as number | null,
  motorNotes: '',
})

// ── Section 8: Riders (repeater, Phase 6b) ──────────────
const riders = ref<RiderInput[]>([])
const savingRiders = ref(false)
const ridersMsg = ref<{ ok: boolean; text: string } | null>(null)

// ── Section 9: Beneficiaries (repeater, Phase 6b) ──────────────
const beneficiaries = ref<BeneficiaryInput[]>([])
const savingBenef = ref(false)
const benefMsg = ref<{ ok: boolean; text: string } | null>(null)
const benefShareSum = computed(() =>
  beneficiaries.value.reduce((s, b) => s + (Number(b.share) || 0), 0),
)

// ── Section 11: Endorsements (Phase 9) ──────────────
const endorsements = ref<Endorsement[]>([])
const endorsementForm = reactive({
  type: 'endorsement.date_change' as string,
  reason: '',
  effectiveDate: '' as string,
})
const savingEndorsement = ref(false)
const endorsementMsg = ref<{ ok: boolean; text: string } | null>(null)

// ── Section 10: Documents (Phase 6b) ──────────────
interface DocRow { id: string; type: string; fileName: string; uploadedAt: string }
const documents = ref<DocRow[]>([])
const docUploading = ref(false)
const docUploadType = ref<string>('policy')
const docMsg = ref<{ ok: boolean; text: string } | null>(null)

/** Fields locked once status ≥ issued. Client mirror of server list. */
const LOCK_TRIGGER = ['issued', 'active', 'lapsed', 'cancelled', 'reinstated', 'expired']
const status = computed(() => (policy.value?.status as string) || '')
const isLocked = computed(() => LOCK_TRIGGER.includes(status.value))

function n(v: unknown, dflt = 0): number {
  const num = Number(v); return Number.isFinite(num) ? num : dflt
}

function hydrate(p: Record<string, unknown>): void {
  dates.effectiveDate = (p.effectiveDate as string) ?? ''
  dates.expiryDate = (p.expiryDate as string) ?? ''
  dates.policyEnd = (p.policyEnd as string) ?? ''
  dates.periodPaidEnd = (p.periodPaidEnd as string) ?? ''
  dates.mailingDate = (p.mailingDate as string) ?? ''
  dates.appDate = (p.appDate as string) ?? ''
  dates.policyYear = n(p.policyYear, 1)
  dates.actYear = n(p.actYear, 1)
  dates.newOrRenew = (p.newOrRenew as string) || 'new'

  premium.netPremium = n(p.netPremium)
  premium.mainPremium = n(p.mainPremium)
  premium.dutyStamp = n(p.dutyStamp)
  premium.vat = n(p.vat)
  premium.totalPremiumPaid = n(p.totalPremiumPaid)
  premium.annualPremium = n(p.annualPremium)
  premium.coverage = n(p.coverage)
  premium.creditCardFee = n(p.creditCardFee)
  premium.discountAmount = n(p.discountAmount)
  premium.whtAmt = n(p.whtAmt)
  premium.whtStatus = (p.whtStatus as string) ?? ''
  premium.frontEndFee = n(p.frontEndFee)

  payment.paymentMethodId = p.paymentMethodId != null ? Number(p.paymentMethodId) : null
  payment.typeOfPaid = (p.typeOfPaid as string) ?? ''
  payment.typeOfPaidNote = (p.typeOfPaidNote as string) ?? ''
  payment.financeCompany = (p.financeCompany as string) ?? ''
  payment.installmentTerm = n(p.installmentTerm)
  payment.firstDueInst = n(p.firstDueInst)
  payment.nextDueInst = n(p.nextDueInst)
  payment.firstDueInstDate = (p.firstDueInstDate as string) ?? ''
  payment.lastDueInstDate = (p.lastDueInstDate as string) ?? ''
  payment.premiumMode = (p.premiumMode as string) || 'annual'
  payment.subsidiseFromAgent = n(p.subsidiseFromAgent)
  payment.subsidiseToFinance = n(p.subsidiseToFinance)

  notes.internalNote = (p.internalNote as string) ?? ''
  notes.mailingNote = (p.mailingNote as string) ?? ''
  notes.statusNote = (p.statusNote as string) ?? ''

  identifiers.policyNo = (p.policyNo as string) ?? ''
  identifiers.notionNo = (p.notionNo as string) ?? ''

  // PolicyResource nests these under `mainCommission`; PATCH endpoint returns
  // the same shape so we hydrate off the nested object.
  const mc = (p.mainCommission as Record<string, unknown> | undefined) ?? {}
  commission.mainComRateInh = n(mc.rateInh)
  commission.mainComAmtInh = n(mc.amtInh)
  commission.mainComRateAg = n(mc.rateAg)
  commission.mainComAmtAg = n(mc.amtAg)
  commission.comRecCheck = (p.comRecCheck as string) ?? ''

  motor.motorTypeVehicle = (p.motorTypeVehicle as string) ?? ''
  motor.motorTypeDriver = (p.motorTypeDriver as string) ?? ''
  motor.motorVehicleBrand = (p.motorVehicleBrand as string) ?? ''
  motor.motorVehicleModel = (p.motorVehicleModel as string) ?? ''
  motor.motorLicenseNo = (p.motorLicenseNo as string) ?? ''
  motor.motorEngineNo = (p.motorEngineNo as string) ?? ''
  motor.motorChassisNo = (p.motorChassisNo as string) ?? ''
  motor.motorRegisterYear = (p.motorRegisterYear as string) ?? ''
  motor.motorNoPassenger = p.motorNoPassenger != null ? Number(p.motorNoPassenger) : null
  motor.motorNotes = (p.motorNotes as string) ?? ''

  // The API returns the full policy with nested collections.
  const rs = (p.riders as Array<Record<string, unknown>> | undefined) ?? []
  riders.value = rs.map((r) => ({
    name: (r.name as string) ?? '',
    premium: Number(r.premium ?? 0),
    slot: r.slot != null ? Number(r.slot) : null,
    notes: (r.notes as string) ?? '',
  }))
  const bs = (p.beneficiaries as Array<Record<string, unknown>> | undefined) ?? []
  beneficiaries.value = bs.map((b) => ({
    name: (b.name as string) ?? '',
    relation: (b.relation as string) ?? '',
    share: Number(b.share ?? 0),
    slot: b.slot != null ? Number(b.slot) : null,
  }))
  const ds = (p.documents as Array<Record<string, unknown>> | undefined) ?? []
  documents.value = ds.map((d) => ({
    id: String(d.id ?? ''),
    type: (d.type as string) ?? '',
    fileName: (d.fileName as string) ?? '',
    uploadedAt: (d.uploadedAt as string) ?? '',
  }))
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchPolicy(String(route.params.id))
    // The API returns { data: { ... } }
    const p = ((res as unknown as { data: Record<string, unknown> }).data)
    policy.value = p
    hydrate(p)
    await loadEndorsements(String(route.params.id))
  } finally {
    loading.value = false
  }
}

async function loadEndorsements(policyId: string): Promise<void> {
  try {
    const res = await fetchEndorsements(policyId)
    endorsements.value = res.data
  } catch { /* silent — endorsements are optional info */ }
}

async function submitEndorsement(): Promise<void> {
  if (!policy.value) return
  if (endorsementForm.reason.trim() === '') return
  savingEndorsement.value = true
  endorsementMsg.value = null
  try {
    const res = await createEndorsement(String(policy.value.id), {
      type: endorsementForm.type,
      reason: endorsementForm.reason.trim(),
      effectiveDate: endorsementForm.effectiveDate || undefined,
    })
    endorsements.value.unshift(res.data)
    endorsementForm.reason = ''
    endorsementForm.effectiveDate = ''
    endorsementMsg.value = { ok: true, text: t('policyEdit.saved') }
  } catch (e: unknown) {
    endorsementMsg.value = { ok: false, text: e instanceof ApiError ? e.message : 'Save failed' }
  } finally {
    savingEndorsement.value = false
    setTimeout(() => { endorsementMsg.value = null }, 3500)
  }
}

onMounted(load)

// ── Phase 9b — Premium auto-recalc watchers ───────────────────────────────
// Ports the iterative-VAT / VAT-inclusive math from Access's Premium calc
// buttons. Fires client-side so users see the answer instantly; the same
// math also lives server-side in PremiumCalculator.php as source of truth.
//
// Behavior:
//   - Editing netPremium (or coverage) → recompute dutyStamp, vat, totalPremiumPaid
//   - dutyStamp / vat / total are treated as derived; agents can still override
//     by directly editing them (autoRecalc = false via the toggle below)
const autoRecalcPremium = ref(true)

function computeDutyStamp(net: number): number {
  // Access: DutyStamp = -Int(-Premium * 0.004) → ceil(net * 0.004)
  return Math.ceil((net * 0.004) * 100) / 100
}
function computeVat(net: number, duty: number): number {
  return Math.round((net + duty) * 0.07 * 100) / 100
}

watch(() => premium.netPremium, (n) => {
  if (!autoRecalcPremium.value) return
  const net = Number(n) || 0
  const duty = computeDutyStamp(net)
  const vat = computeVat(net, duty)
  premium.dutyStamp = duty
  premium.vat = vat
  premium.totalPremiumPaid = Math.round((net + duty + vat) * 100) / 100
  // Access mirrors netPremium into annualPremium + mainPremium at entry time.
  if (!premium.annualPremium || premium.annualPremium === 0) premium.annualPremium = net
  if (!premium.mainPremium || premium.mainPremium === 0) premium.mainPremium = net
})

// ── Phase 9b — Commission amount auto-derives from rate × net premium ────
// Editing the rate updates the amount; editing the amount is left alone
// (mirrors Access's manual-override behavior).
watch(() => commission.mainComRateInh, (rate) => {
  const net = Number(premium.netPremium) || 0
  if (net > 0) commission.mainComAmtInh = Math.round(net * Number(rate) * 100) / 100
})
watch(() => commission.mainComRateAg, (rate) => {
  const net = Number(premium.netPremium) || 0
  if (net > 0) commission.mainComAmtAg = Math.round(net * Number(rate) * 100) / 100
})

// ── Phase 9c — recompute commission at current rates ──────────────────────
const recomputing = ref(false)
async function doRecompute(): Promise<void> {
  if (!policy.value) return
  if (!window.confirm(t('policyEdit.commission.recomputeConfirm'))) return
  recomputing.value = true
  sectionMsg.value.commission = null
  try {
    const res = await recomputeCommission(String(policy.value.id))
    sectionMsg.value.commission = {
      ok: true,
      text: `${res.reversed} reversed, ${res.created} fresh txns (${res.keyVersion})`,
    }
  } catch (e: unknown) {
    sectionMsg.value.commission = {
      ok: false, text: e instanceof ApiError ? e.message : 'Recompute failed',
    }
  } finally {
    recomputing.value = false
    setTimeout(() => { sectionMsg.value.commission = null }, 5000)
  }
}

async function save(section: PolicySection, payload: Record<string, unknown>): Promise<void> {
  if (!policy.value) return
  savingSection.value = section
  sectionMsg.value[section] = null
  try {
    const res = await patchPolicySection(String(policy.value.id), section, payload)
    // Refresh authoritative state so server-round-tripped fields update.
    const p = ((res as unknown as { data: Record<string, unknown> }).data)
    policy.value = p
    hydrate(p)
    sectionMsg.value[section] = { ok: true, text: t('policyEdit.saved') }
  } catch (e: unknown) {
    const msg = e instanceof ApiError ? e.message : e instanceof Error ? e.message : 'Save failed'
    sectionMsg.value[section] = { ok: false, text: msg }
  } finally {
    savingSection.value = null
    setTimeout(() => { sectionMsg.value[section] = null }, 3500)
  }
}

async function saveAll(): Promise<void> {
  savingAll.value = true
  await save('dates', { ...dates })
  await save('premium', { ...premium })
  await save('payment', { ...payment })
  await save('notes', { ...notes })
  await save('identifiers', { ...identifiers })
  await save('motor', { ...motor })
  await save('commission', { ...commission })
  await saveRiders()
  await saveBeneficiaries()
  savingAll.value = false
}

// ── Phase 6b action functions ──────────────

async function saveRiders(): Promise<void> {
  if (!policy.value) return
  savingRiders.value = true
  ridersMsg.value = null
  try {
    const res = await syncPolicyRiders(String(policy.value.id), riders.value)
    const p = ((res as unknown as { data: Record<string, unknown> }).data)
    policy.value = p; hydrate(p)
    ridersMsg.value = { ok: true, text: t('policyEdit.saved') }
  } catch (e: unknown) {
    ridersMsg.value = { ok: false, text: e instanceof ApiError ? e.message : 'Save failed' }
  } finally {
    savingRiders.value = false
    setTimeout(() => { ridersMsg.value = null }, 3500)
  }
}
function addRider(): void {
  riders.value.push({ name: '', premium: 0, notes: '' })
}
function removeRider(i: number): void {
  riders.value.splice(i, 1)
}

async function saveBeneficiaries(): Promise<void> {
  if (!policy.value) return
  savingBenef.value = true
  benefMsg.value = null
  try {
    const res = await syncPolicyBeneficiaries(String(policy.value.id), beneficiaries.value)
    const p = ((res as unknown as { data: Record<string, unknown> }).data)
    policy.value = p; hydrate(p)
    benefMsg.value = { ok: true, text: t('policyEdit.saved') }
  } catch (e: unknown) {
    benefMsg.value = { ok: false, text: e instanceof ApiError ? e.message : 'Save failed' }
  } finally {
    savingBenef.value = false
    setTimeout(() => { benefMsg.value = null }, 3500)
  }
}
function addBeneficiary(): void {
  beneficiaries.value.push({ name: '', relation: '', share: 0 })
}
function removeBeneficiary(i: number): void {
  beneficiaries.value.splice(i, 1)
}

async function onDocFileChange(e: Event): Promise<void> {
  if (!policy.value) return
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  if (!file) return
  docUploading.value = true
  docMsg.value = null
  try {
    const res = await uploadPolicyDocument(String(policy.value.id), docUploadType.value, file)
    documents.value.push({
      id: res.data.id, type: res.data.type,
      fileName: res.data.fileName, uploadedAt: res.data.uploadedAt,
    })
    docMsg.value = { ok: true, text: t('policyEdit.saved') }
  } catch (err: unknown) {
    docMsg.value = { ok: false, text: err instanceof ApiError ? err.message : 'Upload failed' }
  } finally {
    docUploading.value = false
    target.value = ''
    setTimeout(() => { docMsg.value = null }, 3500)
  }
}

async function removeDoc(id: string): Promise<void> {
  if (!policy.value) return
  if (!window.confirm(t('policyEdit.docs.confirmDelete'))) return
  try {
    await deletePolicyDocument(String(policy.value.id), id)
    documents.value = documents.value.filter((d) => d.id !== id)
  } catch (err: unknown) {
    docMsg.value = { ok: false, text: err instanceof ApiError ? err.message : 'Delete failed' }
  }
}
</script>

<template>
  <div class="space-y-6 max-w-4xl">
    <header class="flex items-center justify-between">
      <div>
        <div class="text-xs text-slate-400">{{ t('policyEdit.title') }}</div>
        <h1 class="text-2xl font-semibold text-slate-900 font-mono">
          {{ (policy?.applicationNo as string) || (policy?.quoteNo as string) || '—' }}
        </h1>
        <div class="mt-1 flex items-center gap-2 text-xs">
          <span :class="['inline-flex px-2 py-0.5 rounded', statusBadgeClass(status as PolicyStatus)]">
            {{ status ? t(`policies.status.${status}`) : '—' }}
          </span>
          <span v-if="isLocked" class="text-amber-700 flex items-center gap-1">
            <i class="pi pi-lock text-[10px]" /> {{ t('policyEdit.lockedHint') }}
          </span>
        </div>
      </div>
      <button type="button" :disabled="savingAll || !policy"
        class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
        @click="saveAll">
        <i v-if="savingAll" class="pi pi-spin pi-spinner mr-2" />
        {{ t('policyEdit.saveAll') }}
      </button>
    </header>

    <div v-if="loading && !policy" class="card p-6 text-slate-500 text-sm">Loading…</div>

    <template v-else-if="policy">
      <!-- Section 1: Parties (read-only) -->
      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">{{ t('policyEdit.section.parties') }}</h2>
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div><dt class="text-xs text-slate-400">{{ t('policyEdit.f.customer') }}</dt>
            <dd class="font-mono text-xs">{{ policy.customerId || '—' }}</dd></div>
          <div><dt class="text-xs text-slate-400">{{ t('policyEdit.f.product') }}</dt>
            <dd class="font-mono text-xs">{{ policy.productId || '—' }}</dd></div>
          <div><dt class="text-xs text-slate-400">{{ t('policyEdit.f.carrier') }}</dt>
            <dd class="font-mono text-xs">{{ policy.carrierId || '—' }}</dd></div>
          <div><dt class="text-xs text-slate-400">{{ t('policyEdit.f.writingAgent') }}</dt>
            <dd class="font-mono text-xs">{{ policy.writingAgentId || '—' }}</dd></div>
        </dl>
      </section>

      <!-- Section 2: Identifiers (unlocks policyNo assignment) -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('policyEdit.section.identifiers') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'identifiers'"
            @click="save('identifiers', { ...identifiers })">
            <i v-if="savingSection === 'identifiers'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('policyEdit.save') }}
          </button>
        </div>
        <div v-if="sectionMsg.identifiers"
          :class="sectionMsg.identifiers.ok ? 'text-emerald-700' : 'text-rose-700'"
          class="text-xs mb-2">{{ sectionMsg.identifiers.text }}</div>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.policyNo') }}</label>
            <input v-model.trim="identifiers.policyNo" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.notionNo') }}</label>
            <input v-model.trim="identifiers.notionNo" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs" />
          </div>
        </div>
      </section>

      <!-- Section 3: Dates -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('policyEdit.section.dates') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'dates'"
            @click="save('dates', { ...dates })">
            <i v-if="savingSection === 'dates'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('policyEdit.save') }}
          </button>
        </div>
        <div v-if="sectionMsg.dates"
          :class="sectionMsg.dates.ok ? 'text-emerald-700' : 'text-rose-700'"
          class="text-xs mb-2">{{ sectionMsg.dates.text }}</div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.effectiveDate') }}
              <i v-if="isLocked" class="pi pi-lock text-[9px] text-amber-500 ml-1" /></label>
            <DateInput v-model="dates.effectiveDate" :max="dates.expiryDate || undefined" :disabled="isLocked" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.expiryDate') }}
              <i v-if="isLocked" class="pi pi-lock text-[9px] text-amber-500 ml-1" /></label>
            <DateInput v-model="dates.expiryDate" :min="dates.effectiveDate || undefined" :disabled="isLocked" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.policyEnd') }}</label>
            <DateInput v-model="dates.policyEnd" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.periodPaidEnd') }}</label>
            <DateInput v-model="dates.periodPaidEnd" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.mailingDate') }}</label>
            <DateInput v-model="dates.mailingDate" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.appDate') }}</label>
            <DateInput v-model="dates.appDate" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.policyYear') }}</label>
            <input v-model.number="dates.policyYear" type="number" min="0" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.actYear') }}</label>
            <input v-model.number="dates.actYear" type="number" min="0" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.newOrRenew') }}</label>
            <select v-model="dates.newOrRenew" class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white">
              <option value="new">new</option>
              <option value="renew">renew</option>
            </select>
          </div>
        </div>
      </section>

      <!-- Section 4: Premium & tax -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('policyEdit.section.premium') }}</h2>
          <div class="flex items-center gap-3">
            <label class="text-xs text-slate-500 inline-flex items-center gap-1.5 cursor-pointer">
              <input v-model="autoRecalcPremium" type="checkbox" class="rounded" />
              {{ t('policyEdit.premium.autoCalc') }}
            </label>
            <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
              :disabled="savingSection === 'premium'"
              @click="save('premium', { ...premium })">
              <i v-if="savingSection === 'premium'" class="pi pi-spin pi-spinner mr-1" />
              {{ t('policyEdit.save') }}
            </button>
          </div>
        </div>
        <div v-if="sectionMsg.premium"
          :class="sectionMsg.premium.ok ? 'text-emerald-700' : 'text-rose-700'"
          class="text-xs mb-2">{{ sectionMsg.premium.text }}</div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div v-for="[k, label] in [
            ['netPremium', t('policyEdit.f.netPremium')],
            ['mainPremium', t('policyEdit.f.mainPremium')],
            ['dutyStamp', t('policyEdit.f.dutyStamp')],
            ['vat', t('policyEdit.f.vat')],
            ['totalPremiumPaid', t('policyEdit.f.totalPremiumPaid')],
            ['annualPremium', t('policyEdit.f.annualPremium')],
            ['coverage', t('policyEdit.f.coverage')],
            ['creditCardFee', t('policyEdit.f.creditCardFee')],
            ['discountAmount', t('policyEdit.f.discountAmount')],
            ['whtAmt', t('policyEdit.f.whtAmt')],
            ['frontEndFee', t('policyEdit.f.frontEndFee')],
          ] as [keyof typeof premium, string][]" :key="k">
            <label class="text-xs text-slate-500 mb-1 block">{{ label }}
              <i v-if="isLocked && ['netPremium','mainPremium','dutyStamp','vat','totalPremiumPaid','coverage'].includes(k as string)"
                class="pi pi-lock text-[9px] text-amber-500 ml-1" /></label>
            <input :value="premium[k]" type="number" step="0.01"
              :disabled="isLocked && ['netPremium','mainPremium','dutyStamp','vat','totalPremiumPaid','coverage'].includes(k as string)"
              @input="(e) => ((premium as any)[k] = Number((e.target as HTMLInputElement).value))"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs disabled:bg-slate-50 disabled:text-slate-500" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.whtStatus') }}</label>
            <input v-model.trim="premium.whtStatus" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
        </div>
      </section>

      <!-- Section 5: Payment plan -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('policyEdit.section.payment') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'payment'"
            @click="save('payment', { ...payment })">
            <i v-if="savingSection === 'payment'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('policyEdit.save') }}
          </button>
        </div>
        <div v-if="sectionMsg.payment"
          :class="sectionMsg.payment.ok ? 'text-emerald-700' : 'text-rose-700'"
          class="text-xs mb-2">{{ sectionMsg.payment.text }}</div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.premiumMode') }}</label>
            <select v-model="payment.premiumMode" class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white">
              <option value="single">single</option>
              <option value="annual">annual</option>
              <option value="semiannual">semiannual</option>
              <option value="quarterly">quarterly</option>
              <option value="monthly">monthly</option>
            </select>
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.installmentTerm') }}</label>
            <input v-model.number="payment.installmentTerm" type="number" min="0" max="120" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.typeOfPaid') }}</label>
            <input v-model.trim="payment.typeOfPaid" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.financeCompany') }}</label>
            <input v-model.trim="payment.financeCompany" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.firstDueInst') }}</label>
            <input v-model.number="payment.firstDueInst" type="number" step="0.01" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.nextDueInst') }}</label>
            <input v-model.number="payment.nextDueInst" type="number" step="0.01" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.firstDueInstDate') }}</label>
            <DateInput v-model="payment.firstDueInstDate" :max="payment.lastDueInstDate || undefined" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.lastDueInstDate') }}</label>
            <DateInput v-model="payment.lastDueInstDate" :min="payment.firstDueInstDate || undefined" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.subsidiseFromAgent') }}</label>
            <input v-model.number="payment.subsidiseFromAgent" type="number" step="0.01" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.subsidiseToFinance') }}</label>
            <input v-model.number="payment.subsidiseToFinance" type="number" step="0.01" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs" />
          </div>
          <div class="col-span-3">
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.typeOfPaidNote') }}</label>
            <input v-model.trim="payment.typeOfPaidNote" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
        </div>
      </section>

      <!-- Section 12: Commission (Phase 9b) -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('policyEdit.section.commission') }}</h2>
          <div class="flex items-center gap-3">
            <button type="button"
              class="text-sm text-amber-700 hover:text-amber-800 disabled:opacity-50 inline-flex items-center gap-1"
              :disabled="recomputing" :title="t('policyEdit.commission.recomputeHint')"
              @click="doRecompute">
              <i :class="recomputing ? 'pi pi-spin pi-spinner' : 'pi pi-refresh'" class="text-xs" />
              {{ t('policyEdit.commission.recompute') }}
            </button>
            <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
              :disabled="savingSection === 'commission'"
              @click="save('commission', { ...commission })">
              <i v-if="savingSection === 'commission'" class="pi pi-spin pi-spinner mr-1" />
              {{ t('policyEdit.save') }}
            </button>
          </div>
        </div>
        <div v-if="sectionMsg.commission" :class="sectionMsg.commission.ok ? 'text-emerald-700' : 'text-rose-700'"
          class="text-xs mb-2">{{ sectionMsg.commission.text }}</div>
        <p class="text-xs text-slate-500 mb-3">
          {{ t('policyEdit.commission.hint') }}
        </p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.commission.rateInh') }}</label>
            <div class="relative">
              <input v-model.number="commission.mainComRateInh" type="number" step="0.0001" min="0" max="1"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 pr-10 font-mono text-xs" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">
                {{ ((commission.mainComRateInh || 0) * 100).toFixed(2) }}%
              </span>
            </div>
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.commission.amtInh') }}</label>
            <input v-model.number="commission.mainComAmtInh" type="number" step="0.01" min="0"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.commission.rateAg') }}</label>
            <div class="relative">
              <input v-model.number="commission.mainComRateAg" type="number" step="0.0001" min="0" max="1"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 pr-10 font-mono text-xs" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">
                {{ ((commission.mainComRateAg || 0) * 100).toFixed(2) }}%
              </span>
            </div>
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.commission.amtAg') }}</label>
            <input v-model.number="commission.mainComAmtAg" type="number" step="0.01" min="0"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs" />
          </div>
          <div class="col-span-2 md:col-span-4">
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.commission.recCheck') }}</label>
            <select v-model="commission.comRecCheck" class="border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
              <option value="">—</option>
              <option value="Pending">Pending</option>
              <option value="Complete">Complete</option>
            </select>
            <p class="text-xs text-slate-400 mt-1">{{ t('policyEdit.commission.recCheckHint') }}</p>
          </div>
        </div>
      </section>

      <!-- Section 6: Notes -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('policyEdit.section.notes') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'notes'"
            @click="save('notes', { ...notes })">
            <i v-if="savingSection === 'notes'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('policyEdit.save') }}
          </button>
        </div>
        <div v-if="sectionMsg.notes"
          :class="sectionMsg.notes.ok ? 'text-emerald-700' : 'text-rose-700'"
          class="text-xs mb-2">{{ sectionMsg.notes.text }}</div>
        <div class="grid grid-cols-1 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.internalNote') }}</label>
            <textarea v-model="notes.internalNote" rows="3" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.mailingNote') }}</label>
            <textarea v-model="notes.mailingNote" rows="2" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.statusNote') }}</label>
            <textarea v-model="notes.statusNote" rows="2" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
        </div>
      </section>

      <!-- Section 7: Motor (Phase 6b) -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('policyEdit.section.motor') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'motor'"
            @click="save('motor', { ...motor })">
            <i v-if="savingSection === 'motor'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('policyEdit.save') }}
          </button>
        </div>
        <div v-if="sectionMsg.motor" :class="sectionMsg.motor.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-2">
          {{ sectionMsg.motor.text }}
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorVehicleBrand') }}</label>
            <input v-model.trim="motor.motorVehicleBrand" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorVehicleModel') }}</label>
            <input v-model.trim="motor.motorVehicleModel" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorRegisterYear') }}</label>
            <input v-model.trim="motor.motorRegisterYear" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorLicenseNo') }}</label>
            <input v-model.trim="motor.motorLicenseNo" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorChassisNo') }}</label>
            <input v-model.trim="motor.motorChassisNo" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorEngineNo') }}</label>
            <input v-model.trim="motor.motorEngineNo" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorTypeVehicle') }}</label>
            <input v-model.trim="motor.motorTypeVehicle" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorTypeDriver') }}</label>
            <input v-model.trim="motor.motorTypeDriver" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorNoPassenger') }}</label>
            <input v-model.number="motor.motorNoPassenger" type="number" min="0" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div class="md:col-span-3">
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.f.motorNotes') }}</label>
            <textarea v-model="motor.motorNotes" rows="2" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
        </div>
      </section>

      <!-- Section 8: Riders (Phase 6b, repeater) -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">
            {{ t('policyEdit.section.riders') }}
            <span class="text-slate-500 font-normal text-xs ml-1">({{ riders.length }})</span>
          </h2>
          <div class="flex items-center gap-2">
            <button type="button" class="text-xs text-slate-600 hover:text-brand-600 flex items-center gap-1" @click="addRider">
              <i class="pi pi-plus text-[10px]" /> {{ t('policyEdit.addRow') }}
            </button>
            <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
              :disabled="savingRiders" @click="saveRiders">
              <i v-if="savingRiders" class="pi pi-spin pi-spinner mr-1" />
              {{ t('policyEdit.save') }}
            </button>
          </div>
        </div>
        <div v-if="ridersMsg" :class="ridersMsg.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-2">
          {{ ridersMsg.text }}
        </div>
        <table class="min-w-full text-sm">
          <thead class="text-xs text-slate-500 uppercase">
            <tr>
              <th class="text-left px-2 py-1 w-8">#</th>
              <th class="text-left px-2 py-1">{{ t('policyEdit.riders.name') }}</th>
              <th class="text-right px-2 py-1 w-32">{{ t('policyEdit.riders.premium') }}</th>
              <th class="text-left px-2 py-1">{{ t('policyEdit.riders.notes') }}</th>
              <th class="w-10"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="!riders.length">
              <td colspan="5" class="text-center text-slate-400 py-4 text-xs">{{ t('policyEdit.riders.empty') }}</td>
            </tr>
            <tr v-for="(r, i) in riders" :key="i">
              <td class="px-2 py-1 text-slate-400 text-xs">{{ i + 1 }}</td>
              <td class="px-2 py-1"><input v-model.trim="r.name" class="w-full border border-slate-200 rounded px-2 py-1" /></td>
              <td class="px-2 py-1"><input v-model.number="r.premium" type="number" step="0.01" class="w-full border border-slate-200 rounded px-2 py-1 text-right font-mono text-xs" /></td>
              <td class="px-2 py-1"><input v-model.trim="r.notes" class="w-full border border-slate-200 rounded px-2 py-1" /></td>
              <td class="px-2 py-1 text-right">
                <button type="button" class="text-rose-500 hover:text-rose-700 p-1" @click="removeRider(i)">
                  <i class="pi pi-trash text-xs" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Section 9: Beneficiaries (Phase 6b, repeater) -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">
            {{ t('policyEdit.section.beneficiaries') }}
            <span class="text-slate-500 font-normal text-xs ml-1">
              ({{ beneficiaries.length }} — {{ t('policyEdit.beneficiaries.total') }} {{ benefShareSum }}%)
            </span>
          </h2>
          <div class="flex items-center gap-2">
            <button type="button" class="text-xs text-slate-600 hover:text-brand-600 flex items-center gap-1" @click="addBeneficiary">
              <i class="pi pi-plus text-[10px]" /> {{ t('policyEdit.addRow') }}
            </button>
            <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
              :disabled="savingBenef" @click="saveBeneficiaries">
              <i v-if="savingBenef" class="pi pi-spin pi-spinner mr-1" />
              {{ t('policyEdit.save') }}
            </button>
          </div>
        </div>
        <div v-if="benefMsg" :class="benefMsg.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-2">
          {{ benefMsg.text }}
        </div>
        <div v-if="benefShareSum > 100" class="text-xs text-rose-600 mb-2">
          <i class="pi pi-exclamation-triangle mr-1" /> {{ t('policyEdit.beneficiaries.overLimit') }}
        </div>
        <table class="min-w-full text-sm">
          <thead class="text-xs text-slate-500 uppercase">
            <tr>
              <th class="text-left px-2 py-1 w-8">#</th>
              <th class="text-left px-2 py-1">{{ t('policyEdit.beneficiaries.name') }}</th>
              <th class="text-left px-2 py-1 w-40">{{ t('policyEdit.beneficiaries.relation') }}</th>
              <th class="text-right px-2 py-1 w-24">{{ t('policyEdit.beneficiaries.share') }}</th>
              <th class="w-10"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="!beneficiaries.length">
              <td colspan="5" class="text-center text-slate-400 py-4 text-xs">{{ t('policyEdit.beneficiaries.empty') }}</td>
            </tr>
            <tr v-for="(b, i) in beneficiaries" :key="i">
              <td class="px-2 py-1 text-slate-400 text-xs">{{ i + 1 }}</td>
              <td class="px-2 py-1"><input v-model.trim="b.name" class="w-full border border-slate-200 rounded px-2 py-1" /></td>
              <td class="px-2 py-1"><input v-model.trim="b.relation" class="w-full border border-slate-200 rounded px-2 py-1" /></td>
              <td class="px-2 py-1"><input v-model.number="b.share" type="number" step="0.01" min="0" max="100" class="w-full border border-slate-200 rounded px-2 py-1 text-right font-mono text-xs" /></td>
              <td class="px-2 py-1 text-right">
                <button type="button" class="text-rose-500 hover:text-rose-700 p-1" @click="removeBeneficiary(i)">
                  <i class="pi pi-trash text-xs" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Section 10: Documents (Phase 6b) -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">
            {{ t('policyEdit.section.documents') }}
            <span class="text-slate-500 font-normal text-xs ml-1">({{ documents.length }})</span>
          </h2>
          <div class="flex items-center gap-2">
            <select v-model="docUploadType" class="border border-slate-200 rounded px-2 py-1 text-xs bg-white">
              <option value="application">application</option>
              <option value="policy">policy</option>
              <option value="receipt">receipt</option>
              <option value="medical">medical</option>
              <option value="endorsement">endorsement</option>
              <option value="cancellation">cancellation</option>
              <option value="other">other</option>
            </select>
            <label class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-md bg-brand-600 text-white hover:bg-brand-700 cursor-pointer">
              <i class="pi pi-upload text-[10px]" />
              <span>{{ docUploading ? t('policyEdit.docs.uploading') : t('policyEdit.docs.upload') }}</span>
              <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="hidden"
                :disabled="docUploading" @change="onDocFileChange" />
            </label>
          </div>
        </div>
        <div v-if="docMsg" :class="docMsg.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-2">
          {{ docMsg.text }}
        </div>
        <table class="min-w-full text-sm">
          <thead class="text-xs text-slate-500 uppercase">
            <tr>
              <th class="text-left px-2 py-1">{{ t('policyEdit.docs.name') }}</th>
              <th class="text-left px-2 py-1 w-32">{{ t('policyEdit.docs.type') }}</th>
              <th class="text-left px-2 py-1 w-48">{{ t('policyEdit.docs.uploadedAt') }}</th>
              <th class="w-10"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="!documents.length">
              <td colspan="4" class="text-center text-slate-400 py-4 text-xs">{{ t('policyEdit.docs.empty') }}</td>
            </tr>
            <tr v-for="d in documents" :key="d.id">
              <td class="px-2 py-1 truncate">
                <i class="pi pi-file text-slate-400 mr-1 text-[10px]" />
                {{ d.fileName }}
              </td>
              <td class="px-2 py-1 text-xs">
                <span class="inline-flex px-2 py-0.5 rounded bg-slate-100 text-slate-700">{{ d.type }}</span>
              </td>
              <td class="px-2 py-1 text-xs text-slate-500 font-mono">
                {{ d.uploadedAt?.slice(0, 19).replace('T', ' ') || '—' }}
              </td>
              <td class="px-2 py-1 text-right">
                <button type="button" class="text-rose-500 hover:text-rose-700 p-1" @click="removeDoc(d.id)">
                  <i class="pi pi-trash text-xs" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Section 11: Endorsements (Phase 9) -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">
            {{ t('policyEdit.section.endorsements') }}
            <span class="text-slate-500 font-normal text-xs ml-1">({{ endorsements.length }})</span>
          </h2>
        </div>
        <div v-if="endorsementMsg" :class="endorsementMsg.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-2">
          {{ endorsementMsg.text }}
        </div>

        <!-- Create form -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.endorsements.type') }}</label>
            <select v-model="endorsementForm.type" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white">
              <option value="endorsement.date_change">{{ t('policyEdit.endorsements.dateChange') }}</option>
              <option value="endorsement.coverage_change">{{ t('policyEdit.endorsements.coverageChange') }}</option>
              <option value="endorsement.cancel_reissue">{{ t('policyEdit.endorsements.cancelReissue') }}</option>
              <option value="endorsement.other">{{ t('policyEdit.endorsements.other') }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.endorsements.effectiveDate') }}</label>
            <DateInput v-model="endorsementForm.effectiveDate" />
          </div>
          <div class="flex items-end">
            <button type="button"
              class="w-full px-3 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
              :disabled="savingEndorsement || !endorsementForm.reason.trim()"
              @click="submitEndorsement">
              <i v-if="savingEndorsement" class="pi pi-spin pi-spinner mr-1" />
              {{ t('policyEdit.endorsements.add') }}
            </button>
          </div>
          <div class="md:col-span-3">
            <label class="text-xs text-slate-500 mb-1 block">{{ t('policyEdit.endorsements.reason') }} *</label>
            <textarea v-model="endorsementForm.reason" rows="2" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm"
              :placeholder="t('policyEdit.endorsements.reasonPlaceholder')" />
          </div>
        </div>

        <!-- History -->
        <div v-if="!endorsements.length" class="text-center text-slate-400 text-xs py-4">
          {{ t('policyEdit.endorsements.empty') }}
        </div>
        <table v-else class="min-w-full text-sm">
          <thead class="text-xs text-slate-500 uppercase">
            <tr>
              <th class="text-left px-2 py-1">{{ t('policyEdit.endorsements.type') }}</th>
              <th class="text-left px-2 py-1">{{ t('policyEdit.endorsements.date') }}</th>
              <th class="text-left px-2 py-1">{{ t('policyEdit.endorsements.reason') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="e in endorsements" :key="e.id">
              <td class="px-2 py-2">
                <span class="inline-flex px-2 py-0.5 rounded bg-brand-50 text-brand-700 text-xs">
                  {{ e.type.replace('endorsement.', '') }}
                </span>
              </td>
              <td class="px-2 py-2 text-xs font-mono text-slate-500">
                {{ e.occurredAt?.slice(0, 19).replace('T', ' ') }}
              </td>
              <td class="px-2 py-2 text-slate-700">
                <div class="text-xs">{{ (e.payload as any)?.reason ?? '—' }}</div>
                <div v-if="(e.payload as any)?.effectiveDate" class="text-[10px] text-slate-500 mt-0.5">
                  {{ t('policyEdit.endorsements.effectiveFrom') }}: {{ (e.payload as any).effectiveDate }}
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
