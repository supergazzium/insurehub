<script setup lang="ts">
// สร้าง / แก้ไข ตัวแทน — full-page form (mirrors the customer create/edit style).
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormField from '../../components/FormField.vue'
import DateInput from '../../components/DateInput.vue'
import AddressPicker from '../../components/AddressPicker.vue'
import AgentPicker from '../../components/AgentPicker.vue'
import AgentsSubnav from './AgentsSubnav.vue'
import { levelBadgeClass } from '../../utils/agentLevel'
import { fetchAgent, createAgentFull, updateAgentFull } from '../../api/agents'
import { fetchAgentOutstandingDetail } from '../../api/commissionPayouts'
import { ApiError } from '../../api/client'

const route = useRoute()
const router = useRouter()
const editId = computed(() => (route.name === 'agent-edit' ? String(route.params.id) : null))
const isEdit = computed(() => editId.value !== null)

// AddressPicker (edit mode) emits (fieldKey, value) after it PATCHes agents/{id};
// mirror the value into the local form so displayed state stays in sync.
function applyAddress(field: string, value: string | null): void {
  if (field === 'province') form.province = value ?? ''
  else if (field === 'district') form.district = value ?? ''
  else if (field === 'subDistrict') form.subDistrict = value ?? ''
  else if (field === 'postcode') form.postcode = value ?? ''
}

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const currentLevel = ref<number | null>(null)
const originalType = ref<'AG' | 'IN'>('IN')
const original = reactive({ kind: 'individual', hasVat: false, vatMode: '' as string, taxId: '', juristicName: '' })

const form = reactive({
  agentCode: '',
  agentType: 'IN' as 'AG' | 'IN',
  kind: 'individual' as 'individual' | 'corporate',
  firstName: '', lastName: '', firstNameEn: '', lastNameEn: '', nickname: '',
  gender: '' as '' | 'male' | 'female' | 'other',
  email: '', phone: '', lineId: '',
  idCard: '', birthDate: '',
  juristicName: '', taxId: '',
  hasVat: false, vatMode: 'include' as 'include' | 'exclude',
  whtRate: '' as string | number,
  address: '', province: '', district: '', subDistrict: '', postcode: '',
  bankNameText: '', bankAccountNo: '', bankAccountName: '',
  licenseLifeNo: '', licenseLifeExpiry: '',
  licenseNonLifeNo: '', licenseNonLifeExpiry: '',
  parentAgentId: '',
  joinedAt: '',
  notes: '',
  active: true,
})

const taxIdRequired = computed(() => form.kind === 'corporate' || form.hasVat)
const agentNameOrCode = computed(() => (form.kind === 'corporate' && form.juristicName) || `${form.firstName} ${form.lastName}`.trim() || form.agentCode)

function s(k: string, v: unknown): void { (form as Record<string, unknown>)[k] = v == null ? '' : v }

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    if (isEdit.value) {
      const res = await fetchAgent(editId.value!)
      const d = res.data as Record<string, unknown>
      const bank = (d.bank ?? {}) as Record<string, unknown>
      form.agentCode = String(d.agentCode ?? '')
      form.agentType = (d.agentType as 'AG' | 'IN') || 'IN'
      originalType.value = form.agentType
      form.kind = (d.kind as 'individual' | 'corporate') || 'individual'
      s('firstName', d.firstName); s('lastName', d.lastName)
      s('firstNameEn', d.firstNameEn); s('lastNameEn', d.lastNameEn)
      s('nickname', d.nickname); form.gender = (d.gender as '') || ''
      s('email', d.email); s('phone', d.phone); s('lineId', d.lineId)
      s('idCard', d.idCard); s('birthDate', d.birthDate)
      s('juristicName', d.juristicName); s('taxId', d.taxId)
      s('address', d.address); s('province', d.province); s('district', d.district)
      s('subDistrict', d.subDistrict); s('postcode', d.postcode)
      s('bankNameText', bank.bankName ?? d.bankNameText)
      s('bankAccountNo', bank.accountNo ?? d.bankAccountNo)
      s('bankAccountName', bank.accountName ?? d.bankAccountName)
      s('licenseLifeNo', d.licenseLifeNo); s('licenseLifeExpiry', d.licenseLifeExpiry)
      s('licenseNonLifeNo', d.licenseNonLifeNo); s('licenseNonLifeExpiry', d.licenseNonLifeExpiry)
      s('parentAgentId', d.parentAgentId)
      currentLevel.value = typeof d.rankLevel === 'number' ? d.rankLevel : null
      s('joinedAt', d.joinedAt); s('notes', d.notes)
      form.active = d.active !== false
      form.hasVat = d.hasVat === true
      form.vatMode = (d.vatMode as 'include' | 'exclude') || 'include'
      form.whtRate = d.whtRate === null || d.whtRate === undefined ? '' : Number(d.whtRate)
      Object.assign(original, {
        kind: form.kind, hasVat: form.hasVat, vatMode: form.hasVat ? form.vatMode : '', taxId: form.taxId, juristicName: form.juristicName,
      })
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally { loading.value = false }
}

