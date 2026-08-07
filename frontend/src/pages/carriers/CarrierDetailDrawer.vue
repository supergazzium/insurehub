<script setup lang="ts">
// Carrier detail drawer — profile + list of products under this carrier.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  createCarrierBankAccount,
  createCarrierContact,
  createCarrierCredential,
  deleteCarrierBankAccount,
  deleteCarrierContact,
  deleteCarrierCredential,
  fetchCarrier,
  fetchCarrierCredentials,
  fetchCredentialLabels,
  updateCarrier,
  updateCarrierBankAccount,
  updateCarrierContact,
  updateCarrierCredential,
  type CarrierBankAccount,
  type CarrierBankAccountPayload,
  type CarrierContact,
  type CarrierContactPayload,
  type CarrierCredential,
  type CarrierCredentialPayload,
  type CarrierDetail,
} from '../../api/carriers'
import { fetchBanks, type BankOption } from '../../api/portal'
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

const { t } = useI18n()
const carrierStore = useCarrierStore()
const props = defineProps<{ carrierId: string | null }>()
const emit = defineEmits<{ (e: 'close'): void }>()

// Body-scroll lock — prevents the underlying page from scrolling while
// the drawer is open (especially important on mobile where the drawer
// is full-screen and the background scroll shouldn't compete with the
// drawer's internal overflow-y-auto).
watch(() => props.carrierId, (id) => {
  if (typeof document === 'undefined') return
  document.body.style.overflow = id ? 'hidden' : ''
})
onBeforeUnmount(() => {
  if (typeof document !== 'undefined') document.body.style.overflow = ''
})

const carrier = ref<CarrierDetail | null>(null)

// Status dropdown (Active / Inactive) — inline PATCH with optimistic UI.
// Also updates the row in the shared list store so the badge on the
// carriers list page stays in sync when this drawer closes.
const statusSaving = ref(false)
const statusError = ref<string | null>(null)
async function changeStatus(next: 'active' | 'inactive'): Promise<void> {
  if (!carrier.value) return
  const active = next === 'active'
  if (carrier.value.active === active) return
  const prev = carrier.value.active
  carrier.value.active = active
  const listRow = carrierStore.list.find((r) => r.id === carrier.value!.id)
  if (listRow) listRow.active = active
  statusSaving.value = true
  statusError.value = null
  try {
    await updateCarrier(carrier.value.id, { active })
  } catch (e: unknown) {
    carrier.value.active = prev
    if (listRow) listRow.active = prev
    statusError.value = e instanceof ApiError ? e.message : 'Status change failed.'
  } finally {
    statusSaving.value = false
  }
}

// ── Bank accounts ─────────────────────────────────────────────────────────
const bankAccounts = ref<CarrierBankAccount[]>([])
const bankSaveError = ref<string | null>(null)
const bankSavingId = ref<string | null>(null)
const bankAddingNew = ref(false)
const newBank = reactive<CarrierBankAccountPayload>({
  bankId: null, bankName: '', branch: '', accountNo: '', accountName: '', isPrimary: false,
})

// Thai bank lookup — served by /public/lookup/banks, cached 1 day server-side.
// Loaded once when the drawer first mounts; the map is used to render the
// bank name from bankId on read rows.
const bankOptions = ref<BankOption[]>([])
const bankById = computed(() => {
  const m: Record<string, BankOption> = {}
  for (const b of bankOptions.value) m[b.id] = b
  return m
})
function bankLabel(b: BankOption): string { return b.nameEn ? `${b.nameTh} (${b.nameEn})` : b.nameTh }
function bankLabelForRow(a: CarrierBankAccount): string {
  if (a.bankId && bankById.value[a.bankId]) return bankLabel(bankById.value[a.bankId])
  return a.bankName || ''
}
onMounted(async () => {
  try { bankOptions.value = (await fetchBanks()).data } catch { /* silent — dropdown just empty */ }
})

function resetNewBank(): void {
  Object.assign(newBank, { bankId: null, bankName: '', branch: '', accountNo: '', accountName: '', isPrimary: false })
}

