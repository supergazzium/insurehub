<script setup lang="ts">
// Phase 5 — Quote create/edit. Motor mode wires the ACT tariff picker;
// non-motor mode is a bare product+premium form. Premium widget hits the
// backend calculator endpoint so numbers always match server-side truth.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { createQuote, updateQuote, fetchQuote, fetchActTariffs, previewPremium,
  type Quote, type ActTariff, type PremiumMode } from '../../api/quotes'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { fetchProductList, type ProductListRow } from '../../api/products'
import { useAuthStore } from '../../stores/auth'
import { ApiError } from '../../api/client'
import DateInput from '../../components/DateInput.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const editId = computed(() => (typeof route.params.id === 'string' ? route.params.id : ''))
const isEdit = computed(() => editId.value !== '')

const form = reactive({
  customerId: '' as string | number,
  carrierId: '' as string | number,
  productId: '' as string | number,
  writingAgentId: '' as string | number,
  effectiveDate: '',
  expiryDate: '',
  netPremium: 0,
  dutyStamp: 0,
  vat: 0,
  totalPremiumPaid: 0,
  premiumMode: 'annual' as string,
  newOrRenew: 'new' as string,
  internalNote: '',
})

const kind = ref<'motor' | 'nonMotor'>('nonMotor')
const calcMode = ref<PremiumMode>('iterativeVat')
const fixedDuty = ref(20)
const totalPaidInput = ref(0)

const carriers = ref<CarrierListRow[]>([])
const products = ref<ProductListRow[]>([])
const tariffs = ref<ActTariff[]>([])
const selectedTariffCode = ref<string>('')

const saving = ref(false)
const error = ref<string | null>(null)

onMounted(async () => {
  const [c, p, tres] = await Promise.all([
    fetchCarrierList({ perPage: 100, activeOnly: true }),
    fetchProductList({ perPage: 100 }),
    fetchActTariffs(),
  ])
  carriers.value = c.data
  products.value = p.data
  tariffs.value = tres.data
  if (isEdit.value) {
    const q = await fetchQuote(editId.value)
    hydrate(q.data)
  } else if (auth.user?.role === 'agent' && auth.user?.agentId) {
    form.writingAgentId = auth.user.agentId
  }
})

function hydrate(q: Quote): void {
  form.customerId = q.customerId ?? ''
  form.carrierId = q.carrierId ?? ''
  form.productId = q.productId ?? ''
  form.writingAgentId = q.writingAgentId ?? ''
  form.effectiveDate = q.effectiveDate ?? ''
  form.expiryDate = q.expiryDate ?? ''
  form.netPremium = Number(q.netPremium ?? 0)
  form.dutyStamp = Number(q.dutyStamp ?? 0)
  form.vat = Number(q.vat ?? 0)
  form.totalPremiumPaid = Number(q.totalPremiumPaid ?? 0)
  form.premiumMode = q.premiumMode || 'annual'
  form.newOrRenew = q.newOrRenew || 'new'
  form.internalNote = q.internalNote ?? ''
}

async function recalc(): Promise<void> {
  const res = await previewPremium({
    mode: calcMode.value,
    netPremium: form.netPremium,
    totalPaid: totalPaidInput.value,
    fixedDuty: fixedDuty.value,
  })
  form.netPremium = res.netPremium
  form.dutyStamp = res.dutyStamp
  form.vat = res.vat
  form.totalPremiumPaid = res.totalPremium
}

// When user picks an ACT tariff, seed netPremium and recalc.
watch(selectedTariffCode, (code) => {
  const t = tariffs.value.find((x) => x.vehicleTypeCode === code)
  if (t) {
    form.netPremium = t.premium
    void recalc()
  }
})

