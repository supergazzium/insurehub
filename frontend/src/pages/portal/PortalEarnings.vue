<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchEarnings, myCertificatePath, myStatementPath, type EarningsResponse } from '../../api/portal'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'
import { openPdf } from '../../util/fileDownload'

const { t } = useI18n()
const data = ref<EarningsResponse | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const busy = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    data.value = await fetchEarnings()
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลรายได้ไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const TYPE_CLS: Record<string, string> = {
  base: 'bg-sky-50 text-sky-700', self_mgmt: 'bg-violet-50 text-violet-700',
  referral: 'bg-amber-50 text-amber-700', override: 'bg-emerald-50 text-emerald-700',
}
const byType = computed(() => data.value?.totals.byType ?? {})

async function doc(kind: 'direct' | 'referral' | 'certificate', batchId: string): Promise<void> {
  busy.value = `${kind}-${batchId}`
  error.value = null
  try {
    const path = kind === 'certificate'
      ? myCertificatePath(batchId)
      : myStatementPath(batchId, kind) // 'direct' | 'referral'
    await openPdf(path)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'เปิดเอกสารไม่สำเร็จ'
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('portal.earnings.title') }}</h1>
        <p class="text-sm text-slate-500 mt-1">ค่าคอมพื้นฐาน ค่าแนะนำ และค่าบริหารสายงาน · หักภาษี ณ ที่จ่าย {{ data?.agent?.whtRate ?? 3 }}% ({{ data?.agent?.whtFormLabel ?? 'ภ.ง.ด.3' }})</p>
      </div>
      <button type="button" class="text-sm text-slate-500 hover:text-brand-600 flex items-center gap-1" @click="load">
        <i :class="loading ? 'pi pi-spin pi-spinner' : 'pi pi-refresh'" class="text-xs" />
        {{ t('common.refresh') }}
      </button>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">{{ error }}</div>

    <template v-if="data">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3" data-testid="earnings-summary">
        <div class="card p-4">
          <div class="text-xs text-slate-500">รอรอบจ่ายถัดไป (สุทธิ)</div>
          <div class="text-2xl font-semibold text-emerald-700 mt-1">฿{{ fmt(data.summary.readyNet) }}</div>
          <div class="text-[11px] text-slate-400">ก่อนภาษี ฿{{ fmt(data.summary.readyAmount) }}</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-slate-500">รอเงื่อนไข</div>
          <div class="text-2xl font-semibold text-amber-700 mt-1">฿{{ fmt(data.summary.waitingAmount) }}</div>
          <div class="text-[11px] text-slate-400">รอพ้น Freelook / บริษัทจ่ายค่าคอม / ลูกค้าชำระ</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-slate-500">ได้รับแล้วปีนี้ (สุทธิ)</div>
          <div class="text-2xl font-semibold text-slate-900 mt-1">฿{{ fmt(data.summary.paidThisYear) }}</div>
          <div class="text-[11px] text-slate-400">ภาษีที่ถูกหักปีนี้ ฿{{ fmt(data.summary.whtThisYear) }}</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-slate-500">ได้รับแล้วทั้งหมด</div>
          <div class="text-2xl font-semibold text-slate-900 mt-1">฿{{ fmt(data.summary.paidTotal) }}</div>
        </div>
      </div>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-1">รอรอบจ่ายถัดไป</h2>
        <p class="text-xs text-slate-500 mb-3">
          ค่าคอมพื้นฐาน ฿{{ fmt(byType.base) }} · ค่าแนะนำ ฿{{ fmt(byType.referral) }} · ค่าบริหารสายงาน ฿{{ fmt((byType.override ?? 0) + (byType.self_mgmt ?? 0)) }}
          <template v-if="data.totals.appliedDeduction > 0"> · หักคืน ฿{{ fmt(data.totals.appliedDeduction) }}</template>
          · หัก ณ ที่จ่าย ฿{{ fmt(data.totals.whtAmount) }}
        </p>
        <table class="w-full text-sm">
          <thead class="text-xs text-slate-500 text-left">
            <tr><th class="py-1.5">ประเภท</th><th class="py-1.5">กรมธรรม์</th><th class="py-1.5">รายละเอียด</th><th class="py-1.5 text-right">ยอด</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="!data.ready.length"><td colspan="4" class="py-6 text-center text-slate-400">ยังไม่มีรายการรอจ่าย</td></tr>
            <tr v-for="l in data.ready" :key="l.key">
              <td class="py-1.5"><span :class="['rounded px-1.5 py-0.5 text-xs', TYPE_CLS[l.lineType]]">{{ l.lineLabel }}</span></td>
              <td class="py-1.5 text-slate-700">{{ l.policyNo || l.applicationNo }}<div class="text-[11px] text-slate-400">{{ l.customerName }}</div></td>
              <td class="py-1.5 text-xs text-slate-500">{{ l.sourceAgent ? `จากยอดขายของ ${l.sourceAgent}` : (l.ratioLabel || '') }}</td>
              <td class="py-1.5 text-right font-medium">฿{{ fmt(l.amount) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="data.waiting.length" class="mt-4">
          <div class="text-xs font-semibold text-amber-700 mb-1">รอเงื่อนไข ({{ data.waiting.length }})</div>
          <ul class="space-y-1 text-xs">
            <li v-for="l in data.waiting" :key="l.key" class="flex flex-wrap items-center justify-between gap-2 rounded bg-amber-50 px-2 py-1">
              <span>{{ l.lineLabel }} · {{ l.policyNo || l.applicationNo }} — <span class="text-amber-700">{{ l.reasons.map((r) => r.label).join(' · ') }}</span></span>
              <span class="font-medium text-slate-700">฿{{ fmt(l.amount) }}</span>
            </li>
          </ul>
        </div>
      </section>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ประวัติการรับเงิน</h2>
        <table class="w-full text-sm" data-testid="earnings-history">
          <thead class="text-xs text-slate-500 text-left">
            <tr>
              <th class="py-1.5">วันที่จ่าย</th>
              <th class="py-1.5 text-right">ค่าคอมพื้นฐาน</th>
              <th class="py-1.5 text-right">ค่าแนะนำ / สายงาน</th>
              <th class="py-1.5 text-right">หัก</th>
              <th class="py-1.5 text-right">หัก ณ ที่จ่าย</th>
              <th class="py-1.5 text-right">รับสุทธิ</th>
              <th class="py-1.5"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="!data.history.length"><td colspan="7" class="py-6 text-center text-slate-400">ยังไม่มีประวัติการรับเงิน</td></tr>
            <tr v-for="h in data.history" :key="h.batchId">
              <td class="py-1.5 text-slate-700">{{ fmtDate(h.paymentDate) }}</td>
              <td class="py-1.5 text-right">฿{{ fmt(h.base + h.selfMgmt) }}</td>
              <td class="py-1.5 text-right">฿{{ fmt(h.referral + h.override) }}</td>
              <td class="py-1.5 text-right text-rose-600">{{ h.deduction > 0 ? `−฿${fmt(h.deduction)}` : '—' }}</td>
              <td class="py-1.5 text-right text-rose-600">{{ h.wht > 0 ? `−฿${fmt(h.wht)}` : '—' }}</td>
              <td class="py-1.5 text-right font-semibold text-emerald-700">฿{{ fmt(h.net) }}</td>
              <td class="py-1.5 text-right whitespace-nowrap">
                <button type="button" class="text-xs text-brand-600 hover:underline mr-2" @click="doc('direct', h.batchId)">
                  <i :class="['pi text-[10px]', busy === `direct-${h.batchId}` ? 'pi-spin pi-spinner' : 'pi-file-pdf']" /> ใบแจ้งจ่าย (ขายเอง)
                </button>
                <button type="button" class="text-xs text-brand-600 hover:underline mr-2" @click="doc('referral', h.batchId)">
                  <i :class="['pi text-[10px]', busy === `referral-${h.batchId}` ? 'pi-spin pi-spinner' : 'pi-file-pdf']" /> ใบแจ้งจ่าย (ค่าแนะนำ)
                </button>
                <button v-if="h.certificate" type="button" class="text-xs text-brand-600 hover:underline" @click="doc('certificate', h.batchId)">
                  <i :class="['pi text-[10px]', busy === `certificate-${h.batchId}` ? 'pi-spin pi-spinner' : 'pi-verified']" /> 50 ทวิ
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
