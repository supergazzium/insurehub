<script setup lang="ts">
// Carrier detail drawer — profile + list of products under this carrier.
import { reactive, ref, watch } from 'vue'
import {
  createCarrierBankAccount,
  deleteCarrierBankAccount,
  fetchCarrier,
  updateCarrierBankAccount,
  type CarrierBankAccount,
  type CarrierBankAccountPayload,
  type CarrierDetail,
} from '../../api/carriers'
import { fetchProductList, type ProductListRow } from '../../api/products'
import EditableField from '../../components/EditableField.vue'
import DeleteConfirmDialog from '../../components/DeleteConfirmDialog.vue'
import { api, ApiError } from '../../api/client'
import { useCarrierStore } from '../../stores/carriers'

const INSURE_TYPE_OPTIONS = [
  { value: 'life', label: 'Life' },
  { value: 'non-life', label: 'Non-life' },
  { value: 'tax', label: 'Tax' },
]

const SUB_TYPE_OPTIONS = [
  { value: 'direct', label: 'Direct' },
  { value: 'partner', label: 'Partner' },
]

const carrierStore = useCarrierStore()
const props = defineProps<{ carrierId: string | null }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const carrier = ref<CarrierDetail | null>(null)

// ── Bank accounts ─────────────────────────────────────────────────────────
const bankAccounts = ref<CarrierBankAccount[]>([])
const bankSaveError = ref<string | null>(null)
const bankSavingId = ref<string | null>(null)
const bankAddingNew = ref(false)
const newBank = reactive<CarrierBankAccountPayload>({
  bankName: '', branch: '', accountNo: '', accountName: '', isPrimary: false,
})

function resetNewBank(): void {
  Object.assign(newBank, { bankName: '', branch: '', accountNo: '', accountName: '', isPrimary: false })
}

async function saveBankAccount(a: CarrierBankAccount, field: keyof CarrierBankAccountPayload, value: unknown): Promise<void> {
  if (!props.carrierId) return
  bankSaveError.value = null
  bankSavingId.value = a.id
  try {
    const res = await updateCarrierBankAccount(props.carrierId, a.id, { [field]: value } as CarrierBankAccountPayload)
    const i = bankAccounts.value.findIndex((x) => x.id === a.id)
    if (i >= 0) bankAccounts.value[i] = res.data
    if (field === 'isPrimary' && value === true) {
      bankAccounts.value = bankAccounts.value.map((x) => x.id === a.id ? x : { ...x, isPrimary: false })
    }
  } catch (e: unknown) {
    bankSaveError.value = e instanceof ApiError ? e.message : 'Save failed'
  } finally {
    bankSavingId.value = null
  }
}

async function addBankAccount(): Promise<void> {
  if (!props.carrierId) return
  bankSaveError.value = null
  bankAddingNew.value = true
  try {
    const payload: CarrierBankAccountPayload = {
      bankName: newBank.bankName?.trim() || undefined,
      branch: newBank.branch?.trim() || undefined,
      accountNo: newBank.accountNo?.trim() || undefined,
      accountName: newBank.accountName?.trim() || undefined,
      isPrimary: !!newBank.isPrimary,
    }
    const res = await createCarrierBankAccount(props.carrierId, payload)
    if (res.data.isPrimary) {
      bankAccounts.value = bankAccounts.value.map((x) => ({ ...x, isPrimary: false }))
    }
    bankAccounts.value.push(res.data)
    resetNewBank()
  } catch (e: unknown) {
    bankSaveError.value = e instanceof ApiError ? e.message : 'Create failed'
  } finally {
    bankAddingNew.value = false
  }
}

async function removeBankAccount(a: CarrierBankAccount): Promise<void> {
  if (!props.carrierId) return
  if (!window.confirm('Delete this bank account?')) return
  bankSaveError.value = null
  bankSavingId.value = a.id
  try {
    await deleteCarrierBankAccount(props.carrierId, a.id)
    bankAccounts.value = bankAccounts.value.filter((x) => x.id !== a.id)
  } catch (e: unknown) {
    bankSaveError.value = e instanceof ApiError ? e.message : 'Delete failed'
  } finally {
    bankSavingId.value = null
  }
}

const bankCopiedId = ref<string | 'all' | null>(null)

function formatBankAccount(a: CarrierBankAccount): string {
  const lines = [
    `Bank: ${a.bankName || '-'}`,
    `Branch: ${a.branch || '-'}`,
    `Account No: ${a.accountNo || '-'}`,
    `Account Name: ${a.accountName || '-'}`,
  ]
  return lines.join('\n')
}