const canSubmit = computed(() =>
  form.writingAgentId !== '' && Number(form.netPremium) >= 0,
)

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  saving.value = true
  error.value = null
  try {
    const payload = {
      customerId: form.customerId ? Number(form.customerId) : null,
      carrierId: form.carrierId ? Number(form.carrierId) : null,
      productId: form.productId ? Number(form.productId) : null,
      writingAgentId: form.writingAgentId ? Number(form.writingAgentId) : null,
      effectiveDate: form.effectiveDate || null,
      expiryDate: form.expiryDate || null,
      netPremium: form.netPremium,
      dutyStamp: form.dutyStamp,
      vat: form.vat,
      totalPremiumPaid: form.totalPremiumPaid,
      annualPremium: form.netPremium,
      premiumMode: form.premiumMode,
      newOrRenew: form.newOrRenew,
      internalNote: form.internalNote || null,
    }
    const res = isEdit.value
      ? await updateQuote(editId.value, payload)
      : await createQuote(payload)
    void router.push({ name: 'quote-detail', params: { id: res.data.id } })
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Save failed.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-6 max-w-3xl">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">
        {{ isEdit ? t('quotes.editTitle') : t('quotes.newTitle') }}
      </h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('quotes.newSubtitle') }}</p>
    </header>

    <!-- Kind tabs -->
    <section class="card p-4">
      <div class="flex gap-2 text-sm">
        <button type="button" :class="[
          'px-3 py-1.5 rounded-md',
          kind === 'nonMotor' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-700']"
          @click="kind = 'nonMotor'">{{ t('quotes.kind.nonMotor') }}</button>
        <button type="button" :class="[
          'px-3 py-1.5 rounded-md',
          kind === 'motor' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-700']"
          @click="kind = 'motor'">{{ t('quotes.kind.motor') }}</button>
      </div>
    </section>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">{{ error }}</div>

    <!-- Motor: ACT tariff picker -->
    <section v-if="kind === 'motor'" class="card p-5 space-y-4">
      <h2 class="font-semibold text-slate-900">{{ t('quotes.motor.actTitle') }}</h2>
      <p class="text-xs text-slate-500">{{ t('quotes.motor.actHint') }}</p>
      <div class="grid grid-cols-3 gap-3">
        <button v-for="tar in tariffs" :key="tar.id" type="button"
          :class="['card p-3 text-left border',
            selectedTariffCode === tar.vehicleTypeCode ? 'border-brand-500 bg-brand-50' : 'border-slate-200']"
          @click="selectedTariffCode = tar.vehicleTypeCode">
          <div class="font-medium text-sm">{{ tar.labelTh }}</div>
          <div class="text-xs text-slate-500">{{ tar.vehicleTypeCode }}</div>
          <div class="font-mono text-brand-700 mt-1 text-sm">฿ {{ tar.premium.toFixed(2) }}</div>
        </button>
      </div>
    </section>

    <!-- Common: carrier / product / dates -->
    <section class="card p-5 space-y-4">
      <div class="grid grid-cols-2 gap-4 text-sm">
        <div>
          <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.field.carrier') }}</label>
          <select v-model="form.carrierId" class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white">
            <option value="">—</option>
            <option v-for="c in carriers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.nicknameTh || c.name }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.field.product') }}</label>
          <select v-model="form.productId" class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white">
            <option value="">—</option>
            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.field.effectiveDate') }}</label>
          <DateInput v-model="form.effectiveDate" :max="form.expiryDate || undefined" />
        </div>
        <div>
          <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.field.expiryDate') }}</label>
          <DateInput v-model="form.expiryDate" :min="form.effectiveDate || undefined" />
        </div>
        <div>
          <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.field.writingAgent') }}</label>
          <input v-model="form.writingAgentId" placeholder="Agent id"
            class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs" />
        </div>
        <div>
          <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.field.newOrRenew') }}</label>
          <select v-model="form.newOrRenew" class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white">
            <option value="new">new</option>
            <option value="renew">renew</option>
          </select>
        </div>
      </div>
    </section>

    <!-- Premium calculator -->
    <section class="card p-5 space-y-4">
      <h2 class="font-semibold text-slate-900">{{ t('quotes.premium.title') }}</h2>
      <div class="flex gap-2 text-xs flex-wrap">
        <button v-for="m in (['iterativeVat', 'vatInclusive', 'fixedDuty', 'bare'] as PremiumMode[])" :key="m"
          type="button" :class="['px-2.5 py-1 rounded-md',
            calcMode === m ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-700']"
          @click="calcMode = m">{{ t(`quotes.premium.mode.${m}`) }}</button>
      </div>

      <div class="grid grid-cols-2 gap-3 text-sm">
        <div v-if="calcMode !== 'vatInclusive'">
          <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.premium.netPremium') }}</label>
          <input v-model.number="form.netPremium" type="number" step="0.01"
            class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
        </div>
        <div v-if="calcMode === 'vatInclusive'">
          <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.premium.totalPaid') }}</label>
          <input v-model.number="totalPaidInput" type="number" step="0.01"
            class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
        </div>
        <div v-if="calcMode === 'fixedDuty'">
          <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.premium.fixedDuty') }}</label>
          <input v-model.number="fixedDuty" type="number" step="0.01"
            class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
        </div>
      </div>

      <button type="button" class="text-xs text-brand-600 hover:text-brand-700"
        @click="recalc">
        <i class="pi pi-refresh text-[10px] mr-1" /> {{ t('quotes.premium.recalc') }}
      </button>

      <div class="grid grid-cols-4 gap-3 pt-3 border-t border-slate-100 text-sm">
        <div><div class="text-xs text-slate-400">{{ t('quotes.premium.net') }}</div>
          <div class="font-mono text-slate-900">฿ {{ Number(form.netPremium).toFixed(2) }}</div></div>
        <div><div class="text-xs text-slate-400">{{ t('quotes.premium.duty') }}</div>
          <div class="font-mono text-slate-900">฿ {{ Number(form.dutyStamp).toFixed(2) }}</div></div>
        <div><div class="text-xs text-slate-400">{{ t('quotes.premium.vat') }}</div>
          <div class="font-mono text-slate-900">฿ {{ Number(form.vat).toFixed(2) }}</div></div>
        <div><div class="text-xs text-slate-400">{{ t('quotes.premium.total') }}</div>
          <div class="font-mono text-brand-700 font-semibold">฿ {{ Number(form.totalPremiumPaid).toFixed(2) }}</div></div>
      </div>
    </section>

    <section class="card p-5">
      <label class="text-xs text-slate-500 mb-1 block">{{ t('quotes.field.internalNote') }}</label>
      <textarea v-model="form.internalNote" rows="3" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
    </section>

    <div class="flex justify-end gap-2">
      <button type="button" class="px-4 py-2 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50"
        @click="router.back()">{{ t('common.cancel') }}</button>
      <button type="button" :disabled="!canSubmit || saving"
        class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
        @click="submit">
        <i v-if="saving" class="pi pi-spin pi-spinner mr-2" />
        {{ isEdit ? t('quotes.save') : t('quotes.create') }}
      </button>
    </div>
  </div>
</template>
