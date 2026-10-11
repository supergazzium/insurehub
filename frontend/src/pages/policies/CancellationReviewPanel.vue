<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { fetchCancellationReview, saveCancellationReview, type CancelCheck, type CancellationReview } from '../../api/followups'
import { ApiError } from '../../api/client'
import DateInput from '../../components/DateInput.vue'
import { fmtDateTime } from '../../util/dateFormat'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const canCancel = computed(() => auth.hasPermission('policies.cancel'))

const props = defineProps<{ policyId: string | null; title: string }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const review = ref<CancellationReview | null>(null)
const form = reactive({
  refundAmount: null as number | null,
  clawbackAmount: null as number | null,
  checks: { refund: 'pending' as CancelCheck, clawback: 'pending' as CancelCheck, carrierDeduct: 'pending' as CancelCheck },
  note: '',
  cancelDate: '',
})

const CHECKS: Array<{ key: 'refund' | 'clawback' | 'carrierDeduct'; label: string; hint: string }> = [
  { key: 'refund', label: 'คืนเบี้ยให้ลูกค้าแล้ว', hint: 'ยอดคืนเบี้ยถึงลูกค้า (ตรงหรือผ่านบริษัทประกัน)' },
  { key: 'clawback', label: 'เรียกค่าคอมคืนจากตัวแทนแล้ว', hint: 'ระบบจะหักยอดเรียกคืนจากรอบจ่ายค่าคอมถัดไปของตัวแทนอัตโนมัติ' },
  { key: 'carrierDeduct', label: 'บริษัทประกันหักค่าคอมของเราแล้ว', hint: 'บริษัทประกันเรียกค่าคอม/OV ที่จ่ายให้ InsureHub คืนแล้ว' },
]
const OPTIONS: Array<{ value: CancelCheck; label: string; cls: string }> = [
  { value: 'done', label: 'เสร็จแล้ว', cls: 'bg-emerald-600 text-white' },
  { value: 'na', label: 'ไม่เกี่ยวข้อง', cls: 'bg-slate-600 text-white' },
  { value: 'pending', label: 'ยังไม่', cls: 'bg-amber-500 text-white' },
]