const payload = computed<Record<string, unknown>>(() => {
  const p: Record<string, unknown> = {
    agentCode: form.agentCode.trim(),
    agentType: isEdit.value ? form.agentType : 'IN',
    kind: form.kind,
    firstName: form.firstName || null,
    lastName: form.lastName || null,
    firstNameEn: form.firstNameEn || null,
    lastNameEn: form.lastNameEn || null,
    nickname: form.nickname || null,
    gender: form.gender || null,
    email: form.email || null,
    phone: form.phone || null,
    lineId: form.lineId || null,
    idCard: form.idCard || null,
    birthDate: form.birthDate || null,
    juristicName: form.juristicName || null,
    taxId: form.taxId || null,
    hasVat: form.hasVat,
    vatMode: form.hasVat ? form.vatMode : null,
    whtRate: form.whtRate === '' || form.whtRate === null ? null : Number(form.whtRate),
    address: form.address || null,
    province: form.province || null,
    district: form.district || null,
    subDistrict: form.subDistrict || null,
    postcode: form.postcode || null,
    licenseLifeNo: form.licenseLifeNo || null,
    licenseLifeExpiry: form.licenseLifeExpiry || null,
    licenseNonLifeNo: form.licenseNonLifeNo || null,
    licenseNonLifeExpiry: form.licenseNonLifeExpiry || null,
    parentAgentId: form.parentAgentId || null,
    joinedAt: form.joinedAt || null,
    notes: form.notes || null,
    active: form.active,
    bank: { bankName: form.bankNameText || null, accountNo: form.bankAccountNo || null, accountName: form.bankAccountName || null },
  }
  return p
})

const kindLabel = (k: string) => (k === 'corporate' ? 'นิติบุคคล' : 'บุคคลธรรมดา')
const vatText = (has: boolean, mode: string) => (!has ? 'ไม่มี VAT' : mode === 'include' ? 'มี VAT — รวม VAT' : 'มี VAT — ไม่รวม VAT')
const taxChanges = computed(() => {
  if (!isEdit.value) return []
  const out: { label: string; before: string; after: string }[] = []
  if (original.kind !== form.kind) out.push({ label: 'ชนิด', before: kindLabel(original.kind), after: kindLabel(form.kind) })
  const beforeVat = vatText(original.hasVat, original.vatMode)
  const afterVat = vatText(form.hasVat, form.hasVat ? form.vatMode : '')
  if (beforeVat !== afterVat) out.push({ label: 'VAT', before: beforeVat, after: afterVat })
  if ((original.taxId || '') !== (form.taxId || '')) out.push({ label: 'เลขผู้เสียภาษี', before: original.taxId || '—', after: form.taxId || '—' })
  if (form.kind === 'corporate' && (original.juristicName || '') !== (form.juristicName || '')) {
    out.push({ label: 'ชื่อนิติบุคคล', before: original.juristicName || '—', after: form.juristicName || '—' })
  }
  return out
})
const confirmOpen = ref(false)
const outstanding = ref<{ itemCount: number; totalAmount: number } | null>(null)
const money2 = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

function validateLocal(): boolean {
  const errs: Record<string, string> = {}
  if (!form.agentCode.trim()) errs.agentCode = 'กรุณากรอกรหัสตัวแทน'
  if (form.kind === 'corporate' && !form.juristicName.trim()) errs.juristicName = 'กรุณากรอกชื่อนิติบุคคล'
  if (taxIdRequired.value && !form.taxId.trim()) errs.taxId = 'ต้องกรอกเลขประจำตัวผู้เสียภาษีเมื่อเป็นนิติบุคคลหรือมี VAT'
  fieldErrors.value = errs
  return Object.keys(errs).length === 0
}

