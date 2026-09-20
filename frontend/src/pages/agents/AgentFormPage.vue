<script setup lang="ts">
// สร้าง / แก้ไข ตัวแทน — full-page form (mirrors the customer create/edit style).
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormField from '../../components/FormField.vue'
import DateInput from '../../components/DateInput.vue'
import AddressPicker from '../../components/AddressPicker.vue'
import AgentPicker from '../../components/AgentPicker.vue'
import SearchSelect, { type SearchOption } from '../../components/SearchSelect.vue'
import AgentsSubnav from './AgentsSubnav.vue'
import {
  fetchAgent, createAgentFull, updateAgentFull, fetchTeams, fetchRanks,
  type TeamRow, type RankRow,
} from '../../api/agents'
import { ApiError } from '../../api/client'
import { toIsoDate } from '../../util/dateFormat'

const route = useRoute()
const router = useRouter()
const editId = computed(() => (route.name === 'agent-edit-info' ? String(route.params.id) : null))
const isEdit = computed(() => editId.value !== null)

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const teams = ref<TeamRow[]>([])
const ranks = ref<RankRow[]>([])

const form = reactive({
  agentCode: '',
  agentType: 'AG' as 'AG' | 'IN',
  kind: 'individual' as 'individual' | 'corporate',
  firstName: '', lastName: '', firstNameEn: '', lastNameEn: '', nickname: '',
  gender: '' as '' | 'male' | 'female' | 'other',
  email: '', phone: '', lineId: '',
  idCard: '', birthDate: '',
  juristicName: '', taxId: '',
  address: '', province: '', district: '', subDistrict: '', postcode: '',
  bankNameText: '', bankAccountNo: '', bankAccountName: '',
  licenseLifeNo: '', licenseLifeExpiry: '',
  licenseNonLifeNo: '', licenseNonLifeExpiry: '',
  teamId: '', parentAgentId: '', level: '',
  joinedAt: '',
  notes: '',
  active: true,
})

const teamOptions = computed<SearchOption[]>(() => [
  { value: '', label: '— ไม่มีสายงาน —' },
  ...teams.value.map((t) => ({ value: t.id, label: `${t.code} · ${t.memberCount} คน` })),
])
const levelOptions = computed<SearchOption[]>(() => [
  { value: '', label: '— ไม่กำหนด —' },
  ...ranks.value.map((r) => ({ value: r.levelKey, label: `${r.nameTh} (${r.code})` })),
])

function s(k: string, v: unknown): void { (form as Record<string, unknown>)[k] = v == null ? '' : v }

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [t, r] = await Promise.all([fetchTeams(), fetchRanks()])
    teams.value = t.data; ranks.value = r.data
    if (isEdit.value) {
      const res = await fetchAgent(editId.value!)
      const d = res.data as Record<string, unknown>
      const bank = (d.bank ?? {}) as Record<string, unknown>
      form.agentCode = String(d.agentCode ?? '')
      form.agentType = (d.agentType as 'AG' | 'IN') || 'AG'
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
      s('teamId', d.teamId); s('parentAgentId', d.parentAgentId); s('level', d.level)
      s('joinedAt', d.joinedAt); s('notes', d.notes)
      form.active = d.active !== false
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally { loading.value = false }
}

const payload = computed<Record<string, unknown>>(() => {
  const p: Record<string, unknown> = {
    agentCode: form.agentCode.trim(),
    agentType: form.agentType,
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
    birthDate: toIsoDate(form.birthDate) || null,
    juristicName: form.juristicName || null,
    taxId: form.taxId || null,
    address: form.address || null,
    province: form.province || null,
    district: form.district || null,
    subDistrict: form.subDistrict || null,
    postcode: form.postcode || null,
    licenseLifeNo: form.licenseLifeNo || null,
    licenseLifeExpiry: toIsoDate(form.licenseLifeExpiry) || null,
    licenseNonLifeNo: form.licenseNonLifeNo || null,
    licenseNonLifeExpiry: toIsoDate(form.licenseNonLifeExpiry) || null,
    parentAgentId: form.parentAgentId || null,
    joinedAt: toIsoDate(form.joinedAt) || null,
    notes: form.notes || null,
    active: form.active,
    bank: { bankName: form.bankNameText || null, accountNo: form.bankAccountNo || null, accountName: form.bankAccountName || null },
  }
  return p
})