async function copyBankAccount(a: CarrierBankAccount): Promise<void> {
  try {
    await navigator.clipboard.writeText(formatBankAccount(a))
    bankCopiedId.value = a.id
    setTimeout(() => { if (bankCopiedId.value === a.id) bankCopiedId.value = null }, 1500)
  } catch {
    bankSaveError.value = 'Clipboard copy failed'
  }
}

async function copyAllBankAccounts(): Promise<void> {
  if (!bankAccounts.value.length) return
  try {
    const text = bankAccounts.value.map(formatBankAccount).join('\n\n')
    await navigator.clipboard.writeText(text)
    bankCopiedId.value = 'all'
    setTimeout(() => { if (bankCopiedId.value === 'all') bankCopiedId.value = null }, 1500)
  } catch {
    bankSaveError.value = 'Clipboard copy failed'
  }
}

// ── Delete ────────────────────────────────────────────────────────────────
const showDelete = ref(false)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

async function doDelete(): Promise<void> {
  if (!props.carrierId) return
  deleting.value = true
  deleteError.value = null
  try {
    await api.delete(`carriers/${props.carrierId}`)
    await carrierStore.loadPage({})
    showDelete.value = false
    emit('close')
  } catch (e: unknown) {
    deleteError.value = e instanceof ApiError ? e.message : 'Delete failed'
  } finally {
    deleting.value = false
  }
}