async function submit(): Promise<void> {
  error.value = null
  if (!validateLocal()) { error.value = Object.values(fieldErrors.value)[0]; return }
  if (isEdit.value && taxChanges.value.length > 0 && !confirmOpen.value) {
    outstanding.value = null
    confirmOpen.value = true
    fetchAgentOutstandingDetail(editId.value!).then((r) => { outstanding.value = r.totals }).catch(() => { outstanding.value = null })
    return
  }
  await save()
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    if (isEdit.value) {
      await updateAgentFull(editId.value!, payload.value)
      confirmOpen.value = false
      router.push({ name: 'agent-detail', params: { id: editId.value! } })
    } else {
      const res = await createAgentFull(payload.value)
      const id = (res.data as Record<string, unknown>).id
      router.push(id ? { name: 'agent-detail', params: { id: String(id) } } : { name: 'agents' })
    }
  } catch (e: unknown) {
    confirmOpen.value = false
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      fieldErrors.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]))
      error.value = Object.values(errs)[0]?.[0] ?? e.message
    } else {
      error.value = 'บันทึกไม่สำเร็จ'
    }
  } finally { saving.value = false }
}
function cancel(): void {
  if (isEdit.value) router.push({ name: 'agent-detail', params: { id: editId.value! } })
  else router.push({ name: 'agents' })
}