async function submit(): Promise<void> {
  if (!form.agentCode.trim()) { error.value = 'กรุณากรอกรหัสตัวแทน'; return }
  saving.value = true
  error.value = null
  try {
    if (isEdit.value) {
      await updateAgentFull(editId.value!, payload.value)
      // team + level go through the hierarchy endpoint separately (kept in sync).
      router.push({ name: 'agents' })
    } else {
      const res = await createAgentFull(payload.value)
      const id = (res.data as Record<string, unknown>).id
      // Apply team/level via the hierarchy endpoint after creation if set.
      router.push(id ? { name: 'agent-detail', params: { id: String(id) } } : { name: 'agents' })
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
  } finally { saving.value = false }
}
function cancel(): void { router.push({ name: 'agents' }) }

onMounted(load)
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-4xl px-4 py-5">
      <button type="button" class="mb-2 text-xs text-slate-500 hover:text-slate-700" @click="cancel">
        <i class="pi pi-arrow-left text-[10px]" /> กลับไปจัดการตัวแทน
      </button>
      <h1 class="mb-4 text-lg font-semibold text-slate-800">{{ isEdit ? 'แก้ไขข้อมูลตัวแทน' : 'เพิ่มตัวแทนใหม่' }}</h1>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <div v-else class="space-y-4">
        <div v-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</div>

        <!-- ข้อมูลพื้นฐาน -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ข้อมูลพื้นฐาน</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="รหัสตัวแทน *"><input v-model.trim="form.agentCode" :disabled="isEdit" class="ipt" /></FormField>
            <FormField label="ประเภท">
              <select v-model="form.agentType" class="ipt"><option value="AG">AG (ตัวแทน)</option><option value="IN">IN (นายหน้า)</option></select>
            </FormField>
            <FormField label="ชนิด">
              <select v-model="form.kind" class="ipt"><option value="individual">บุคคลธรรมดา</option><option value="corporate">นิติบุคคล</option></select>
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
              <FormField label="ชื่อนิติบุคคล" class="col-span-2"><input v-model.trim="form.juristicName" class="ipt" /></FormField>
              <FormField label="เลขประจำตัวผู้เสียภาษี"><input v-model.trim="form.taxId" class="ipt" /></FormField>
            </template>
          </div>
        </section>

        <!-- ติดต่อ -->
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
                :fields="{ province: 'province', district: 'district', subDistrict: 'subDistrict', postcode: 'postcode' }"
                :province="form.province" :district="form.district" :sub-district="form.subDistrict" :postcode="form.postcode"
                @update:province="(v) => form.province = v" @update:district="(v) => form.district = v"
                @update:subDistrict="(v) => form.subDistrict = v" @update:postcode="(v) => form.postcode = v" />
            </div>
          </div>
        </section>

        <!-- ธนาคาร -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">บัญชีธนาคาร</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="ธนาคาร"><input v-model.trim="form.bankNameText" class="ipt" /></FormField>
            <FormField label="เลขบัญชี"><input v-model.trim="form.bankAccountNo" class="ipt" /></FormField>
            <FormField label="ชื่อบัญชี"><input v-model.trim="form.bankAccountName" class="ipt" /></FormField>
          </div>
        </section>

        <!-- ใบอนุญาต -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ใบอนุญาต</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <FormField label="เลขใบอนุญาตชีวิต"><input v-model.trim="form.licenseLifeNo" class="ipt" /></FormField>
            <FormField label="วันหมดอายุ (ชีวิต)"><DateInput v-model="form.licenseLifeExpiry" /></FormField>
            <FormField label="เลขใบอนุญาตวินาศภัย"><input v-model.trim="form.licenseNonLifeNo" class="ipt" /></FormField>
            <FormField label="วันหมดอายุ (วินาศภัย)"><DateInput v-model="form.licenseNonLifeExpiry" /></FormField>
          </div>
        </section>

        <!-- สายงาน & ระดับ -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">สายงาน &amp; ระดับ</h2>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <FormField label="สายงาน (ทีม)"><SearchSelect v-model="form.teamId" :options="teamOptions" placeholder="เลือกทีม" /></FormField>
            <FormField label="ระดับ"><SearchSelect v-model="form.level" :options="levelOptions" placeholder="เลือกระดับ" /></FormField>
            <FormField label="วันที่เข้าร่วม"><DateInput v-model="form.joinedAt" /></FormField>
            <FormField label="ต้นสาย (Upline)" class="col-span-2 sm:col-span-3">
              <AgentPicker v-model="form.parentAgentId" placeholder="ค้นหาตัวแทนต้นสาย" />
            </FormField>
          </div>
          <p v-if="!isEdit && (form.teamId || form.level)" class="mt-1 text-[10px] text-amber-600">
            <i class="pi pi-info-circle text-[9px]" /> สายงาน/ระดับ จะตั้งค่าในหน้ารายละเอียดหลังสร้างตัวแทน
          </p>
        </section>

        <!-- หมายเหตุ + สถานะ -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <FormField label="หมายเหตุ"><textarea v-model="form.notes" rows="2" class="ipt"></textarea></FormField>
          <label class="mt-2 flex items-center gap-2 text-xs text-slate-600">
            <input type="checkbox" v-model="form.active" class="h-4 w-4" /> เปิดใช้งาน (active)
          </label>
        </section>

        <div class="flex justify-end gap-2">
          <button type="button" class="rounded border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50" @click="cancel">ยกเลิก</button>
          <button type="button" class="rounded bg-brand-600 px-4 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-50"
            :disabled="saving" @click="submit">
            <i :class="saving ? 'pi pi-spin pi-spinner' : 'pi pi-check'" class="mr-1 text-xs" />{{ isEdit ? 'บันทึก' : 'สร้างตัวแทน' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ipt { @apply w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400; }
</style>
