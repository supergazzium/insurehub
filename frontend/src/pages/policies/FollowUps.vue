<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchFollowUps, type FollowUpRow, type FollowUpCategory } from '../../api/followups'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()

const rows = ref<FollowUpRow[]>([])
const counts = ref<Record<string, number>>({})
const loading = ref(true)
const error = ref<string | null>(null)
const category = ref<FollowUpCategory>('approval')
const q = ref('')

interface CatDef {
  key: FollowUpCategory
  label: string
  hint: string
  icon: string
  color: string
}
// #5 (payment) is intentionally omitted — it has its own /collections page.
const CATEGORIES: CatDef[] = [
  { key: 'approval', label: 'สถานะรออนุมัติ', hint: 'กรมธรรม์ที่ส่งแล้วรอบริษัทอนุมัติ', icon: 'pi-hourglass', color: 'amber' },
  { key: 'no_policy_no', label: 'ยังไม่บันทึกเลขกรมธรรม์', hint: 'อนุมัติแล้วแต่ยังไม่มีเลขกรมธรรม์', icon: 'pi-id-card', color: 'sky' },
  { key: 'not_delivered', label: 'ยังไม่จัดส่ง', hint: 'ยังไม่ได้บันทึกวันจัดส่งกรมธรรม์', icon: 'pi-send', color: 'indigo' },
  { key: 'freelook', label: 'ติดตาม Freelook', hint: 'อยู่ในช่วง Free Look (ประกันชีวิต)', icon: 'pi-clock', color: 'violet' },
  { key: 'no_commission', label: 'ยังไม่บันทึกค่าคอม', hint: 'ยังไม่บันทึกค่าคอม บ.ประกัน→ฮับ หรือ ฮับ→ตัวแทน', icon: 'pi-wallet', color: 'rose' },
  { key: 'cancelled', label: 'ตรวจสอบรายการยกเลิก', hint: 'กรมธรรม์ที่ถูกยกเลิก', icon: 'pi-times-circle', color: 'slate' },
]

const chipCls = (c: CatDef, active: boolean): string => {
  const base = 'flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition'
  if (active) return `${base} border-sky-500 bg-sky-50 text-sky-800 ring-1 ring-sky-300`
  return `${base} border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50`
}
const countBadge = (c: CatDef, active: boolean): string => {
  const n = counts.value[c.key] ?? 0
  const on = active ? 'bg-sky-600 text-white' : n > 0 ? 'bg-slate-800 text-white' : 'bg-slate-200 text-slate-500'
  return `ml-auto inline-flex min-w-[1.5rem] justify-center rounded-full px-2 py-0.5 text-xs font-semibold ${on}`
}

const STATUS_LABEL: Record<string, string> = {
  submitted: 'รออนุมัติ', active: 'มีผลบังคับ', issued: 'ออกกรมธรรม์แล้ว',
  cancelled: 'ยกเลิก', draft: 'ร่าง', expired: 'หมดอายุ',
}

let debounce: number | undefined
async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchFollowUps({ category: category.value, q: q.value || undefined })
    rows.value = res.data
    counts.value = res.meta.counts
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
function pick(c: FollowUpCategory): void { category.value = c; load() }
function reload(): void { window.clearTimeout(debounce); debounce = window.setTimeout(load, 250) }

function openPolicy(r: FollowUpRow): void {
  // Open the full-page wizard/editor (same as the policy list). It hydrates
  // from GET /policies/{id} and holds every field a follow-up needs to fix.
  router.push({ name: 'policy-edit-draft', params: { id: r.policyId } })
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-slate-800">ติดตามงานค้าง</h1>
      <p class="mt-1 text-sm text-slate-500">รายการงานที่ต้องติดตามและดำเนินการต่อ แยกตามประเภท</p>
    </header>

    <!-- Category filter chips -->
    <div class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
      <button
        v-for="c in CATEGORIES"
        :key="c.key"
        type="button"
        :class="chipCls(c, category === c.key)"
        @click="pick(c.key)"
      >
        <i :class="['pi', c.icon, 'text-base']" />
        <span class="flex flex-col">
          <span class="font-medium">{{ c.label }}</span>
          <span class="text-xs text-slate-400">{{ c.hint }}</span>
        </span>
        <span :class="countBadge(c, category === c.key)">{{ counts[c.key] ?? 0 }}</span>
      </button>
    </div>

    <!-- Search -->
    <div class="mb-3 flex items-center gap-2">
      <div class="relative w-full max-w-sm">
        <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input
          v-model="q"
          type="text"
          placeholder="ค้นหา เลขกรมธรรม์ / ใบคำขอ / ลูกค้า / ตัวแทน"
          class="w-full rounded-lg border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-sky-400 focus:outline-none focus:ring-1 focus:ring-sky-300"
          @input="reload"
        >
      </div>
      <span class="text-sm text-slate-500">{{ rows.length }} รายการ</span>
    </div>

    <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <!-- Table -->
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">เลขกรมธรรม์ / ใบคำขอ</th>
            <th class="px-4 py-3">ลูกค้า</th>
            <th class="px-4 py-3">ตัวแทน</th>
            <th class="px-4 py-3">บริษัท / แบบประกัน</th>
            <th class="px-4 py-3">สถานะ</th>
            <th class="px-4 py-3">วันที่เกี่ยวข้อง</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="7" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td>
          </tr>
          <tr v-else-if="rows.length === 0">
            <td colspan="7" class="px-4 py-10 text-center text-slate-400">ไม่มีรายการค้างในหมวดนี้ 🎉</td>
          </tr>
          <tr
            v-for="r in rows"
            :key="r.policyId"
            class="cursor-pointer hover:bg-slate-50"
            @click="openPolicy(r)"
          >
            <td class="px-4 py-3">
              <div class="font-medium text-slate-800">{{ r.policyNo || '—' }}</div>
              <div class="text-xs text-slate-400">{{ r.applicationNo || '—' }}</div>
            </td>
            <td class="px-4 py-3">
              <div class="text-slate-700">{{ r.customerName || '—' }}</div>
              <div class="text-xs text-slate-400">{{ r.customerPhone || r.customerCode || '' }}</div>
            </td>
            <td class="px-4 py-3">
              <div class="text-slate-700">{{ r.agentName || '—' }}</div>
              <div class="text-xs text-slate-400">{{ r.agentCode || '' }}</div>
            </td>
            <td class="px-4 py-3">
              <div class="text-slate-700">{{ r.carrierName || '—' }}</div>
              <div class="text-xs text-slate-400">{{ r.productName || '' }}</div>
            </td>
            <td class="px-4 py-3">
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                {{ STATUS_LABEL[r.status] || r.status }}
              </span>
            </td>
            <td class="px-4 py-3 text-slate-600">
              <template v-if="category === 'freelook'">
                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">ยังไม่บันทึกวัน Free Look</span>
              </template>
              <template v-else-if="category === 'cancelled'">ยกเลิก {{ fmtDate(r.cancelDate) }}</template>
              <template v-else-if="category === 'not_delivered'">รับเมื่อ {{ fmtDate(r.receivedDate) || '—' }}</template>
              <template v-else>มีผล {{ fmtDate(r.effectiveDate) || '—' }}</template>
            </td>
            <td class="px-4 py-3 text-right">
              <i class="pi pi-chevron-right text-slate-300" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