async function saveBankAccount(a: CarrierBankAccount, field: keyof CarrierBankAccountPayload, value: unknown): Promise<void> {
  if (!props.carrierId) return
  bankSaveError.value = null
  bankSavingId.value = a.id
  try {
    // When the dropdown picks a bankId, mirror the display name into bankName
    // so the row still reads correctly on servers that don't join banks table.
    const payload: CarrierBankAccountPayload = { [field]: value } as CarrierBankAccountPayload
    if (field === 'bankId') {
      const b = value ? bankById.value[String(value)] : null
      ;(payload as CarrierBankAccountPayload).bankName = b ? b.nameTh : ''
    }
    const res = await updateCarrierBankAccount(props.carrierId, a.id, payload)
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
    const pickedBank = newBank.bankId ? bankById.value[String(newBank.bankId)] : null
    const payload: CarrierBankAccountPayload = {
      bankId: newBank.bankId || undefined,
      bankName: pickedBank ? pickedBank.nameTh : (newBank.bankName?.trim() || undefined),
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
  // Copy uses the carrier's company name as the account name — matches
  // how operators paste bank details into external forms/emails (the
  // recipient wants the payee's company name, not the per-row account
  // label). Falls back to the row's accountName if the carrier isn't
  // loaded yet for some reason.
  const accountName = carrier.value?.name || a.accountName || '-'
  const lines = [
    `Bank: ${bankLabelForRow(a) || '-'}`,
    `Branch: ${a.branch || '-'}`,
    `Account No: ${a.accountNo || '-'}`,
    `Account Name: ${accountName}`,
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

// ── Portal Credentials ────────────────────────────────────────────────────
// Per-carrier login credentials (URL + username + password + label).
// Same inline-editable-row pattern as bank accounts. Password is stored
// encrypted server-side; here we render masked by default with a per-row
// eye toggle + copy button. Label acts as a sticky-note tag with an
// autocomplete over labels this carrier has used before — click a chip
// to reuse, type to filter, Enter to commit.
const credentials = ref<CarrierCredential[]>([])
const credentialSaveError = ref<string | null>(null)
const credentialSavingId = ref<string | null>(null)
const revealedPasswords = ref<Set<string>>(new Set())
const credentialCopiedField = ref<string | null>(null) // e.g. `${id}:url`
const credentialSearch = ref('')

const newCredential = reactive<CarrierCredentialPayload>({
  url: '', username: '', password: '', label: '',
})
function resetNewCredential(): void {
  Object.assign(newCredential, { url: '', username: '', password: '', label: '' })
}

// Filter — matches on URL / username / label (case-insensitive), skips
// password on purpose so searching doesn't accidentally match secrets.
const filteredCredentials = computed(() => {
  const q = credentialSearch.value.trim().toLowerCase()
  if (!q) return credentials.value
  return credentials.value.filter((c) => {
    return (c.url || '').toLowerCase().includes(q)
      || (c.username || '').toLowerCase().includes(q)
      || (c.label || '').toLowerCase().includes(q)
  })
})

// ── Multi-label helpers ──────────────────────────────────────────────────
// A credential's `label` field is a comma-separated string on the wire
// (e.g. "Broker portal, Claims") — kept as a single string for zero-
// migration compatibility. Everywhere the UI needs to reason about
// labels, we split into an array; on save we join back.
function parseLabels(raw: string | null | undefined): string[] {
  if (!raw) return []
  return raw.split(',').map((s) => s.trim()).filter(Boolean)
}
function serializeLabels(arr: string[]): string {
  // De-dupe while preserving order (first occurrence wins).
  const seen = new Set<string>()
  const out: string[] = []
  for (const l of arr) {
    const t = l.trim()
    if (!t || seen.has(t)) continue
    seen.add(t); out.push(t)
  }
  return out.join(', ')
}
function labelsOf(c: CarrierCredential): string[] {
  return parseLabels(c.label)
}

// Tenant-wide label suggestions — populated on drawer open from
// GET /carrier-credentials/labels so operators can reuse labels created
// on OTHER carriers, not just this one. Refreshed after each save so a
// brand-new label immediately shows up in the picker.
const tenantLabels = ref<Array<{ label: string; count: number }>>([])
async function reloadTenantLabels(): Promise<void> {
  try {
    const res = await fetchCredentialLabels()
    tenantLabels.value = res.data
  } catch { /* silent — picker just shows this-carrier only */ }
}

// Reusable labels — union of tenant-wide labels + labels used on THIS
// carrier (in case the operator added one client-side that hasn't been
// re-fetched yet). Counts from both sources sum; results sort by
// frequency for speed of reuse.
const labelSuggestions = computed<string[]>(() => {
  const counts = new Map<string, number>()
  for (const { label, count } of tenantLabels.value) {
    counts.set(label, (counts.get(label) ?? 0) + count)
  }
  for (const c of credentials.value) {
    for (const l of labelsOf(c)) {
      counts.set(l, (counts.get(l) ?? 0) + 1)
    }
  }
  return [...counts.entries()]
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0], 'th'))
    .map(([label]) => label)
})

// Deterministic color hash for a label — same string → same color always,
// so the operator's eye can track "Broker portal" (blue) vs "Claims"
// (green) at a glance. Uses tailwind's 50-family for background /
// 700-family for text so the chip stays legible.
const LABEL_COLORS = [
  { bg: 'bg-sky-50', text: 'text-sky-700', border: 'border-sky-200' },
  { bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200' },
  { bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200' },
  { bg: 'bg-violet-50', text: 'text-violet-700', border: 'border-violet-200' },
  { bg: 'bg-rose-50', text: 'text-rose-700', border: 'border-rose-200' },
  { bg: 'bg-indigo-50', text: 'text-indigo-700', border: 'border-indigo-200' },
  { bg: 'bg-teal-50', text: 'text-teal-700', border: 'border-teal-200' },
  { bg: 'bg-fuchsia-50', text: 'text-fuchsia-700', border: 'border-fuchsia-200' },
]
function labelColor(label: string): { bg: string; text: string; border: string } {
  if (!label) return { bg: 'bg-slate-50', text: 'text-slate-500', border: 'border-slate-200' }
  let hash = 0
  for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0
  return LABEL_COLORS[Math.abs(hash) % LABEL_COLORS.length]
}

// Per-row label picker menu state (single row open at a time). Unlike
// the single-label version, `labelPickerDraft` here is only the text the
// operator is currently typing — the row's actual label set lives on
// the credential itself. Clicking a suggestion adds; clicking an
// existing chip on the row removes.
const labelPickerOpenFor = ref<string | null>(null) // credential id, or 'new'
const labelPickerDraft = ref('')
function openLabelPicker(id: string | 'new'): void {
  labelPickerOpenFor.value = id
  labelPickerDraft.value = ''
}
function closeLabelPicker(): void {
  labelPickerOpenFor.value = null
  labelPickerDraft.value = ''
}
// Suggestions filtered by typed input, minus labels already on this row
// (no point offering a suggestion that's already added).
function labelPickerFilteredFor(current: string[]): string[] {
  const q = labelPickerDraft.value.trim().toLowerCase()
  const currentSet = new Set(current)
  return labelSuggestions.value.filter((l) => {
    if (currentSet.has(l)) return false
    if (!q) return true
    return l.toLowerCase().includes(q)
  })
}

// Add / remove a label on an existing credential (persists via API).
async function addLabelTo(c: CarrierCredential, label: string): Promise<void> {
  const clean = label.trim()
  if (!clean) return
  const next = serializeLabels([...labelsOf(c), clean])
  if (next === (c.label || '')) return
  await saveCredential(c, 'label', next)
  labelPickerDraft.value = ''
}
async function removeLabelFrom(c: CarrierCredential, label: string): Promise<void> {
  const next = serializeLabels(labelsOf(c).filter((l) => l !== label))
  if (next === (c.label || '')) return
  await saveCredential(c, 'label', next)
}
// New-row equivalents — operate on newCredential.label (still a string).
function addLabelToNew(label: string): void {
  const clean = label.trim()
  if (!clean) return
  newCredential.label = serializeLabels([...parseLabels(newCredential.label), clean])
  labelPickerDraft.value = ''
}
function removeLabelFromNew(label: string): void {
  newCredential.label = serializeLabels(parseLabels(newCredential.label).filter((l) => l !== label))
}

async function saveCredential(c: CarrierCredential, field: keyof CarrierCredentialPayload, value: unknown): Promise<void> {
  if (!props.carrierId) return
  credentialSaveError.value = null
  credentialSavingId.value = c.id
  try {
    const res = await updateCarrierCredential(props.carrierId, c.id, { [field]: value } as CarrierCredentialPayload)
    const i = credentials.value.findIndex((x) => x.id === c.id)
    if (i >= 0) credentials.value[i] = res.data
    // Refresh tenant-wide suggestions when labels change so a brand-new
    // one immediately shows up for future carriers (and count updates).
    if (field === 'label') void reloadTenantLabels()
  } catch (e: unknown) {
    credentialSaveError.value = e instanceof ApiError ? e.message : 'Save failed'
  } finally {
    credentialSavingId.value = null
  }
}
async function addCredential(): Promise<void> {
  if (!props.carrierId) return
  credentialSaveError.value = null
  try {
    const payload: CarrierCredentialPayload = {
      url: newCredential.url?.trim() || undefined,
      username: newCredential.username?.trim() || undefined,
      password: newCredential.password || undefined,
      label: newCredential.label?.trim() || undefined,
    }
    // Don't POST empty rows — require at least a URL or username.
    if (!payload.url && !payload.username) {
      credentialSaveError.value = 'Please fill in a URL or username first.'
      return
    }
    const res = await createCarrierCredential(props.carrierId, payload)
    credentials.value.push(res.data)
    resetNewCredential()
    // New credential may have introduced new labels — refresh suggestions.
    if (payload.label) void reloadTenantLabels()
  } catch (e: unknown) {
    credentialSaveError.value = e instanceof ApiError ? e.message : 'Create failed'
  }
}
async function removeCredential(c: CarrierCredential): Promise<void> {
  if (!props.carrierId) return
  if (!window.confirm('ลบข้อมูลเข้าใช้งานนี้?')) return
  credentialSaveError.value = null
  try {
    await deleteCarrierCredential(props.carrierId, c.id)
    credentials.value = credentials.value.filter((x) => x.id !== c.id)
    revealedPasswords.value.delete(c.id)
  } catch (e: unknown) {
    credentialSaveError.value = e instanceof ApiError ? e.message : 'Delete failed'
  }
}

function togglePasswordReveal(id: string): void {
  const next = new Set(revealedPasswords.value)
  if (next.has(id)) next.delete(id); else next.add(id)
  revealedPasswords.value = next
}
async function copyCredentialField(id: string, field: 'url' | 'username' | 'password' | 'label', value: string): Promise<void> {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    credentialCopiedField.value = `${id}:${field}`
    setTimeout(() => {
      if (credentialCopiedField.value === `${id}:${field}`) credentialCopiedField.value = null
    }, 1500)
  } catch {
    credentialSaveError.value = 'Clipboard copy failed'
  }
}

/**
 * Copy the entire row as a formatted block — URL / user / password /
 * label — for the "open portal, paste credentials" workflow. Skips
 * empty fields so the pasted text stays tidy.
 */
async function copyCredentialRow(c: CarrierCredential): Promise<void> {
  const lines: string[] = []
  if (c.url) lines.push(`URL: ${c.url}`)
  if (c.username) lines.push(`Username: ${c.username}`)
  if (c.password) lines.push(`Password: ${c.password}`)
  if (c.label) lines.push(`Label: ${c.label}`)
  if (lines.length === 0) return
  try {
    await navigator.clipboard.writeText(lines.join('\n'))
    credentialCopiedField.value = `${c.id}:row`
    setTimeout(() => {
      if (credentialCopiedField.value === `${c.id}:row`) credentialCopiedField.value = null
    }, 1500)
  } catch {
    credentialSaveError.value = 'Clipboard copy failed'
  }
}

// ── Contacts ──────────────────────────────────────────────────────────────
// Individual contact people per carrier — first name, last name, phone,
// email. Same CRUD pattern as bank accounts: inline-editable rows, add-new
// row, delete button. Phone + email each get a copy-to-clipboard icon.
const contacts = ref<CarrierContact[]>([])
const contactSaveError = ref<string | null>(null)
const contactSavingId = ref<string | null>(null)
const contactAddingNew = ref(false)
const newContact = reactive<CarrierContactPayload>({
  firstName: '', lastName: '', phone: '', email: '', isPrimary: false,
})
function resetNewContact(): void {
  Object.assign(newContact, { firstName: '', lastName: '', phone: '', email: '', isPrimary: false })
}
const contactCopiedKey = ref<string | null>(null)
function copyKey(id: string, field: 'phone' | 'email'): string { return `${id}:${field}` }
async function copyToClipboard(id: string, field: 'phone' | 'email', value: string): Promise<void> {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    contactCopiedKey.value = copyKey(id, field)
    setTimeout(() => {
      if (contactCopiedKey.value === copyKey(id, field)) contactCopiedKey.value = null
    }, 1500)
  } catch {
    contactSaveError.value = 'Clipboard copy failed'
  }
}

// ── ข้อมูลหัก ณ ที่จ่าย (withholding tax) ─────────────────────────────
// Read-only display block above Bank Accounts. Fields come from the
// existing carrier record (name, address, tax_id). Each field has a
// copy-to-clipboard button; a "Copy all" formats the block for pasting
// into a WHT certificate form.
type WhtField = 'companyName' | 'address' | 'taxId' | 'branch' | 'all'
const whtCopiedKey = ref<WhtField | null>(null)
async function copyWht(field: WhtField, value: string): Promise<void> {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    whtCopiedKey.value = field
    setTimeout(() => { if (whtCopiedKey.value === field) whtCopiedKey.value = null }, 1500)
  } catch { /* ignore — clipboard errors are non-fatal */ }
}
// Thai tax ID: 13 digits → X-XXXX-XXXXX-XX-X for display; raw for copy.
function formatTaxId(raw: string | null | undefined): string {
  const d = (raw ?? '').replace(/\D/g, '')
  if (d.length !== 13) return raw ?? ''
  return `${d[0]}-${d.slice(1, 5)}-${d.slice(5, 10)}-${d.slice(10, 12)}-${d[12]}`
}
const whtBranchLabel = computed(() => 'สำนักงานใหญ่')
const whtCompanyName = computed(() => carrier.value?.name ?? '')
const whtAddress = computed(() => carrier.value?.address ?? '')
const whtTaxIdRaw = computed(() => (carrier.value?.taxId ?? '').replace(/\D/g, ''))
const whtCopyAllText = computed(() => [
  `ชื่อบริษัท: ${whtCompanyName.value}`,
  `ที่อยู่: ${whtAddress.value}`,
  `เลขประจำตัวผู้เสียภาษี: ${whtTaxIdRaw.value}`,
  `สาขา: ${whtBranchLabel.value}`,
].join('\n'))

