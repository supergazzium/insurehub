<script setup lang="ts">
// #3 — ผู้บริหาร (executive) dashboard.
// Wired to the existing dashboard-kpis endpoint where data exists; widgets
// that need new backend aggregates are rendered as clearly-marked pending
// panels so the layout matches the spec and the gaps are explicit.
import { onMounted, computed } from 'vue'
import { useReportsStore } from '../../stores/reports'
import StatCard from './StatCard.vue'
import PanelCard from './PanelCard.vue'
import { formatBaht, formatInt } from './widgets'

const reports = useReportsStore()
onMounted(() => { void reports.loadKpis() })
const k = computed(() => reports.kpis)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">แดชบอร์ดผู้บริหาร</h1>
      <p class="text-slate-500 mt-1">ภาพรวมยอดขาย กรมธรรม์ การเงิน และผลการดำเนินงาน</p>
    </header>

    <!-- KPI row — real data from dashboard-kpis -->
    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <StatCard label="เบี้ยที่ยังมีผล (in-force)" :value="formatBaht(k?.inForcePremium)"
        icon="pi pi-chart-line" tone="violet" :busy="reports.loading" />
      <StatCard label="กรมธรรม์ที่ใช้งาน" :value="formatInt(k?.activePolicies)"
        :hint="`จาก ${formatInt(k?.totalPolicies)} ทั้งหมด`" icon="pi pi-file" tone="sky" :busy="reports.loading" />
      <StatCard label="ใกล้ครบกำหนด (60 วัน)" :value="formatInt(k?.expiring60d)"
        icon="pi pi-calendar-clock" tone="amber" :busy="reports.loading" />
      <StatCard label="กรมธรรม์ยกเลิก" :value="formatInt(k?.cancelledPolicies)"
        icon="pi pi-times-circle" tone="rose" :busy="reports.loading" />
    </section>

    <!-- Sales vs target — needs a targets endpoint -->
    <PanelCard title="ยอดขายและเบี้ยประกัน เทียบเป้าหมาย"
      subtitle="เดือนนี้ · เทียบเป้า / เดือนก่อน / ช่วงเดียวกันปีก่อน" pending />

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <PanelCard title="Pipeline การขาย"
        subtitle="มูลค่าใบเสนอราคาที่รอผล แยก Corporate / Agent" pending />
      <PanelCard title="งานต่ออายุล่วงหน้า"
        subtitle="30 / 60 / 90 วัน + ยอดที่เสี่ยงหลุด" to="/policies/expiring" toLabel="เปิดคิวต่ออายุ">
        <div class="grid grid-cols-3 gap-3 text-center">
          <div class="rounded-lg border border-slate-100 p-3">
            <div class="text-xs text-slate-400">ใกล้ครบ 60 วัน</div>
            <div class="mt-1 text-lg font-semibold text-amber-700">{{ formatInt(k?.expiring60d) }}</div>
          </div>
          <div class="rounded-lg border border-dashed border-slate-200 p-3">
            <div class="text-xs text-slate-400">30 วัน</div>
            <div class="mt-1 text-lg font-semibold text-slate-300">—</div>
          </div>
          <div class="rounded-lg border border-dashed border-slate-200 p-3">
            <div class="text-xs text-slate-400">90 วัน</div>
            <div class="mt-1 text-lg font-semibold text-slate-300">—</div>
          </div>
        </div>
      </PanelCard>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <PanelCard title="สถานะการเงิน"
        subtitle="เบี้ยค้างรับ · ค่าคอมค้างรับ · ค่าคอมรอจ่าย · ยอดไม่ตรง"
        to="/policies/collections" toLabel="ไปติดตามเงิน" pending />
      <PanelCard title="ผลการดำเนินงานรายทีม"
        subtitle="ยอดขาย · อัตราปิดการขาย · งานค้างเกินกำหนด" pending />
    </div>

    <PanelCard title="เรื่องที่ต้องตัดสินใจ"
      subtitle="อนุมัติส่วนลด · ข้อยกเว้น · เคสที่ติดปัญหา" pending />
  </div>
</template>
