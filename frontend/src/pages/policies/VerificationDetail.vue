<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  approveVerification, cancelVerification, fetchVerification, rejectVerification, type VerifyDetail,
} from '../../api/verifications'
import { ApiError } from '../../api/client'
import { fmtDateTime } from '../../util/dateFormat'

const route = useRoute()
const router = useRouter()
const id = computed(() => String(route.params.id))

const item = ref<VerifyDetail | null>(null)
const canVerify = ref(false)
const isMaker = ref(false)
const loading = ref(true)
const busy = ref(false)
const error = ref<string | null>(null)
const done = ref<string | null>(null)
const rejecting = ref(false)
const reason = ref('')

const STATUS: Record<string, { label: string; cls: string }> = {
  pending: { label: 'รอตรวจสอบ', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  approved: { label: 'อนุมัติแล้ว', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  rejected: { label: 'ไม่อนุมัติ', cls: 'bg-rose-50 text-rose-700 ring-rose-200' },
  cancelled: { label: 'ถอนคำขอแล้ว', cls: 'bg-slate-100 text-slate-600 ring-slate-200' },
}
const ACTION_CLS: Record<string, string> = {
  create: 'bg-sky-50 text-sky-700', update: 'bg-indigo-50 text-indigo-700', delete: 'bg-rose-50 text-rose-700',
}

const pending = computed(() => item.value?.status === 'pending')
const canDecide = computed(() => pending.value && canVerify.value && !isMaker.value)
const anyStale = computed(() => (item.value?.diff ?? []).some((d) => d.currentChanged))

function errMsg(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const errs = (e.body?.errors ?? {}) as Record<string, string[]>
    return Object.values(errs)[0]?.[0] ?? e.message
  }
  return fallback
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchVerification(id.value)
    item.value = res.data
    canVerify.value = res.meta.canVerify
    isMaker.value = res.meta.isMaker
  } catch (e: unknown) {
    error.value = errMsg(e, 'โหลดคำขอไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function run(fn: () => Promise<{ data: VerifyDetail }>, msg: string): Promise<void> {
  busy.value = true
  error.value = null
  try {
    const res = await fn()
    item.value = { ...res.data, summary: item.value?.summary }
    done.value = msg
    rejecting.value = false
  } catch (e: unknown) {
    error.value = errMsg(e, 'ดำเนินการไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

const approve = () => run(() => approveVerification(id.value), 'อนุมัติแล้ว ระบบบันทึกข้อมูลลงกรมธรรม์เรียบร้อย')
const cancel = () => run(() => cancelVerification(id.value), 'ถอนคำขอแล้ว')
function reject(): void {
  if (!reason.value.trim()) {
    error.value = 'กรุณาระบุเหตุผลที่ไม่อนุมัติ'
    return
  }
  void run(() => rejectVerification(id.value, reason.value.trim()), 'ไม่อนุมัติแล้ว ผู้ส่งคำขอจะเห็นเหตุผลที่หน้ากรมธรรม์')
}

function openPolicy(): void {
  if (item.value) void router.push({ name: 'policy-edit-draft', params: { id: item.value.policyId } })
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-6" data-testid="verification-detail">
    <button type="button" class="mb-3 text-sm text-slate-500 hover:text-slate-700"
      @click="router.push({ name: 'policies-follow-ups', query: { cat: 'verify' } })">
      <i class="pi pi-arrow-left text-xs" /> กลับไปคิว Verify
    </button>

    <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
    <div v-else-if="!item" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error || 'ไม่พบคำขอ' }}</div>
    <template v-else>
      <header class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="flex items-center gap-2">
              <span :class="['rounded px-2 py-0.5 text-xs font-medium', ACTION_CLS[item.action]]">{{ item.actionLabel }}</span>
              <span :class="['rounded-full px-2 py-0.5 text-xs ring-1', STATUS[item.status]?.cls]" data-testid="verify-status">{{ STATUS[item.status]?.label ?? item.status }}</span>
            </div>
            <h1 class="mt-2 text-lg font-semibold text-slate-800">
              {{ item.policyNo || item.applicationNo || 'กรมธรรม์ (ร่าง)' }}
              <span class="text-sm font-normal text-slate-500">· {{ item.customerName || '—' }}</span>
            </h1>
            <p class="mt-1 text-xs text-slate-500">
              ส่งโดย <b class="text-slate-700">{{ item.makerName || '—' }}</b> · {{ fmtDateTime(item.createdAt) }}
              <template v-if="item.checkerName"> · ตรวจโดย <b class="text-slate-700">{{ item.checkerName }}</b> · {{ fmtDateTime(item.decidedAt) }}</template>
            </p>
          </div>
          <button type="button" class="rounded border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50" @click="openPolicy">
            <i class="pi pi-external-link text-xs" /> เปิดกรมธรรม์
          </button>
        </div>
        <p v-if="item.note" class="mt-3 rounded bg-slate-50 px-3 py-2 text-sm text-slate-700">
          <span class="text-xs text-slate-500">{{ item.action === 'delete' ? 'เหตุผลที่ขอลบ' : 'หมายเหตุผู้ส่ง' }}:</span> {{ item.note }}
        </p>
        <p v-if="item.status === 'rejected' && item.reason" class="mt-3 rounded bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="verify-reject-reason">
          <i class="pi pi-times-circle text-xs" /> เหตุผลที่ไม่อนุมัติ: {{ item.reason }}
        </p>
      </header>

      <div v-if="done" class="mb-3 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700" data-testid="verify-done">{{ done }}</div>
      <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700" data-testid="verify-error">{{ error }}</div>

      <section v-if="item.diff.length" class="mb-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-700">รายการที่เปลี่ยน ({{ item.diff.length }} ช่อง)</h2>
        <table class="min-w-full text-sm" data-testid="verify-diff">
          <thead class="bg-slate-50 text-left text-xs text-slate-500">
            <tr><th class="px-4 py-2 w-1/4">ช่อง</th><th class="px-4 py-2">ค่าเดิม</th><th class="px-4 py-2">ค่าใหม่</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="d in item.diff" :key="d.field">
              <td class="px-4 py-2 text-slate-600">
                {{ d.label }}
                <div v-if="d.currentChanged" class="text-[11px] text-amber-600"><i class="pi pi-exclamation-triangle text-[10px]" /> ค่าปัจจุบันถูกแก้หลังส่งคำขอ</div>
              </td>
              <td class="px-4 py-2"><span class="rounded bg-rose-50 px-1.5 py-0.5 text-rose-700 line-through decoration-rose-300">{{ d.before ?? '(ว่าง)' }}</span></td>
              <td class="px-4 py-2"><span class="rounded bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-700">{{ d.after ?? '(ว่าง)' }}</span></td>
            </tr>
          </tbody>
        </table>
      </section>

      <section v-if="item.action !== 'update' && item.summary?.length" class="mb-4 rounded-xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-700">
          {{ item.action === 'delete' ? 'ข้อมูลกรมธรรม์ที่จะถูกลบ' : 'ข้อมูลสำคัญของกรมธรรม์ที่ขอสร้าง' }}
        </h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-1.5 p-4 text-sm sm:grid-cols-2" data-testid="verify-summary">
          <div v-for="s in item.summary" :key="s.field" class="flex justify-between gap-3 border-b border-dashed border-slate-100 py-1">
            <dt class="text-slate-500">{{ s.label }}</dt><dd class="text-right font-medium text-slate-800">{{ s.value }}</dd>
          </div>
        </dl>
      </section>

      <div v-if="pending" class="rounded-xl border border-slate-200 bg-white p-4">
        <p v-if="anyStale" class="mb-3 text-xs text-amber-700">มีช่องที่ค่าปัจจุบันไม่ตรงกับตอนส่งคำขอ ตรวจให้แน่ใจก่อนอนุมัติ</p>
        <template v-if="canDecide">
          <div v-if="rejecting" class="mb-3">
            <label class="text-xs text-slate-600">เหตุผลที่ไม่อนุมัติ (ผู้ส่งจะเห็นข้อความนี้)
              <textarea v-model="reason" rows="2" data-testid="verify-reason" class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm" />
            </label>
          </div>
          <div class="flex flex-wrap justify-end gap-2">
            <template v-if="!rejecting">
              <button type="button" data-testid="verify-reject" class="rounded border border-rose-300 px-4 py-2 text-sm text-rose-700 hover:bg-rose-50" :disabled="busy" @click="rejecting = true">
                <i class="pi pi-times text-xs" /> ไม่อนุมัติ
              </button>
              <button type="button" data-testid="verify-approve" class="rounded bg-emerald-600 px-4 py-2 text-sm text-white hover:bg-emerald-700 disabled:opacity-50" :disabled="busy" @click="approve">
                <i class="pi pi-check text-xs" /> อนุมัติ (Verify)
              </button>
            </template>
            <template v-else>
              <button type="button" class="rounded border border-slate-300 px-4 py-2 text-sm text-slate-600" :disabled="busy" @click="rejecting = false; reason = ''">ยกเลิก</button>
              <button type="button" data-testid="verify-reject-confirm" class="rounded bg-rose-600 px-4 py-2 text-sm text-white hover:bg-rose-700 disabled:opacity-50" :disabled="busy" @click="reject">ยืนยันไม่อนุมัติ</button>
            </template>
          </div>
        </template>
        <div v-else-if="isMaker" class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-sm text-slate-600">คุณเป็นผู้ส่งคำขอนี้ ต้องให้ผู้ใช้อีกคนที่มีสิทธิ์ตรวจสอบเป็นผู้อนุมัติ</p>
          <button type="button" data-testid="verify-withdraw" class="rounded border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" :disabled="busy" @click="cancel">
            <i class="pi pi-undo text-xs" /> ถอนคำขอ
          </button>
        </div>
        <p v-else class="text-sm text-slate-500">คุณไม่มีสิทธิ์ตรวจสอบ (Verify) กรมธรรม์</p>
      </div>
    </template>
  </div>
</template>