async function saveContact(c: CarrierContact, field: keyof CarrierContactPayload, value: unknown): Promise<void> {
  if (!props.carrierId) return
  contactSaveError.value = null
  contactSavingId.value = c.id
  try {
    const res = await updateCarrierContact(props.carrierId, c.id, { [field]: value } as CarrierContactPayload)
    const i = contacts.value.findIndex((x) => x.id === c.id)
    if (i >= 0) contacts.value[i] = res.data
    if (field === 'isPrimary' && value === true) {
      contacts.value = contacts.value.map((x) => x.id === c.id ? x : { ...x, isPrimary: false })
    }
  } catch (e: unknown) {
    contactSaveError.value = e instanceof ApiError ? e.message : 'Save failed'
  } finally {
    contactSavingId.value = null
  }
}

async function addContact(): Promise<void> {
  if (!props.carrierId) return
  contactSaveError.value = null
  contactAddingNew.value = true
  try {
    const payload: CarrierContactPayload = {
      firstName: newContact.firstName?.trim() || undefined,
      lastName: newContact.lastName?.trim() || undefined,
      phone: newContact.phone?.trim() || undefined,
      email: newContact.email?.trim() || undefined,
      isPrimary: !!newContact.isPrimary,
    }
    const res = await createCarrierContact(props.carrierId, payload)
    if (res.data.isPrimary) {
      contacts.value = contacts.value.map((x) => ({ ...x, isPrimary: false }))
    }
    contacts.value.push(res.data)
    resetNewContact()
  } catch (e: unknown) {
    contactSaveError.value = e instanceof ApiError ? e.message : 'Create failed'
  } finally {
    contactAddingNew.value = false
  }
}