const canMarkReviewed = computed(() => !Object.values(form.checks).includes('pending'))
const money = (n: number | null | undefined) => (n ?? 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

async function load(): Promise<void> {
  if (!props.policyId) return
  loading.value = true
  error.value = null
  try {
    const r = (await fetchCancellationReview(props.policyId)).data
    review.value = r
    form.refundAmount = r.refundAmount ?? r.suggestion.netRefundAmount
    form.clawbackAmount = r.clawbackAmount ?? (r.suggestion.paidCommission > 0 ? r.suggestion.paidCommission : null)
    form.checks = { ...r.checks }
    form.note = r.note ?? ''
    form.cancelDate = r.cancelDate ?? ''
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally { loading.value = false }
}

async function save(markReviewed: boolean): Promise<void> {
  if (!props.policyId) return
  saving.value = true
  error.value = null
  try {
    review.value = (await saveCancellationReview(props.policyId, {
      refundAmount: form.refundAmount,
      clawbackAmount: form.clawbackAmount,
      checks: form.checks,
      note: form.note || null,
      cancelDate: form.cancelDate || null,
      markReviewed,
    })).data
    emit('saved')
    if (markReviewed) emit('close')
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      error.value = Object.values(errs)[0]?.[0] ?? e.message
    } else {
      error.value = 'บันทึกไม่สำเร็จ'
    }
  } finally { saving.value = false }
}

watch(() => props.policyId, () => { void load() }, { immediate: true })
</script>

<template>
  <div v-if="policyId" class="fixed inset-0 z-50 flex justify-end bg-slate-900/40" data-testid="cancel-review-panel" @click.self="emit('close')">
    <div class="h-full w-full max-w-lg overflow-y-auto bg-white p-5 shadow-xl">
      <div class="mb-4 flex items-start justify-between gap-3">
        <div>
          <h3 class="text-base font-semibold text-slate-800">ตรวจสอบรายการยกเลิก</h3>
          <p class="text-xs text-slate-500">{{ title }}</p>
        </div>
        <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('close')"><i class="pi pi-times" /></button>
      </div>

      <div v-if="loading" class="py-10 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <template v-else-if="review">
        <div v-if="review.reviewedAt" class="mb-3 rounded bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
          <i class="pi pi-check-circle text-[11px]" /> ตรวจแล้วโดย {{ review.reviewedByName }} · {{ fmtDateTime(review.reviewedAt) }}
        </div>
        <div class="grid grid-cols-2 gap-3">
          <label class="text-xs text-slate-600">วันที่ยกเลิก
            <DateInput v-model="form.cancelDate" class="mt-0.5" />
          </label>
          <div class="text-xs text-slate-600">เหตุผลยกเลิก
            <div class="mt-1.5 text-sm text-slate-800">{{ review.cancelReason || '—' }}</div>
          </div>
          <label class="text-xs text-slate-600">ยอดคืนเบี้ยลูกค้า (บาท)
            <input v-model.number="form.refundAmount" type="number" min="0" step="0.01" data-testid="cancel-refund"
              class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1.5 text-sm" />
          </label>
          <label class="text-xs text-slate-600">เรียกคืนค่าคอมตัวแทน (บาท)
            <input v-model.number="form.clawbackAmount" type="number" min="0" step="0.01" data-testid="cancel-clawback"
              class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1.5 text-sm" />
            <span class="mt-0.5 block text-[11px] text-slate-400">จ่ายค่าคอมกรมธรรม์นี้ให้ตัวแทนไปแล้ว ฿{{ money(review.suggestion.paidCommission) }}</span>
          </label>
        </div>

        <div class="mt-4 space-y-2">
          <div v-for="c in CHECKS" :key="c.key" class="rounded-lg border border-slate-200 p-2.5" :data-testid="`cancel-check-${c.key}`">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <div class="text-sm text-slate-800">{{ c.label }}</div>
                <div class="text-[11px] text-slate-400">{{ c.hint }}</div>
              </div>
              <div class="inline-flex overflow-hidden rounded border border-slate-200">
                <button v-for="o in OPTIONS" :key="o.value" type="button"
                  :class="['px-2 py-1 text-[11px]', form.checks[c.key] === o.value ? o.cls : 'bg-white text-slate-600 hover:bg-slate-50']"
                  @click="form.checks[c.key] = o.value">{{ o.label }}</button>
              </div>
            </div>
          </div>
        </div>

        <label class="mt-3 block text-xs text-slate-600">หมายเหตุ
          <textarea v-model="form.note" rows="2" class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1.5 text-sm" />
        </label>

        <div v-if="review.uplines?.length" class="mt-3 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900" data-testid="cancel-uplines">
          <div class="mb-1 font-medium">ค่าแนะนำ/ค่าบริหารสายงานที่จ่ายให้ upline แล้ว — เรียกคืนอัตโนมัติ {{ Math.round((review.uplineRatio ?? 0) * 10000) / 100 }}% ตามสัดส่วนของผู้ขาย</div>
          <div v-for="u in review.uplines" :key="u.agentId" class="flex justify-between">
            <span>{{ u.agentCode }} {{ u.agentName }} · ได้รับ ฿{{ money(u.paid) }}</span>
            <span>เรียกคืน ฿{{ money(u.clawback) }}</span>
          </div>
          <div class="mt-1 text-[11px] text-amber-700">ยอดจะถูกหักจากรอบจ่ายถัดไปของแต่ละคนเมื่อกด "ตรวจแล้ว" · ข้อ "เรียกค่าคอมคืน" = ไม่เกี่ยวข้อง จะไม่เรียกคืนจาก upline</div>
        </div>

        <div v-if="review.clawbacks.length" class="mt-3 rounded bg-slate-50 p-2 text-xs text-slate-600">
          <div class="mb-1 font-medium">รายการหักค่าคอมคืน</div>
          <div v-for="c in review.clawbacks" :key="c.id" class="flex justify-between">
            <span>{{ c.agentCode ? `${c.agentCode} · ` : '' }}{{ c.status === 'pending' ? 'รอหักรอบจ่ายถัดไป' : `หักแล้ว (รอบ #${c.batchId})` }}</span>
            <span>฿{{ money(c.amount) }}</span>
          </div>
        </div>

        <p v-if="error" class="mt-3 text-sm text-rose-600" data-testid="cancel-review-error">{{ error }}</p>
        <div v-if="canCancel" class="mt-4 flex justify-end gap-2">
          <button type="button" class="rounded border border-slate-300 px-3 py-1.5 text-sm text-slate-600" :disabled="saving" @click="save(false)">บันทึกความคืบหน้า</button>
          <button type="button" data-testid="cancel-mark-reviewed" class="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white disabled:opacity-50"
            :disabled="saving || !canMarkReviewed" :title="canMarkReviewed ? '' : 'ทุกข้อต้องเป็น เสร็จแล้ว หรือ ไม่เกี่ยวข้อง'" @click="save(true)">
            <i class="pi pi-check text-xs" /> ตรวจแล้ว
          </button>
        </div>
        <p v-else class="mt-4 text-right text-[11px] text-amber-600"><i class="pi pi-eye text-[10px]" /> ดูข้อมูลเท่านั้น</p>
        <p v-if="canCancel && !canMarkReviewed" class="mt-1 text-right text-[11px] text-amber-600">ทุกข้อต้องเป็น "เสร็จแล้ว" หรือ "ไม่เกี่ยวข้อง" จึงกดตรวจแล้วได้</p>
      </template>
    </div>
  </div>
</template>