onMounted(load)
watch(() => route.params.id, () => { if (route.name === 'agent-edit') load() })
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-4xl px-4 py-5">
      <button type="button" class="mb-2 text-xs text-slate-500 hover:text-slate-700" @click="cancel">
        <i class="pi pi-arrow-left text-[10px]" /> {{ isEdit ? 'กลับไปหน้าข้อมูลตัวแทน' : 'กลับไปรายชื่อตัวแทน' }}
      </button>
      <div class="mb-4">
        <h1 class="text-lg font-semibold text-slate-800">
          {{ isEdit ? `แก้ไขข้อมูล — ${agentNameOrCode}` : 'เพิ่มตัวแทนใหม่' }}
        </h1>
        <p v-if="isEdit" class="text-xs text-slate-500">{{ form.agentCode }}</p>
      </div>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <div v-else class="space-y-4">
        <div v-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="agent-form-error">{{ error }}</div>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ข้อมูลพื้นฐาน</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="รหัสตัวแทน *"><input v-model.trim="form.agentCode" :disabled="isEdit" class="ipt" /></FormField>
            <FormField label="ประเภท">
              <select v-if="isEdit && originalType === 'AG'" v-model="form.agentType" class="ipt" data-testid="agent-type">
                <option value="AG">AG (ตัวแทน)</option><option value="IN">IN (นายหน้า)</option>
              </select>
              <div v-else class="ipt bg-slate-50 text-slate-600" data-testid="agent-type">IN (นายหน้า)</div>
              <p v-if="!isEdit" class="mt-1 text-[11px] text-slate-400">ตัวแทนใหม่ทุกคนเป็นประเภท IN</p>
            </FormField>
            <FormField label="ชนิด">
              <select v-model="form.kind" class="ipt" data-testid="agent-kind"><option value="individual">บุคคลธรรมดา</option><option value="corporate">นิติบุคคล</option></select>
            </FormField>
            <template v-if="form.kind === 'individual'">
              <FormField label="ชื่อ"><input v-model.trim="form.firstName" class="ipt" /></FormField>
              <FormField label="นามสกุล"><input v-model.trim="form.lastName" class="ipt" /></FormField>
              <FormField label="ชื่อเล่น"><input v-model.trim="form.nickname" class="ipt" /></FormField>
              <FormField label="ชื่อ (อังกฤษ)"><input v-model.trim="form.firstNameEn" class="ipt" /></FormField>
              <FormField label="นามสกุล (อังกฤษ)"><input v-model.trim="form.lastNameEn" class="ipt" /></FormField>
              <FormField label="เพศ">
                <select v-model="form.gender" class="ipt"><option value="">—</option><option value="male">ชาย</option><option value="female">หญิง</option><option value="other">อื่นๆ</option></select>
              </FormField>
              <FormField label="เลขบัตรประชาชน"><input v-model.trim="form.idCard" class="ipt" /></FormField>
              <FormField label="วันเกิด"><DateInput v-model="form.birthDate" /></FormField>
            </template>
            <template v-else>
              <FormField label="ชื่อนิติบุคคล *" class="col-span-2">
                <input v-model.trim="form.juristicName" :class="['ipt', fieldErrors.juristicName ? 'border-rose-400' : '']" data-testid="agent-juristic-name" />
                <p v-if="fieldErrors.juristicName" class="mt-1 text-[11px] text-rose-600">{{ fieldErrors.juristicName }}</p>
              </FormField>
            </template>
            <FormField :label="taxIdRequired ? 'เลขประจำตัวผู้เสียภาษี *' : 'เลขประจำตัวผู้เสียภาษี'">
              <input v-model.trim="form.taxId" maxlength="13" inputmode="numeric" :class="['ipt font-mono', fieldErrors.taxId ? 'border-rose-400' : '']" data-testid="agent-tax-id" />
              <p v-if="fieldErrors.taxId" class="mt-1 text-[11px] text-rose-600">{{ fieldErrors.taxId }}</p>
              <p v-else-if="taxIdRequired" class="mt-1 text-[11px] text-slate-400">13 หลัก · จำเป็นเมื่อเป็นนิติบุคคลหรือมี VAT</p>
            </FormField>
          </div>
          <div class="mt-3 border-t border-slate-100 pt-3">
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" v-model="form.hasVat" class="h-4 w-4" data-testid="agent-has-vat" /> ตัวแทนจดทะเบียน VAT
            </label>
            <div v-if="form.hasVat" class="mt-2">
              <label class="text-[11px] text-slate-500">จ่ายค่าคอมแบบ</label>
              <div class="ml-2 inline-flex overflow-hidden rounded-lg border border-slate-200">
                <button type="button" data-testid="vat-include" :class="['px-3 py-1 text-xs', form.vatMode === 'include' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']" @click="form.vatMode = 'include'">รวม VAT (Include)</button>
                <button type="button" data-testid="vat-exclude" :class="['px-3 py-1 text-xs', form.vatMode === 'exclude' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']" @click="form.vatMode = 'exclude'">ไม่รวม VAT (Exclude)</button>
              </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-slate-700">
              <label for="agent-wht-rate">อัตราหัก ณ ที่จ่าย</label>
              <input id="agent-wht-rate" v-model="form.whtRate" type="number" min="0" max="15" step="0.01" placeholder="3.00" data-testid="agent-wht-rate"
                class="w-24 rounded-lg border border-slate-200 px-2 py-1 text-right text-sm" />
              <span class="text-xs text-slate-500">% · เว้นว่าง = ค่าเริ่มต้นของหน่วยงาน ({{ form.kind === 'corporate' ? 'นิติบุคคล ยื่น ภ.ง.ด.53' : 'บุคคลธรรมดา ยื่น ภ.ง.ด.3' }})</span>
            </div>
            <p v-if="isEdit" class="mt-2 text-[11px] text-amber-700"><i class="pi pi-info-circle text-[10px]" /> เปลี่ยนชนิด/VAT/เลขผู้เสียภาษี/อัตราหัก ณ ที่จ่าย มีผลกับรอบจ่ายค่าคอมที่สร้างหลังบันทึกเท่านั้น และถูกบันทึกประวัติ</p>
          </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ข้อมูลติดต่อ</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="อีเมล"><input v-model.trim="form.email" type="email" class="ipt" /></FormField>
            <FormField label="โทรศัพท์"><input v-model.trim="form.phone" class="ipt" /></FormField>
            <FormField label="LINE ID"><input v-model.trim="form.lineId" class="ipt" /></FormField>
          </div>
          <div class="mt-3">
            <FormField label="ที่อยู่"><input v-model.trim="form.address" class="ipt" /></FormField>
            <div class="mt-2">
              <AddressPicker
                v-if="isEdit && editId"
                entity="agents" :id="editId"
                :fields="{ province: 'province', district: 'district', subDistrict: 'subDistrict', postcode: 'postcode' }"
                :province="form.province" :district="form.district" :sub-district="form.subDistrict" :postcode="form.postcode"
                @update="(f: string, v: string | null) => applyAddress(f, v)" />
              <div v-else class="grid grid-cols-2 gap-3">
                <FormField label="จังหวัด"><input v-model.trim="form.province" class="ipt" /></FormField>
                <FormField label="อำเภอ/เขต"><input v-model.trim="form.district" class="ipt" /></FormField>
                <FormField label="ตำบล/แขวง"><input v-model.trim="form.subDistrict" class="ipt" /></FormField>
                <FormField label="รหัสไปรษณีย์"><input v-model.trim="form.postcode" class="ipt" /></FormField>
              </div>
            </div>
          </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">บัญชีธนาคาร</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="ธนาคาร"><input v-model.trim="form.bankNameText" class="ipt" /></FormField>
            <FormField label="เลขบัญชี"><input v-model.trim="form.bankAccountNo" class="ipt" /></FormField>
            <FormField label="ชื่อบัญชี"><input v-model.trim="form.bankAccountName" class="ipt" /></FormField>
          </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ใบอนุญาต</h2>
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="rounded-lg border-2 border-sky-100 p-3" data-testid="form-license-life">
              <div class="mb-2 text-xs font-semibold text-sky-800"><i class="pi pi-heart text-[10px]" /> ประกันชีวิต</div>
              <div class="grid grid-cols-2 gap-2">
                <FormField label="เลขใบอนุญาต"><input v-model.trim="form.licenseLifeNo" class="ipt" /></FormField>
                <FormField label="วันหมดอายุ"><DateInput v-model="form.licenseLifeExpiry" /></FormField>
              </div>
            </div>
            <div class="rounded-lg border-2 border-amber-100 p-3" data-testid="form-license-non-life">
              <div class="mb-2 text-xs font-semibold text-amber-800"><i class="pi pi-car text-[10px]" /> ประกันวินาศภัย</div>
              <div class="grid grid-cols-2 gap-2">
                <FormField label="เลขใบอนุญาต"><input v-model.trim="form.licenseNonLifeNo" class="ipt" /></FormField>
                <FormField label="วันหมดอายุ"><DateInput v-model="form.licenseNonLifeExpiry" /></FormField>
              </div>
            </div>
          </div>
          <p class="mt-2 text-[11px] text-slate-400">อัปโหลดรูปใบอนุญาตได้ที่หน้าข้อมูลตัวแทน</p>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ต้นสาย &amp; ระดับ</h2>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <FormField label="ระดับ">
              <span :class="['inline-block rounded px-2 py-1 text-sm font-semibold', levelBadgeClass(isEdit ? currentLevel : 1)]" data-testid="agent-level">
                {{ isEdit ? (currentLevel ? `ระดับ ${currentLevel}` : '—') : 'ระดับ 1' }}
              </span>
              <p class="mt-1 text-[11px] text-slate-400">{{ isEdit ? 'ขอปรับระดับได้ที่หน้าข้อมูลตัวแทน' : 'ตัวแทนใหม่เริ่มที่ระดับ 1' }}</p>
            </FormField>
            <FormField label="วันที่เข้าร่วม"><DateInput v-model="form.joinedAt" /></FormField>
            <FormField label="ต้นสาย (Upline)" class="col-span-2 sm:col-span-3">
              <AgentPicker v-model="form.parentAgentId" placeholder="ค้นหาตัวแทนต้นสาย" />
            </FormField>
          </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <FormField label="หมายเหตุ"><textarea v-model="form.notes" rows="2" class="ipt"></textarea></FormField>
          <p v-if="isEdit" class="mt-2 text-[11px] text-slate-400">ปิด/เปิดใช้งานตัวแทนได้ที่หน้าข้อมูลตัวแทน</p>
        </section>

        <div class="flex justify-end gap-2">
          <button type="button" class="rounded border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50" @click="cancel">ยกเลิก</button>
          <button type="button" data-testid="agent-save" class="rounded bg-brand-600 px-4 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-50"
            :disabled="saving" @click="submit">
            <i :class="saving ? 'pi pi-spin pi-spinner' : 'pi pi-check'" class="mr-1 text-xs" />{{ isEdit ? 'บันทึก' : 'สร้างตัวแทน' }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="confirmOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" data-testid="tax-change-dialog" @click.self="confirmOpen = false">
      <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
        <h3 class="text-base font-semibold text-slate-800">ยืนยันเปลี่ยนข้อมูลภาษี/VAT ของ {{ agentNameOrCode }}</h3>
        <table class="mt-3 w-full text-sm">
          <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">รายการ</th><th class="py-1">เดิม</th><th class="py-1">ใหม่</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in taxChanges" :key="c.label">
              <td class="py-1.5 text-slate-500">{{ c.label }}</td>
              <td class="py-1.5 text-slate-500 line-through">{{ c.before }}</td>
              <td class="py-1.5 font-medium text-slate-800">{{ c.after }}</td>
            </tr>
          </tbody>
        </table>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">
          <li>มีผลกับ<b>รอบจ่ายค่าคอมที่สร้างหลังจากนี้</b> — รอบที่สร้างแล้วไม่เปลี่ยน</li>
          <li v-if="outstanding">ค่าคอมที่ยังไม่เข้ารอบจ่าย <b>{{ outstanding.itemCount }} รายการ ฿{{ money2(outstanding.totalAmount) }}</b> จะถูกจ่ายตามข้อมูลใหม่</li>
          <li>ระบบบันทึกค่าเดิม/ใหม่ และผู้แก้ไขไว้ในประวัติ</li>
        </ul>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50" @click="confirmOpen = false">กลับไปแก้ไข</button>
          <button type="button" data-testid="tax-change-confirm" class="rounded bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-50" :disabled="saving" @click="save">ยืนยันและบันทึก</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ipt { @apply w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400; }
</style>