async function removeContact(c: CarrierContact): Promise<void> {
  if (!props.carrierId) return
  if (!window.confirm('Delete this contact?')) return
  contactSaveError.value = null
  contactSavingId.value = c.id
  try {
    await deleteCarrierContact(props.carrierId, c.id)
    contacts.value = contacts.value.filter((x) => x.id !== c.id)
  } catch (e: unknown) {
    contactSaveError.value = e instanceof ApiError ? e.message : 'Delete failed'
  } finally {
    contactSavingId.value = null
  }
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
      contacts.value = []
      credentials.value = []
      revealedPasswords.value = new Set()
      credentialSearch.value = ''
      resetNewBank()
      resetNewContact()
      resetNewCredential()
      return
    }
    loading.value = true
    errorMsg.value = null
    try {
      const car = await fetchCarrier(id)
      carrier.value = car.data
      bankAccounts.value = car.data.bankAccounts ?? []
      contacts.value = car.data.contacts ?? []
      resetNewBank()
      resetNewContact()
      resetNewCredential()
      // Credentials are a separate endpoint — the CarrierResource doesn't
      // include them (avoids leaking passwords in unrelated fetches).
      try {
        const creds = await fetchCarrierCredentials(id)
        credentials.value = creds.data
      } catch { credentials.value = [] }
      // Tenant-wide labels for the sticky-note picker — one call, cached
      // for the lifetime of the drawer session.
      void reloadTenantLabels()
      revealedPasswords.value = new Set()
      credentialSearch.value = ''
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
  <!-- Overlay: fully covers the viewport at any width. Denser at mobile
       so the underlying page can't visually bleed through. Body scroll
       is locked via the watcher in the script block. -->
  <div v-if="props.carrierId" class="fixed inset-0 bg-slate-900/60 sm:bg-slate-900/40 flex justify-end z-50" @click.self="emit('close')">
    <!-- Panel widens progressively so tables never need horizontal scroll
         on desktop while staying full-screen on mobile:
           <  640px  → full-screen (w-full, no cap)
           ≥  640px  → cap at 3xl (768px)
           ≥ 1024px  → 80vw, cap 5xl (1024px) — small laptops
           ≥ 1280px  → 78vw, cap 6xl (1152px)
           ≥ 1536px  → 75vw, cap 7xl (1280px) — 1920px screens
         `max-w-*` prevents the panel from becoming unusably wide on
         very large monitors. -->
    <div class="bg-white w-full sm:max-w-3xl lg:w-[80vw] lg:max-w-5xl xl:w-[78vw] xl:max-w-6xl 2xl:w-[75vw] 2xl:max-w-7xl h-full overflow-y-auto shadow-xl flex flex-col">
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
              <div class="flex items-center gap-2">
                <select
                  :value="carrier.active ? 'active' : 'inactive'"
                  :disabled="statusSaving"
                  :class="[
                    'px-2 py-0.5 rounded-md text-xs font-medium border focus:outline-none focus:ring-2 focus:ring-brand-100',
                    carrier.active
                      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                      : 'bg-slate-100 text-slate-600 border-slate-200',
                  ]"
                  @change="e => changeStatus((e.target as HTMLSelectElement).value as 'active' | 'inactive')">
                  <option value="active">{{ t('carriers.list.statusActive') }}</option>
                  <option value="inactive">{{ t('carriers.list.statusInactive') }}</option>
                </select>
                <i v-if="statusSaving" class="pi pi-spin pi-spinner text-brand-500 text-xs" />
              </div>
              <div v-if="statusError" class="mt-1 text-xs text-rose-600">{{ statusError }}</div>
            </div>
          </div>
        </section>

        <!-- ข้อมูลหัก ณ ที่จ่าย (Withholding tax) -->
        <section>
          <div class="flex items-center justify-between mb-2">
            <h3 class="text-xs uppercase tracking-wider text-slate-400">ข้อมูลหัก ณ ที่จ่าย</h3>
            <button type="button"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-slate-200 text-xs text-slate-600 hover:bg-slate-50 hover:text-brand-600 disabled:opacity-40 disabled:cursor-not-allowed"
              :disabled="!whtCompanyName && !whtAddress && !whtTaxIdRaw"
              :title="whtCopiedKey === 'all' ? 'Copied!' : 'Copy all withholding-tax info'"
              @click="copyWht('all', whtCopyAllText)">
              <i :class="whtCopiedKey === 'all' ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-[10px]" />
              <span>{{ whtCopiedKey === 'all' ? 'Copied' : 'Copy all' }}</span>
            </button>
          </div>
          <div class="card p-4">
            <dl class="grid grid-cols-1 sm:grid-cols-12 gap-y-3 text-sm">
              <dt class="sm:col-span-3 text-xs text-slate-400">ชื่อบริษัท</dt>
              <dd class="sm:col-span-9 flex items-start gap-2">
                <span class="flex-1 text-slate-900">{{ whtCompanyName || '—' }}</span>
                <button type="button"
                  class="text-slate-400 hover:text-brand-600 p-1 shrink-0"
                  :title="whtCopiedKey === 'companyName' ? 'Copied!' : 'Copy company name'"
                  :disabled="!whtCompanyName"
                  @click="copyWht('companyName', whtCompanyName)">
                  <i :class="whtCopiedKey === 'companyName' ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-xs" />
                </button>
              </dd>

              <dt class="sm:col-span-3 text-xs text-slate-400">ที่อยู่</dt>
              <dd class="sm:col-span-9 flex items-start gap-2">
                <span class="flex-1 text-slate-900 whitespace-pre-line">{{ whtAddress || '—' }}</span>
                <button type="button"
                  class="text-slate-400 hover:text-brand-600 p-1 shrink-0"
                  :title="whtCopiedKey === 'address' ? 'Copied!' : 'Copy address'"
                  :disabled="!whtAddress"
                  @click="copyWht('address', whtAddress)">
                  <i :class="whtCopiedKey === 'address' ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-xs" />
                </button>
              </dd>

              <dt class="sm:col-span-3 text-xs text-slate-400">เลขประจำตัวผู้เสียภาษี</dt>
              <dd class="sm:col-span-9 flex items-start gap-2">
                <span class="flex-1 text-slate-900 font-mono">{{ whtTaxIdRaw ? formatTaxId(whtTaxIdRaw) : '—' }}</span>
                <button type="button"
                  class="text-slate-400 hover:text-brand-600 p-1 shrink-0"
                  :title="whtCopiedKey === 'taxId' ? 'Copied raw digits!' : 'Copy tax ID (raw 13 digits)'"
                  :disabled="!whtTaxIdRaw"
                  @click="copyWht('taxId', whtTaxIdRaw)">
                  <i :class="whtCopiedKey === 'taxId' ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-xs" />
                </button>
              </dd>

              <dt class="sm:col-span-3 text-xs text-slate-400">สาขา</dt>
              <dd class="sm:col-span-9 flex items-start gap-2">
                <span class="flex-1 text-slate-900">{{ whtBranchLabel }}</span>
                <button type="button"
                  class="text-slate-400 hover:text-brand-600 p-1 shrink-0"
                  :title="whtCopiedKey === 'branch' ? 'Copied!' : 'Copy branch'"
                  @click="copyWht('branch', whtBranchLabel)">
                  <i :class="whtCopiedKey === 'branch' ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-xs" />
                </button>
              </dd>
            </dl>
            <p class="text-[11px] text-slate-400 mt-3">
              <i class="pi pi-info-circle mr-1" />
              แก้ไขข้อมูลได้จากส่วน Profile ด้านบน (ชื่อบริษัท / ที่อยู่ / Tax ID)
            </p>
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
            <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-3 py-2 text-left">Bank</th>
                  <th class="px-3 py-2 text-left">Branch</th>
                  <th class="px-3 py-2 text-left">Account No</th>
                  <th class="px-3 py-2 text-center">Primary</th>
                  <th class="px-3 py-2"></th>
                  <th class="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="!bankAccounts.length && !bankAddingNew">
                  <td class="px-3 py-3 text-slate-500 text-xs" colspan="6">No bank accounts yet — add one below.</td>
                </tr>
                <tr v-for="a in bankAccounts" :key="a.id" :class="{ 'opacity-60': bankSavingId === a.id }">
                  <td class="px-3 py-2">
                    <select :value="a.bankId ?? ''"
                      @change="e => saveBankAccount(a, 'bankId', (e.target as HTMLSelectElement).value || null)"
                      class="w-full border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm bg-white focus:outline-none">
                      <option value="">— เลือกธนาคาร —</option>
                      <option v-for="b in bankOptions" :key="b.id" :value="b.id">{{ bankLabel(b) }}</option>
                      <option v-if="a.bankId === null && a.bankName" :value="a.bankName" disabled>
                        {{ a.bankName }} (legacy)
                      </option>
                    </select>
                  </td>
                  <td class="px-3 py-2">
                    <input :value="a.branch" @change="e => saveBankAccount(a, 'branch', (e.target as HTMLInputElement).value)"
                      class="w-full border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input :value="a.accountNo" @change="e => saveBankAccount(a, 'accountNo', (e.target as HTMLInputElement).value)"
                      class="w-full border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none font-mono" />
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
                    <select v-model="newBank.bankId"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm bg-white focus:outline-none">
                      <option :value="null">— เลือกธนาคาร —</option>
                      <option v-for="b in bankOptions" :key="b.id" :value="b.id">{{ bankLabel(b) }}</option>
                    </select>
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.trim="newBank.branch" placeholder="สีลม"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.trim="newBank.accountNo" placeholder="001-2-34567-8"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none font-mono" />
                  </td>
                  <td class="px-3 py-2 text-center">
                    <input v-model="newBank.isPrimary" type="checkbox" />
                  </td>
                  <td class="px-3 py-2"></td>
                  <td class="px-3 py-2 text-right">
                    <button type="button"
                      class="px-2 py-1 rounded bg-brand-600 text-white hover:bg-brand-700 text-xs disabled:opacity-50 flex items-center gap-1"
                      :disabled="bankAddingNew || (!newBank.bankId && !newBank.accountNo)"
                      @click="addBankAccount">
                      <i class="pi pi-plus text-[10px]" /> Add
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            </div>
          </div>
        </section>

        <!-- Portal Credentials — URL / username / password / label per carrier portal.
             Label acts as a sticky-note tag with autocomplete over previously-used
             labels for this carrier. -->
        <section>
          <div class="flex items-center justify-between mb-2 gap-3 flex-wrap">
            <h3 class="text-xs uppercase tracking-wider text-slate-400">
              บัญชีเข้าใช้งานพอร์ทัล <span class="text-slate-500 normal-case">({{ credentials.length }})</span>
            </h3>
            <div class="relative flex-1 max-w-xs">
              <i class="pi pi-search absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs" />
              <input v-model.trim="credentialSearch" type="text"
                placeholder="ค้นหา URL / user / label"
                class="w-full border border-slate-200 rounded-md pl-7 pr-2 py-1 text-xs bg-white focus:outline-none focus:border-brand-400" />
            </div>
          </div>
          <div v-if="credentialSaveError" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm mb-2">
            {{ credentialSaveError }}
          </div>
          <div class="card overflow-visible">
            <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-3 py-2 text-left">Link</th>
                  <th class="px-3 py-2 text-left">Username</th>
                  <th class="px-3 py-2 text-left">Password</th>
                  <th class="px-3 py-2 text-left">Label</th>
                  <th class="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="!filteredCredentials.length && !credentialSearch">
                  <td class="px-3 py-3 text-slate-500 text-xs" colspan="5">
                    ยังไม่มีบัญชีเข้าใช้งาน — เพิ่มด้านล่าง
                  </td>
                </tr>
                <tr v-else-if="!filteredCredentials.length" class="text-xs text-slate-400">
                  <td class="px-3 py-3" colspan="5">ไม่พบผลการค้นหา</td>
                </tr>
                <tr v-for="c in filteredCredentials" :key="c.id"
                  :class="{ 'opacity-60': credentialSavingId === c.id }">
                  <!-- URL — inline-editable + open-link + copy -->
                  <td class="px-3 py-2">
                    <div class="flex items-center gap-1">
                      <input :value="c.url"
                        @change="e => saveCredential(c, 'url', (e.target as HTMLInputElement).value)"
                        placeholder="https://..."
                        class="flex-1 border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-xs focus:outline-none font-mono" />
                      <a :href="c.url || undefined" target="_blank" rel="noopener"
                        :class="['p-1', c.url ? 'text-slate-400 hover:text-brand-600' : 'text-slate-200 pointer-events-none']"
                        title="เปิดลิงก์">
                        <i class="pi pi-external-link text-[10px]" />
                      </a>
                      <button @click="copyCredentialField(c.id, 'url', c.url)"
                        :disabled="!c.url"
                        :class="['p-1', c.url ? 'text-slate-400 hover:text-brand-600' : 'text-slate-200 cursor-not-allowed']"
                        :title="credentialCopiedField === `${c.id}:url` ? 'Copied!' : 'Copy'">
                        <i :class="credentialCopiedField === `${c.id}:url` ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-[10px]" />
                      </button>
                    </div>
                  </td>
                  <!-- Username — inline-editable + copy -->
                  <td class="px-3 py-2">
                    <div class="flex items-center gap-1">
                      <input :value="c.username"
                        @change="e => saveCredential(c, 'username', (e.target as HTMLInputElement).value)"
                        class="flex-1 border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-xs focus:outline-none" />
                      <button @click="copyCredentialField(c.id, 'username', c.username)"
                        :disabled="!c.username"
                        :class="['p-1', c.username ? 'text-slate-400 hover:text-brand-600' : 'text-slate-200 cursor-not-allowed']"
                        :title="credentialCopiedField === `${c.id}:username` ? 'Copied!' : 'Copy'">
                        <i :class="credentialCopiedField === `${c.id}:username` ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-[10px]" />
                      </button>
                    </div>
                  </td>
                  <!-- Password — masked by default, eye-toggle, copy -->
                  <td class="px-3 py-2">
                    <div class="flex items-center gap-1">
                      <input :value="c.password"
                        :type="revealedPasswords.has(c.id) ? 'text' : 'password'"
                        @change="e => saveCredential(c, 'password', (e.target as HTMLInputElement).value)"
                        class="flex-1 border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-xs focus:outline-none font-mono" />
                      <button @click="togglePasswordReveal(c.id)"
                        class="text-slate-400 hover:text-brand-600 p-1"
                        :title="revealedPasswords.has(c.id) ? 'Hide' : 'Show'">
                        <i :class="revealedPasswords.has(c.id) ? 'pi pi-eye-slash' : 'pi pi-eye'" class="text-[10px]" />
                      </button>
                      <button @click="copyCredentialField(c.id, 'password', c.password)"
                        :disabled="!c.password"
                        :class="['p-1', c.password ? 'text-slate-400 hover:text-brand-600' : 'text-slate-200 cursor-not-allowed']"
                        :title="credentialCopiedField === `${c.id}:password` ? 'Copied!' : 'Copy'">
                        <i :class="credentialCopiedField === `${c.id}:password` ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-[10px]" />
                      </button>
                    </div>
                  </td>
                  <!-- Labels — one or more sticky chips + autocomplete picker.
                       Click a chip's × to remove; click "+" to add another
                       (either pick a used-before chip from the popup or type
                       a new one and press Enter). -->
                  <td class="px-3 py-2 relative">
                    <div class="flex items-center flex-wrap gap-1">
                      <span v-for="l in labelsOf(c)" :key="l"
                        :class="[
                          'inline-flex items-center gap-1 pl-2 pr-1 py-0.5 rounded-full border text-[11px]',
                          labelColor(l).bg, labelColor(l).text, labelColor(l).border,
                        ]">
                        <i class="pi pi-tag text-[8px]" />
                        {{ l }}
                        <button type="button" @click="removeLabelFrom(c, l)"
                          class="hover:bg-white/50 rounded-full p-0.5"
                          :title="`Remove ${l}`">
                          <i class="pi pi-times text-[8px]" />
                        </button>
                      </span>
                      <button v-if="labelPickerOpenFor !== c.id" type="button"
                        @click="openLabelPicker(c.id)"
                        class="text-slate-400 hover:text-brand-600 text-[11px] italic px-1">
                        + {{ labelsOf(c).length === 0 ? 'เพิ่มป้ายกำกับ' : 'เพิ่ม' }}
                      </button>
                      <input v-if="labelPickerOpenFor === c.id"
                        v-model="labelPickerDraft" type="text"
                        placeholder="พิมพ์แล้ว Enter"
                        @keydown.enter.prevent="() => { addLabelTo(c, labelPickerDraft) }"
                        @keydown.esc.prevent="closeLabelPicker"
                        @blur="closeLabelPicker"
                        class="min-w-[100px] flex-1 border border-brand-400 rounded px-2 py-0.5 text-xs focus:outline-none" />
                    </div>
                    <!-- Suggestions popup — reusable labels not yet on this row. -->
                    <div v-if="labelPickerOpenFor === c.id && labelPickerFilteredFor(labelsOf(c)).length > 0"
                      class="absolute z-30 left-3 top-full mt-1 rounded-lg border border-slate-200 bg-white shadow-lg p-2 max-w-md flex flex-wrap gap-1.5">
                      <button v-for="l in labelPickerFilteredFor(labelsOf(c))" :key="l" type="button"
                        @mousedown.prevent="() => addLabelTo(c, l)"
                        :class="[
                          'inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px]',
                          labelColor(l).bg, labelColor(l).text, labelColor(l).border,
                          'hover:ring-2 hover:ring-brand-100',
                        ]">
                        <i class="pi pi-tag text-[8px]" />
                        {{ l }}
                      </button>
                    </div>
                  </td>
                  <!-- Actions: copy-all-row + delete -->
                  <td class="px-3 py-2 text-right whitespace-nowrap">
                    <button @click="copyCredentialRow(c)"
                      :class="['p-1 mr-1',
                        credentialCopiedField === `${c.id}:row`
                          ? 'text-emerald-600'
                          : 'text-slate-400 hover:text-brand-600']"
                      :title="credentialCopiedField === `${c.id}:row` ? 'Copied all!' : 'Copy URL + user + password'">
                      <i :class="credentialCopiedField === `${c.id}:row` ? 'pi pi-check' : 'pi pi-clone'" class="text-xs" />
                    </button>
                    <button class="text-slate-400 hover:text-rose-600 p-1" title="Delete"
                      @click="removeCredential(c)">
                      <i class="pi pi-trash text-xs" />
                    </button>
                  </td>
                </tr>
                <!-- New row — always visible at the bottom, saves on "+ Add" click. -->
                <tr class="bg-slate-50/50">
                  <td class="px-3 py-2">
                    <input v-model.trim="newCredential.url" placeholder="https://..."
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-xs focus:outline-none font-mono" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.trim="newCredential.username" placeholder="ชื่อผู้ใช้"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-xs focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="newCredential.password" type="password" placeholder="รหัสผ่าน"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-xs focus:outline-none font-mono" />
                  </td>
                  <td class="px-3 py-2 relative">
                    <div class="flex items-center flex-wrap gap-1">
                      <span v-for="l in parseLabels(newCredential.label)" :key="l"
                        :class="[
                          'inline-flex items-center gap-1 pl-2 pr-1 py-0.5 rounded-full border text-[11px]',
                          labelColor(l).bg, labelColor(l).text, labelColor(l).border,
                        ]">
                        <i class="pi pi-tag text-[8px]" />
                        {{ l }}
                        <button type="button" @click="removeLabelFromNew(l)"
                          class="hover:bg-white/50 rounded-full p-0.5"
                          :title="`Remove ${l}`">
                          <i class="pi pi-times text-[8px]" />
                        </button>
                      </span>
                      <button v-if="labelPickerOpenFor !== 'new'" type="button"
                        @click="openLabelPicker('new')"
                        class="text-slate-400 hover:text-brand-600 text-[11px] italic px-1">
                        + {{ parseLabels(newCredential.label).length === 0 ? 'เพิ่มป้ายกำกับ' : 'เพิ่ม' }}
                      </button>
                      <input v-if="labelPickerOpenFor === 'new'"
                        v-model="labelPickerDraft" type="text"
                        placeholder="พิมพ์แล้ว Enter"
                        @keydown.enter.prevent="() => addLabelToNew(labelPickerDraft)"
                        @keydown.esc.prevent="closeLabelPicker"
                        @blur="closeLabelPicker"
                        class="min-w-[100px] flex-1 border border-brand-400 rounded px-2 py-0.5 text-xs focus:outline-none" />
                    </div>
                    <div v-if="labelPickerOpenFor === 'new' && labelPickerFilteredFor(parseLabels(newCredential.label)).length > 0"
                      class="absolute z-30 left-3 top-full mt-1 rounded-lg border border-slate-200 bg-white shadow-lg p-2 max-w-md flex flex-wrap gap-1.5">
                      <button v-for="l in labelPickerFilteredFor(parseLabels(newCredential.label))" :key="l" type="button"
                        @mousedown.prevent="() => addLabelToNew(l)"
                        :class="[
                          'inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px]',
                          labelColor(l).bg, labelColor(l).text, labelColor(l).border,
                          'hover:ring-2 hover:ring-brand-100',
                        ]">
                        <i class="pi pi-tag text-[8px]" />
                        {{ l }}
                      </button>
                    </div>
                  </td>
                  <td class="px-3 py-2 text-right">
                    <button @click="addCredential"
                      class="text-brand-600 hover:text-brand-700 text-xs font-medium">
                      <i class="pi pi-plus text-[10px] mr-1" /> Add
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            </div>
          </div>
        </section>

        <!-- Contacts -->
        <section>
          <div class="flex items-center justify-between mb-2">
            <h3 class="text-xs uppercase tracking-wider text-slate-400">
              Contacts <span class="text-slate-500 normal-case">({{ contacts.length }})</span>
            </h3>
          </div>
          <div v-if="contactSaveError" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm mb-2">
            {{ contactSaveError }}
          </div>
          <div class="card overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-3 py-2 text-left">First name</th>
                  <th class="px-3 py-2 text-left">Last name</th>
                  <th class="px-3 py-2 text-left">Phone</th>
                  <th class="px-3 py-2 text-left">Email</th>
                  <th class="px-3 py-2 text-center">Primary</th>
                  <th class="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="!contacts.length && !contactAddingNew">
                  <td class="px-3 py-3 text-slate-500 text-xs" colspan="6">No contacts yet — add one below.</td>
                </tr>
                <tr v-for="c in contacts" :key="c.id" :class="{ 'opacity-60': contactSavingId === c.id }">
                  <td class="px-3 py-2">
                    <input :value="c.firstName" lang="th"
                      @change="e => saveContact(c, 'firstName', (e.target as HTMLInputElement).value)"
                      class="w-full border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input :value="c.lastName" lang="th"
                      @change="e => saveContact(c, 'lastName', (e.target as HTMLInputElement).value)"
                      class="w-full border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <div class="flex items-center gap-1">
                      <input :value="c.phone" type="tel" inputmode="tel"
                        @change="e => saveContact(c, 'phone', (e.target as HTMLInputElement).value)"
                        class="flex-1 border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none font-mono" />
                      <button type="button" class="text-slate-400 hover:text-brand-600 p-1 shrink-0"
                        :title="contactCopiedKey === copyKey(c.id, 'phone') ? 'Copied!' : 'Copy phone'"
                        :disabled="!c.phone"
                        @click="copyToClipboard(c.id, 'phone', c.phone)">
                        <i :class="contactCopiedKey === copyKey(c.id, 'phone') ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-xs" />
                      </button>
                    </div>
                  </td>
                  <td class="px-3 py-2">
                    <div class="flex items-center gap-1">
                      <input :value="c.email" type="email"
                        @change="e => saveContact(c, 'email', (e.target as HTMLInputElement).value)"
                        class="flex-1 border border-transparent hover:border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                      <button type="button" class="text-slate-400 hover:text-brand-600 p-1 shrink-0"
                        :title="contactCopiedKey === copyKey(c.id, 'email') ? 'Copied!' : 'Copy email'"
                        :disabled="!c.email"
                        @click="copyToClipboard(c.id, 'email', c.email)">
                        <i :class="contactCopiedKey === copyKey(c.id, 'email') ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-xs" />
                      </button>
                    </div>
                  </td>
                  <td class="px-3 py-2 text-center">
                    <input type="checkbox" :checked="c.isPrimary"
                      @change="e => saveContact(c, 'isPrimary', (e.target as HTMLInputElement).checked)" />
                  </td>
                  <td class="px-3 py-2 text-right">
                    <button class="text-slate-400 hover:text-rose-600 p-1" title="Delete" @click="removeContact(c)">
                      <i class="pi pi-trash text-xs" />
                    </button>
                  </td>
                </tr>
                <tr class="bg-slate-50/50">
                  <td class="px-3 py-2">
                    <input v-model.trim="newContact.firstName" placeholder="ชื่อ" lang="th"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.trim="newContact.lastName" placeholder="นามสกุล" lang="th"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.trim="newContact.phone" placeholder="08x-xxx-xxxx" type="tel" inputmode="tel"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none font-mono" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.trim="newContact.email" placeholder="name@example.com" type="email"
                      class="w-full border border-slate-200 focus:border-brand-400 rounded px-2 py-1 text-sm focus:outline-none" />
                  </td>
                  <td class="px-3 py-2 text-center">
                    <input v-model="newContact.isPrimary" type="checkbox" />
                  </td>
                  <td class="px-3 py-2 text-right">
                    <button type="button"
                      class="px-2 py-1 rounded bg-brand-600 text-white hover:bg-brand-700 text-xs disabled:opacity-50 flex items-center gap-1"
                      :disabled="contactAddingNew || (!newContact.firstName && !newContact.email && !newContact.phone)"
                      @click="addContact">
                      <i class="pi pi-plus text-[10px]" /> Add
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            </div>
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
            <div class="overflow-x-auto">
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
                  <!-- Truncation caps relax at larger breakpoints so the
                       extra panel width flows into the name / category
                       columns instead of getting eaten by whitespace. -->
                  <td class="px-4 py-2 text-slate-900 truncate max-w-[240px] lg:max-w-[400px] xl:max-w-[560px]">{{ p.name }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ p.type || '—' }}</td>
                  <td class="px-4 py-2 text-slate-700">{{ p.mainRider || '—' }}</td>
                  <td class="px-4 py-2 text-slate-700 truncate max-w-[160px] lg:max-w-[280px] xl:max-w-[360px]">{{ p.category || '—' }}</td>
                  <td class="px-4 py-2">
                    <span v-if="p.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">active</span>
                    <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">inactive</span>
                  </td>
                </tr>
              </tbody>
            </table>
            </div>
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