function apply(pathKey: string, v: unknown): void {
  if (!carrier.value) return
  const parts = pathKey.split('.')
  let obj: Record<string, unknown> = carrier.value as unknown as Record<string, unknown>
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
const products = ref<ProductListRow[]>([])
const productsMeta = ref<{ total: number; lastPage: number } | null>(null)
const loading = ref(false)
const productsLoading = ref(false)
const errorMsg = ref<string | null>(null)

watch(
  () => props.carrierId,
  async (id) => {
    if (!id) {
      carrier.value = null
      products.value = []
      productsMeta.value = null
      bankAccounts.value = []
      resetNewBank()
      return
    }
    loading.value = true
    errorMsg.value = null
    try {
      const car = await fetchCarrier(id)
      carrier.value = car.data
      bankAccounts.value = car.data.bankAccounts ?? []
      resetNewBank()
      productsLoading.value = true
      const prods = await fetchProductList({ carrierId: id, perPage: 100 })
      products.value = prods.data
      productsMeta.value = prods.meta
        ? { total: prods.meta.total, lastPage: prods.meta.last_page }
        : null
    } catch (e: unknown) {
      errorMsg.value = e instanceof Error ? e.message : 'Failed to load carrier detail.'
    } finally {
      loading.value = false
      productsLoading.value = false
    }
  },
  { immediate: true },
)

function typeBadge(insureType: string): string {
  return {
    life: 'bg-emerald-50 text-emerald-700',
    'non-life': 'bg-sky-50 text-sky-700',
    tax: 'bg-violet-50 text-violet-700',
  }[insureType] ?? 'bg-slate-100 text-slate-600'
}
</script>

<template>
  <div v-if="props.carrierId" class="fixed inset-0 bg-slate-900/40 flex justify-end z-50" @click.self="emit('close')">
    <div class="bg-white w-full max-w-3xl h-full overflow-y-auto shadow-xl flex flex-col">
      <header class="px-6 py-4 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white z-10">
        <div v-if="carrier">
          <div class="flex items-center gap-2 text-xs uppercase text-slate-400">
            <span class="font-mono">{{ carrier.code }}</span>
            <span v-if="carrier.insureType">·</span>
            <span v-if="carrier.insureType" :class="['inline-flex px-2 py-0.5 rounded-md text-[10px] font-medium', typeBadge(carrier.insureType)]">{{ carrier.insureType }}</span>
          </div>
          <div class="text-lg font-semibold text-slate-900 mt-1">{{ carrier.nicknameTh || carrier.name }}</div>
          <div v-if="carrier.name && carrier.name !== carrier.nicknameTh" class="text-xs text-slate-500 mt-0.5">{{ carrier.name }}</div>
        </div>
        <div v-else class="text-slate-500">Loading…</div>
        <button class="text-slate-400 hover:text-slate-700 p-2" @click="emit('close')">
          <i class="pi pi-times" />
        </button>
      </header>

      <div v-if="errorMsg" class="m-6 p-4 bg-rose-50 border border-rose-200 rounded-lg text-rose-700 text-sm">
        {{ errorMsg }}
      </div>

      <div v-if="carrier" class="flex-1 p-6 space-y-6">
        <!-- Profile (editable) -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Profile</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div><div class="text-xs text-slate-400">Code</div>
              <EditableField entity="carriers" :id="carrier.id" field="code" :value="carrier.code" @update="v => apply('code', v)" /></div>
            <div><div class="text-xs text-slate-400">Type</div>
              <EditableField entity="carriers" :id="carrier.id" field="type" type="select" :options="INSURE_TYPE_OPTIONS" :value="carrier.insureType" @update="v => apply('insureType', v)" /></div>
            <div><div class="text-xs text-slate-400">Sub-type</div>
              <EditableField entity="carriers" :id="carrier.id" field="subType" type="select" :options="SUB_TYPE_OPTIONS" :value="carrier.subType" @update="v => apply('subType', v)" /></div>
            <div><div class="text-xs text-slate-400">OIC Insurance Code</div>
              <EditableField entity="carriers" :id="carrier.id" field="oicInsureComCode" :value="carrier.oicInsureComCode" @update="v => apply('oicInsureComCode', v)" /></div>
            <div><div class="text-xs text-slate-400">Company Insure Code</div>
              <EditableField entity="carriers" :id="carrier.id" field="compInsureCode" :value="carrier.compInsureCode" @update="v => apply('compInsureCode', v)" /></div>
            <div><div class="text-xs text-slate-400">Tax ID</div>
              <EditableField entity="carriers" :id="carrier.id" field="taxId" :value="carrier.taxId" @update="v => apply('taxId', v)" /></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Name (Thai)</div>
              <EditableField entity="carriers" :id="carrier.id" field="name" :value="carrier.name" @update="v => apply('name', v)" /></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Name (English)</div>
              <EditableField entity="carriers" :id="carrier.id" field="nameEn" :value="carrier.nameEn" @update="v => apply('nameEn', v)" /></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Nickname</div>
              <EditableField entity="carriers" :id="carrier.id" field="nicknameTh" :value="carrier.nicknameTh" @update="v => apply('nicknameTh', v)" /></div>
            <div><div class="text-xs text-slate-400">Phone</div>
              <EditableField entity="carriers" :id="carrier.id" field="phone" :value="carrier.phone" @update="v => apply('phone', v)" /></div>
            <div><div class="text-xs text-slate-400">Email</div>
              <EditableField entity="carriers" :id="carrier.id" field="email" :value="carrier.email" @update="v => apply('email', v)" /></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Website</div>
              <EditableField entity="carriers" :id="carrier.id" field="website" :value="carrier.website" @update="v => apply('website', v)" /></div>
            <div><div class="text-xs text-slate-400">Products</div><div class="font-medium text-slate-900">{{ carrier.productCount.toLocaleString() }}</div></div>
            <div><div class="text-xs text-slate-400">Contracts</div><div class="font-medium text-slate-900">{{ carrier.contractCount.toLocaleString() }}</div></div>
            <div><div class="text-xs text-slate-400">Status</div>
              <span v-if="carrier.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">active</span>
              <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">inactive</span>
            </div>
          </div>
        </section>

        <!-- Bank accounts -->
        <section>
          <div class="flex items-center justify-between mb-2">
            <h3 class="text-xs uppercase tracking-wider text-slate-400">
              Bank Accounts <span class="text-slate-500 normal-case">({{ bankAccounts.length }})</span>
            </h3>
            <button type="button"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-slate-200 text-xs text-slate-600 hover:bg-slate-50 hover:text-brand-600 disabled:opacity-40 disabled:cursor-not-allowed"
              :disabled="!bankAccounts.length"
              :title="bankCopiedId === 'all' ? 'Copied!' : 'Copy all bank accounts'"
              @click="copyAllBankAccounts">
              <i :class="bankCopiedId === 'all' ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-[10px]" />
              <span>{{ bankCopiedId === 'all' ? 'Copied' : 'Copy all' }}</span>
            </button>
          </div>
          <div v-if="bankSaveError" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm mb-2">
            {{ bankSaveError }}
          </div>
          <div class="card overflow-hidden">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-3 py-2 text-left">Bank</th>
                  <th class="px-3 py-2 text-left">Branch</th>
                  <th class="px-3 py-2 text-left">Account No</th>
                  <th class="px-3 py-2 text-left">Account Name</th>
                  <th class="px-3 py-2 text-center">Primary</th>
                  <th class="px-3 py-2"></th>
                  <th class="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="!bankAccounts.length && !bankAddingNew">
                  <td class="px-3 py-3 text-slate-500 text-xs" colspan="7">No bank accounts yet — add one below.</td>
                </tr>
                <tr v-for="a in bankAccounts" :key="a.id" :class="{ 'opacity-60': bankSavingId === a.id }">
                  <td class="px-3 py-2">
                    <input :value="a.bankName" @change="e => saveBankAccount(a, 'bankName', (e.target as HTMLInputElement).value)"
                      class="w-full border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input :value="a.branch" @change="e => saveBankAccount(a, 'branch', (e.target as HTMLInputElement).value)"
                      class="w-full border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input :value="a.accountNo" @change="e => saveBankAccount(a, 'accountNo', (e.target as HTMLInputElement).value)"
                      class="w-full border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none font-mono" />
                  </td>
                  <td class="px-3 py-2">
                    <input :value="a.accountName" @change="e => saveBankAccount(a, 'accountName', (e.target as HTMLInputElement).value)"
                      class="w-full border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2 text-center">
                    <input type="checkbox" :checked="a.isPrimary"
                      @change="e => saveBankAccount(a, 'isPrimary', (e.target as HTMLInputElement).checked)" />
                  </td>
                  <td class="px-3 py-2 text-center">
                    <button class="text-slate-400 hover:text-brand-600 p-1"
                      :title="bankCopiedId === a.id ? 'Copied!' : 'Copy bank info'"
                      @click="copyBankAccount(a)">
                      <i :class="bankCopiedId === a.id ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-xs" />
                    </button>
                  </td>
                  <td class="px-3 py-2 text-right">
                    <button class="text-slate-400 hover:text-rose-600 p-1" title="Delete" @click="removeBankAccount(a)">
                      <i class="pi pi-trash text-xs" />
                    </button>
                  </td>
                </tr>
                <tr class="bg-slate-50/50">
                  <td class="px-3 py-2">
                    <input v-model.trim="newBank.bankName" placeholder="กสิกรไทย"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.trim="newBank.branch" placeholder="สีลม"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.trim="newBank.accountNo" placeholder="001-2-34567-8"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none font-mono" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.trim="newBank.accountName" placeholder="ชื่อบัญชี"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2 text-center">
                    <input v-model="newBank.isPrimary" type="checkbox" />
                  </td>
                  <td class="px-3 py-2"></td>
                  <td class="px-3 py-2 text-right">
                    <button type="button"
                      class="px-2 py-1 rounded bg-brand-600 text-white hover:bg-brand-700 text-xs disabled:opacity-50 flex items-center gap-1"
                      :disabled="bankAddingNew || (!newBank.bankName && !newBank.accountNo)"
                      @click="addBankAccount">
                      <i class="pi pi-plus text-[10px]" /> Add
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Products under this carrier -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">
            Products <span class="text-slate-500 normal-case">({{ productsMeta?.total ?? products.length }})</span>
          </h3>
          <div v-if="productsLoading" class="card p-4 text-sm text-slate-500">Loading products…</div>
          <div v-else-if="!products.length" class="card p-4 text-sm text-slate-500">No products for this carrier.</div>
          <div v-else class="card overflow-hidden">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-4 py-2 text-left">Code</th>
                  <th class="px-4 py-2 text-left">Name</th>
                  <th class="px-4 py-2 text-left">Type</th>
                  <th class="px-4 py-2 text-left">Main / Rider</th>
                  <th class="px-4 py-2 text-left">Category</th>
                  <th class="px-4 py-2 text-left">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="p in products" :key="p.id">
                  <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ p.code }}</td>
                  <td class="px-4 py-2 text-slate-900 truncate max-w-[240px]">{{ p.name }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ p.type || '—' }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ p.mainRider || '—' }}</td>
                  <td class="px-4 py-2 text-slate-700 truncate max-w-[160px]">{{ p.category || '—' }}</td>
                  <td class="px-4 py-2">
                    <span v-if="p.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">active</span>
                    <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">inactive</span>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-if="productsMeta && productsMeta.lastPage > 1"
              class="px-4 py-2 text-xs text-slate-500 border-t border-slate-100">
              Showing first 100 of {{ productsMeta.total }} products
            </div>
          </div>
        </section>

        <div v-if="loading" class="text-center text-slate-500 py-4">Loading…</div>
      </div>

      <!-- Footer -->
      <footer v-if="carrier" class="border-t border-slate-200 px-6 py-3 flex items-center justify-between sticky bottom-0 bg-white">
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
      v-if="carrier"
      :open="showDelete"
      :label="`carrier ${carrier.code}`"
      :confirm-token="carrier.code"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
      @cancel="showDelete = false"
    />
  </div>
</template>
