<script setup lang="ts">
import { ref, computed } from 'vue'

// ─────────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────────

interface PendingAgent {
  id: string
  agentCode: string
  firstName: string
  lastName: string
  nickname: string
  email: string
  phone: string
  uplineName: string
  submittedAt: string // 25xx-mm-dd
  docsComplete: boolean
  hasLicense: boolean
  hasIdCard: boolean
  hasContract: boolean
  hasBgCheck: boolean
  region: string
}

interface ExpiringLicense {
  agentName: string
  agentCode: string
  licenseNo: string
  expiresOn: string
  daysLeft: number
}

interface CarrierUpdate {
  carrierCode: string
  carrierName: string
  type: 'rate' | 'product' | 'contract'
  effectiveOn: string
  description: string
}

interface SystemAlert {
  id: string
  severity: 'error' | 'warning' | 'info'
  source: string
  message: string
  occurredAt: string
}

interface MasterDataLink {
  key: string
  title: string
  desc: string
  icon: string
  iconBg: string
  iconColor: string
  metricLabel: string
  metricValue: string
  to: string
}

// ─────────────────────────────────────────────────────────────────────────────
// Reactive state
// ─────────────────────────────────────────────────────────────────────────────

const pendingAgents = ref<PendingAgent[]>([
  {
    id: 'pa1', agentCode: 'AG-00016',
    firstName: 'พิมพ์ลภัส', lastName: 'อินทรประสิทธิ์', nickname: 'พิม',
    email: 'pimlapas.i@gmail.com', phone: '081-444-7788',
    uplineName: 'สมชาย แก้วประเสริฐ',
    submittedAt: '2569-06-05',
    docsComplete: true, hasLicense: true, hasIdCard: true, hasContract: true, hasBgCheck: true,
    region: 'กรุงเทพมหานคร',
  },
  {
    id: 'pa2', agentCode: 'AG-00017',
    firstName: 'ภาคิน', lastName: 'ตั้งสกุล', nickname: 'ภา',
    email: 'pakin.t@hotmail.com', phone: '089-222-1144',
    uplineName: 'จิราภรณ์ พงษ์ศิริ',
    submittedAt: '2569-06-04',
    docsComplete: false, hasLicense: true, hasIdCard: true, hasContract: false, hasBgCheck: true,
    region: 'นนทบุรี',
  },
  {
    id: 'pa3', agentCode: 'AG-00018',
    firstName: 'นันท์นภัส', lastName: 'วรินทร์', nickname: 'นันท์',
    email: 'nannapas.w@gmail.com', phone: '081-555-0099',
    uplineName: 'พรทิพย์ มั่นคง',
    submittedAt: '2569-06-04',
    docsComplete: true, hasLicense: true, hasIdCard: true, hasContract: true, hasBgCheck: true,
    region: 'กรุงเทพมหานคร',
  },
  {
    id: 'pa4', agentCode: 'AG-00019',
    firstName: 'ศิวกร', lastName: 'มงคลธนภัทร', nickname: 'ศิว',
    email: 'siwakorn.m@gmail.com', phone: '081-666-3322',
    uplineName: 'อนุชา ใจดี',
    submittedAt: '2569-06-03',
    docsComplete: false, hasLicense: false, hasIdCard: true, hasContract: true, hasBgCheck: true,
    region: 'เชียงใหม่',
  },
  {
    id: 'pa5', agentCode: 'AG-00020',
    firstName: 'อิงครัต', lastName: 'พงศ์ภัค', nickname: 'อิ๊ง',
    email: 'ingkarat.p@gmail.com', phone: '081-777-4455',
    uplineName: 'ณัฐวุฒิ รัตนา',
    submittedAt: '2569-06-03',
    docsComplete: true, hasLicense: true, hasIdCard: true, hasContract: true, hasBgCheck: true,
    region: 'กรุงเทพมหานคร',
  },
  {
    id: 'pa6', agentCode: 'AG-00021',
    firstName: 'ปวีณ์ธิดา', lastName: 'รุ่งโรจน์', nickname: 'ปวีณ์',
    email: 'paweethida.r@gmail.com', phone: '081-888-7711',
    uplineName: 'ปิยะ ทองคำ',
    submittedAt: '2569-06-02',
    docsComplete: true, hasLicense: true, hasIdCard: true, hasContract: true, hasBgCheck: false,
    region: 'ภูเก็ต',
  },
  {
    id: 'pa7', agentCode: 'AG-00022',
    firstName: 'ธวัชชัย', lastName: 'สุทธิเลิศ', nickname: 'ชัย',
    email: 'thawatchai.s@gmail.com', phone: '081-999-2266',
    uplineName: 'อรอุมา สุขศรี',
    submittedAt: '2569-06-02',
    docsComplete: true, hasLicense: true, hasIdCard: true, hasContract: true, hasBgCheck: true,
    region: 'ชลบุรี',
  },
])

const expiringLicenses = ref<ExpiringLicense[]>([
  { agentName: 'วรรณา สุขใจ', agentCode: 'AG-00006', licenseNo: 'IC-630022', expiresOn: '2569-06-28', daysLeft: 21 },
  { agentName: 'อนุชา ใจดี', agentCode: 'AG-00003', licenseNo: 'IC-610055', expiresOn: '2569-06-30', daysLeft: 23 },
  { agentName: 'ชนาภา รัตนพันธ์', agentCode: 'AG-00015', licenseNo: 'IC-660188', expiresOn: '2569-07-04', daysLeft: 27 },
])

const carrierUpdates = ref<CarrierUpdate[]>([
  { carrierCode: 'AIA', carrierName: 'บริษัท เอไอเอ จำกัด', type: 'rate', effectiveOn: '2569-07-01', description: 'ปรับอัตราค่าคอมฯ ปีแรก ผลิตภัณฑ์สุขภาพ +2.5%' },
  { carrierCode: 'TLI', carrierName: 'บริษัท ไทยประกันชีวิต จำกัด (มหาชน)', type: 'product', effectiveOn: '2569-06-15', description: 'เพิ่มผลิตภัณฑ์โบนัสบำนาญพิเศษ' },
  { carrierCode: 'VIB', carrierName: 'บริษัท วิริยะประกันภัย จำกัด (มหาชน)', type: 'contract', effectiveOn: '2569-06-20', description: 'ปรับเงื่อนไขสัญญารถยนต์ชั้น 1 อายุรถไม่เกิน 8 ปี' },
  { carrierCode: 'BLA', carrierName: 'บริษัท กรุงเทพประกันชีวิต จำกัด (มหาชน)', type: 'rate', effectiveOn: '2569-07-15', description: 'ปรับเบี้ยพื้นฐาน PA สบายใจ −5%' },
])

const systemAlerts = ref<SystemAlert[]>([
  { id: 'al1', severity: 'error', source: 'AIA Sync API', message: 'การ sync ค่าคอมฯ ล้มเหลว (timeout 30s)', occurredAt: '2569-06-07 08:42' },
  { id: 'al2', severity: 'warning', source: 'Email Service', message: '12 อีเมลแจ้งเตือนยังไม่ถูกส่ง (queue stuck)', occurredAt: '2569-06-07 06:15' },
  { id: 'al3', severity: 'warning', source: 'Payment Gateway', message: 'การเชื่อมต่อ KBank API ตอบช้า (>5s)', occurredAt: '2569-06-06 22:30' },
])

// ─────────────────────────────────────────────────────────────────────────────
// Stats (computed live from reactive arrays)
// ─────────────────────────────────────────────────────────────────────────────

const stats = computed(() => ({
  pendingRegistrations: pendingAgents.value.length,
  expiringLicenses: expiringLicenses.value.length,
  carrierUpdates: carrierUpdates.value.length,
  systemAlerts: systemAlerts.value.length,
}))

// ─────────────────────────────────────────────────────────────────────────────
// Master data management links
// ─────────────────────────────────────────────────────────────────────────────

const masterDataLinks: MasterDataLink[] = [
  {
    key: 'carriers',
    title: 'Configure Carrier Profiles',
    desc: 'จัดการบริษัทประกันภัยทั้งหมดในแค็ตตาล็อกกลาง',
    icon: 'pi pi-building',
    iconBg: 'bg-sky-50', iconColor: 'text-sky-600',
    metricLabel: 'บริษัทประกัน',
    metricValue: '8',
    to: '/carriers',
  },
  {
    key: 'premium',
    title: 'Update Premium Rate Matrices',
    desc: 'ตารางเบี้ยประกันต่อผลิตภัณฑ์ ช่วงอายุ และระยะเวลา',
    icon: 'pi pi-table',
    iconBg: 'bg-violet-50', iconColor: 'text-violet-600',
    metricLabel: 'ผลิตภัณฑ์',
    metricValue: '11',
    to: '/products',
  },
  // Commission Rules card removed with the MGM rewrite. Will be replaced
  // by the tier / matrix admin surface when PR-A2/A4 land.
  {
    key: 'audit',
    title: 'View System Audit Logs',
    desc: 'บันทึกการดำเนินการทั้งหมดในระบบ พร้อมตัวกรองช่วงเวลา',
    icon: 'pi pi-history',
    iconBg: 'bg-slate-100', iconColor: 'text-slate-600',
    metricLabel: 'รายการล่าสุด',
    metricValue: '10',
    to: '/settings',
  },
]

// ─────────────────────────────────────────────────────────────────────────────
// Mutations
// ─────────────────────────────────────────────────────────────────────────────

const recentlyApproved = ref<string[]>([])

function approveAgent(id: string) {
  const agent = pendingAgents.value.find((a) => a.id === id)
  if (!agent) return
  pendingAgents.value = pendingAgents.value.filter((a) => a.id !== id)
  recentlyApproved.value = [`${agent.firstName} ${agent.lastName} (${agent.agentCode})`, ...recentlyApproved.value].slice(0, 3)
  setTimeout(() => {
    recentlyApproved.value = recentlyApproved.value.filter((n) => n !== `${agent.firstName} ${agent.lastName} (${agent.agentCode})`)
  }, 4000)
}

const reviewTarget = ref<PendingAgent | null>(null)
function openReview(agent: PendingAgent) {
  reviewTarget.value = agent
}
function closeReview() {
  reviewTarget.value = null
}

function rejectAgent(id: string) {
  pendingAgents.value = pendingAgents.value.filter((a) => a.id !== id)
  reviewTarget.value = null
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function carrierUpdateBadge(type: CarrierUpdate['type']): string {
  return {
    rate: 'bg-emerald-50 text-emerald-700',
    product: 'bg-sky-50 text-sky-700',
    contract: 'bg-amber-50 text-amber-700',
  }[type]
}

function carrierUpdateLabel(type: CarrierUpdate['type']): string {
  return {
    rate: 'ปรับเรท',
    product: 'ผลิตภัณฑ์ใหม่',
    contract: 'สัญญา',
  }[type]
}

function alertIconClass(s: SystemAlert['severity']): string {
  return {
    error: 'bg-rose-100 text-rose-600',
    warning: 'bg-amber-100 text-amber-600',
    info: 'bg-sky-100 text-sky-600',
  }[s]
}

function alertIcon(s: SystemAlert['severity']): string {
  return {
    error: 'pi pi-times-circle',
    warning: 'pi pi-exclamation-triangle',
    info: 'pi pi-info-circle',
  }[s]
}

function relativeDays(thaiDate: string): string {
  // Convert BE date to JS Date for diff
  const [y, m, d] = thaiDate.split('-').map(Number)
  const target = new Date(y - 543, m - 1, d).getTime()
  const today = new Date('2026-06-07').getTime()
  const diffDays = Math.round((today - target) / 86_400_000)
  if (diffDays === 0) return 'วันนี้'
  if (diffDays === 1) return 'เมื่อวาน'
  if (diffDays < 7) return `${diffDays} วันที่แล้ว`
  return thaiDate
}
</script>

<template>
  <div class="space-y-6">
    <!-- ─── Header ─────────────────────────────────────────────────────── -->
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Agent Operation Support</h1>
        <p class="text-slate-500 text-sm mt-1">แดชบอร์ดสำหรับทีมหลังบ้าน — อนุมัติตัวแทน จัดการข้อมูลกลาง และตรวจสอบระบบ</p>
      </div>
      <div class="flex items-center gap-2">
        <button class="px-3 py-2 text-sm border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition flex items-center gap-2">
          <i class="pi pi-download" />
          <span class="hidden sm:inline">ส่งออกรายงาน</span>
        </button>
        <button class="px-3 py-2 text-sm bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2">
          <i class="pi pi-refresh" />
          <span class="hidden sm:inline">รีเฟรช</span>
        </button>
      </div>
    </header>

    <!-- ─── System health cards ────────────────────────────────────────── -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <!-- Pending Registrations -->
      <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:shadow-md transition">
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
            <i class="pi pi-user-plus" />
          </div>
          <span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-medium">รอตรวจสอบ</span>
        </div>
        <div class="mt-4">
          <div class="text-3xl font-semibold text-slate-900">{{ stats.pendingRegistrations }}</div>
          <div class="text-sm text-slate-500 mt-0.5">Pending Registrations</div>
          <div class="text-xs text-slate-400 mt-1">รอตรวจสอบประวัติและเอกสาร</div>
        </div>
      </div>

      <!-- Expiring Licenses -->
      <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:shadow-md transition relative overflow-hidden">
        <div v-if="stats.expiringLicenses > 0" class="absolute top-0 left-0 w-1 h-full bg-amber-500" />
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
            <i class="pi pi-id-card" />
          </div>
          <span class="text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 font-medium">&lt; 30 วัน</span>
        </div>
        <div class="mt-4">
          <div class="text-3xl font-semibold text-slate-900">{{ stats.expiringLicenses }}</div>
          <div class="text-sm text-slate-500 mt-0.5">Expiring Licenses</div>
          <div class="text-xs text-slate-400 mt-1">ใบอนุญาตใกล้หมดอายุ</div>
        </div>
      </div>

      <!-- Carrier Updates Due -->
      <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:shadow-md transition">
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-lg bg-violet-50 text-violet-600 flex items-center justify-center">
            <i class="pi pi-sync" />
          </div>
          <span class="text-xs px-2 py-0.5 rounded-full bg-violet-50 text-violet-700 font-medium">รอรีวิว</span>
        </div>
        <div class="mt-4">
          <div class="text-3xl font-semibold text-slate-900">{{ stats.carrierUpdates }}</div>
          <div class="text-sm text-slate-500 mt-0.5">Carrier Updates Due</div>
          <div class="text-xs text-slate-400 mt-1">การเปลี่ยนแปลงเรท/ผลิตภัณฑ์</div>
        </div>
      </div>

      <!-- System Alerts -->
      <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:shadow-md transition relative overflow-hidden">
        <div v-if="stats.systemAlerts > 0" class="absolute top-0 left-0 w-1 h-full bg-rose-500" />
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
            <i class="pi pi-bell" />
          </div>
          <span class="text-xs px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 font-medium">ระบบ</span>
        </div>
        <div class="mt-4">
          <div class="text-3xl font-semibold text-slate-900">{{ stats.systemAlerts }}</div>
          <div class="text-sm text-slate-500 mt-0.5">System Alerts</div>
          <div class="text-xs text-slate-400 mt-1">sync ผิดพลาด/API หยุด</div>
        </div>
      </div>
    </div>

    <!-- ─── Approval toast (lightweight) ───────────────────────────────── -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0 -translate-y-2"
      enter-to-class="opacity-100 translate-y-0"
    >
      <div
        v-if="recentlyApproved.length"
        class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg px-4 py-3 flex items-center gap-3"
      >
        <i class="pi pi-check-circle text-emerald-600" />
        <div class="flex-1 text-sm">
          <strong class="font-semibold">อนุมัติเรียบร้อย:</strong>
          {{ recentlyApproved.join(' · ') }}
        </div>
      </div>
    </Transition>

    <!-- ─── Split body (60 / 40) ───────────────────────────────────────── -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
      <!-- LEFT 60% — Agent Approval Queue -->
      <section class="lg:col-span-3 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <div>
            <h2 class="font-semibold text-slate-900">Agent Approval Queue</h2>
            <p class="text-xs text-slate-500 mt-0.5">ตัวแทนที่ลงทะเบียนใหม่ รอการอนุมัติ</p>
          </div>
          <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 text-xs font-medium">
            <span class="w-1.5 h-1.5 rounded-full bg-blue-500" />
            {{ stats.pendingRegistrations }} รอตรวจสอบ
          </span>
        </header>

        <div v-if="!pendingAgents.length" class="flex-1 flex items-center justify-center py-16">
          <div class="text-center">
            <div class="w-16 h-16 mx-auto rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3">
              <i class="pi pi-check text-2xl" />
            </div>
            <p class="text-sm font-medium text-slate-700">ไม่มีตัวแทนรอตรวจสอบ</p>
            <p class="text-xs text-slate-400 mt-1">งานทุกอย่างเสร็จเรียบร้อย</p>
          </div>
        </div>

        <ul v-else class="divide-y divide-slate-100 flex-1 overflow-y-auto max-h-[640px]">
          <li v-for="agent in pendingAgents" :key="agent.id" class="px-5 py-4 hover:bg-slate-50/40 transition">
            <div class="flex items-start gap-4">
              <!-- Avatar -->
              <div class="w-11 h-11 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-sm font-medium shrink-0">
                {{ agent.firstName.charAt(0) }}{{ agent.lastName.charAt(0) }}
              </div>

              <!-- Identity + docs -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-sm font-medium text-slate-900 truncate">
                    {{ agent.firstName }} {{ agent.lastName }}
                    <span class="text-slate-400 font-normal">({{ agent.nickname }})</span>
                  </span>
                  <span class="font-mono text-[10px] text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded">{{ agent.agentCode }}</span>
                </div>

                <div class="text-xs text-slate-500 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                  <span class="flex items-center gap-1">
                    <i class="pi pi-envelope text-[10px] text-slate-400" />
                    {{ agent.email }}
                  </span>
                  <span class="flex items-center gap-1">
                    <i class="pi pi-phone text-[10px] text-slate-400" />
                    {{ agent.phone }}
                  </span>
                </div>

                <div class="text-xs text-slate-500 mt-1 flex flex-wrap items-center gap-x-3">
                  <span>upline: <span class="text-slate-700 font-medium">{{ agent.uplineName }}</span></span>
                  <span class="text-slate-300">·</span>
                  <span>{{ agent.region }}</span>
                  <span class="text-slate-300">·</span>
                  <span class="text-slate-400">ส่ง {{ relativeDays(agent.submittedAt) }}</span>
                </div>

                <!-- Doc checklist -->
                <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                  <span
                    v-for="(checked, idx) in [
                      { label: 'ID', val: agent.hasIdCard },
                      { label: 'License', val: agent.hasLicense },
                      { label: 'Contract', val: agent.hasContract },
                      { label: 'BG Check', val: agent.hasBgCheck },
                    ]"
                    :key="idx"
                    :class="[
                      'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-medium',
                      checked.val ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-400',
                    ]"
                  >
                    <i :class="checked.val ? 'pi pi-check' : 'pi pi-minus'" class="text-[9px]" />
                    {{ checked.label }}
                  </span>
                  <span
                    v-if="!agent.docsComplete"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-amber-50 text-amber-700 text-[10px] font-medium"
                  >
                    <i class="pi pi-exclamation-triangle text-[9px]" />
                    เอกสารไม่ครบ
                  </span>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex flex-col items-end gap-1.5 shrink-0">
                <button
                  @click="approveAgent(agent.id)"
                  :disabled="!agent.docsComplete"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-md hover:bg-emerald-700 disabled:bg-slate-200 disabled:text-slate-400 disabled:cursor-not-allowed transition"
                >
                  <i class="pi pi-check text-[10px]" />
                  Approve
                </button>
                <button
                  @click="openReview(agent)"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-md hover:bg-blue-700 transition"
                >
                  <i class="pi pi-file-edit text-[10px]" />
                  Review Documents
                </button>
              </div>
            </div>
          </li>
        </ul>
      </section>

      <!-- RIGHT 40% — Master Data Management Console -->
      <section class="lg:col-span-2 space-y-4">
        <!-- Master data console -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <header class="px-5 py-4 border-b border-slate-100">
            <h2 class="font-semibold text-slate-900">Master Data Management</h2>
            <p class="text-xs text-slate-500 mt-0.5">เครื่องมือจัดการข้อมูลกลางของระบบ</p>
          </header>

          <div class="p-3 space-y-2">
            <RouterLink
              v-for="link in masterDataLinks"
              :key="link.key"
              :to="link.to"
              class="group flex items-start gap-3 p-3 rounded-lg border border-transparent hover:bg-slate-50 hover:border-slate-200 transition"
            >
              <div :class="['w-10 h-10 rounded-lg flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform', link.iconBg, link.iconColor]">
                <i :class="link.icon" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 text-sm font-medium text-slate-900 group-hover:text-brand-700 transition">
                  {{ link.title }}
                  <i class="pi pi-arrow-up-right text-[10px] text-slate-300 group-hover:text-brand-500 transition" />
                </div>
                <div class="text-xs text-slate-500 mt-0.5 truncate">{{ link.desc }}</div>
                <div class="mt-1.5 text-[10px] text-slate-400">
                  {{ link.metricLabel }}: <span class="text-slate-700 font-semibold">{{ link.metricValue }}</span>
                </div>
              </div>
            </RouterLink>
          </div>
        </div>

        <!-- Expiring licenses card -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
              <i class="pi pi-id-card text-amber-500 text-xs" />
              ใบอนุญาตใกล้หมดอายุ
            </h3>
            <span class="text-xs text-slate-400">{{ expiringLicenses.length }} ราย</span>
          </header>
          <ul class="divide-y divide-slate-100">
            <li v-for="(l, idx) in expiringLicenses" :key="idx" class="px-5 py-3 text-sm flex items-center gap-3">
              <div class="flex-1 min-w-0">
                <div class="text-slate-900 truncate">{{ l.agentName }}</div>
                <div class="text-xs text-slate-500 font-mono mt-0.5">{{ l.agentCode }} · {{ l.licenseNo }}</div>
              </div>
              <span :class="[
                'inline-flex flex-col items-end',
                l.daysLeft <= 14 ? 'text-rose-600' : 'text-amber-600',
              ]">
                <span class="text-sm font-semibold">{{ l.daysLeft }} วัน</span>
                <span class="text-[10px] text-slate-400">{{ l.expiresOn }}</span>
              </span>
            </li>
          </ul>
        </div>

        <!-- Carrier updates card -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
              <i class="pi pi-sync text-violet-500 text-xs" />
              การอัปเดตของบริษัทประกัน
            </h3>
            <span class="text-xs text-slate-400">{{ carrierUpdates.length }} รายการ</span>
          </header>
          <ul class="divide-y divide-slate-100">
            <li v-for="(u, idx) in carrierUpdates" :key="idx" class="px-5 py-3 text-sm">
              <div class="flex items-center gap-2 mb-1">
                <span class="font-mono text-[10px] text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded">{{ u.carrierCode }}</span>
                <span :class="['inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium', carrierUpdateBadge(u.type)]">
                  {{ carrierUpdateLabel(u.type) }}
                </span>
                <span class="ml-auto text-[10px] text-slate-400">เริ่ม {{ u.effectiveOn }}</span>
              </div>
              <div class="text-xs text-slate-700 leading-relaxed">{{ u.description }}</div>
            </li>
          </ul>
        </div>

        <!-- System alerts card -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
              <i class="pi pi-bell text-rose-500 text-xs" />
              ระบบและการเชื่อมต่อ
            </h3>
            <span class="text-xs text-slate-400">{{ systemAlerts.length }} เคส</span>
          </header>
          <ul class="divide-y divide-slate-100">
            <li v-for="a in systemAlerts" :key="a.id" class="px-5 py-3 flex items-start gap-3">
              <div :class="['w-8 h-8 rounded-lg flex items-center justify-center shrink-0', alertIconClass(a.severity)]">
                <i :class="alertIcon(a.severity) + ' text-xs'" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-sm text-slate-900 truncate">{{ a.message }}</div>
                <div class="text-[10px] text-slate-400 mt-0.5 font-mono">{{ a.source }} · {{ a.occurredAt }}</div>
              </div>
            </li>
          </ul>
        </div>
      </section>
    </div>

    <!-- ─── Review modal ───────────────────────────────────────────────── -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="reviewTarget"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40"
      >
        <div class="bg-white rounded-xl shadow-xl w-full max-w-lg">
          <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-semibold text-slate-900">ตรวจสอบเอกสารตัวแทน</h3>
            <button @click="closeReview" class="text-slate-400 hover:text-slate-700">
              <i class="pi pi-times" />
            </button>
          </header>
          <div class="px-5 py-5 space-y-4">
            <div class="flex items-start gap-3">
              <div class="w-12 h-12 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-base font-medium shrink-0">
                {{ reviewTarget.firstName.charAt(0) }}{{ reviewTarget.lastName.charAt(0) }}
              </div>
              <div>
                <div class="font-semibold text-slate-900">{{ reviewTarget.firstName }} {{ reviewTarget.lastName }} <span class="text-slate-400 font-normal">({{ reviewTarget.nickname }})</span></div>
                <div class="text-xs text-slate-500 font-mono mt-0.5">{{ reviewTarget.agentCode }} · ลงทะเบียน {{ relativeDays(reviewTarget.submittedAt) }}</div>
                <div class="text-xs text-slate-600 mt-1">upline: {{ reviewTarget.uplineName }} · {{ reviewTarget.region }}</div>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-3 space-y-2">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">เอกสารที่ส่งมา</h4>
              <div
                v-for="(item, idx) in [
                  { label: 'สำเนาบัตรประชาชน', val: reviewTarget.hasIdCard, file: 'id_card_scanned.pdf' },
                  { label: 'ใบอนุญาตตัวแทน คปภ.', val: reviewTarget.hasLicense, file: 'oic_license.pdf' },
                  { label: 'สัญญาตัวแทน', val: reviewTarget.hasContract, file: 'agency_contract.pdf' },
                  { label: 'ผลตรวจประวัติอาชญากรรม', val: reviewTarget.hasBgCheck, file: 'background_check.pdf' },
                ]"
                :key="idx"
                class="flex items-center gap-3 p-2.5 border border-slate-200 rounded-lg"
                :class="!item.val && 'opacity-60'"
              >
                <i :class="[
                  item.val ? 'pi pi-check-circle text-emerald-500' : 'pi pi-times-circle text-rose-400',
                  'shrink-0',
                ]" />
                <div class="flex-1 min-w-0">
                  <div class="text-sm text-slate-900">{{ item.label }}</div>
                  <div class="text-xs text-slate-400 font-mono truncate">{{ item.val ? item.file : 'ไม่มีไฟล์' }}</div>
                </div>
                <button v-if="item.val" class="text-xs text-blue-600 hover:underline">ดู</button>
              </div>
            </div>

            <div v-if="!reviewTarget.docsComplete" class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-exclamation-triangle mt-0.5" />
              <span>เอกสารไม่ครบ — ส่งกลับเพื่อขอเอกสารเพิ่มเติมก่อนอนุมัติ</span>
            </div>
          </div>
          <footer class="px-5 py-4 border-t border-slate-100 flex justify-between gap-2 bg-slate-50/50 rounded-b-xl">
            <button
              @click="rejectAgent(reviewTarget.id)"
              class="px-4 py-2 text-sm rounded-lg border border-rose-300 text-rose-700 hover:bg-rose-50 transition"
            >
              ปฏิเสธคำขอ
            </button>
            <div class="flex gap-2">
              <button @click="closeReview" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
                ปิด
              </button>
              <button
                @click="approveAgent(reviewTarget.id); closeReview()"
                :disabled="!reviewTarget.docsComplete"
                class="px-4 py-2 text-sm rounded-lg bg-emerald-600 text-white font-medium hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5 transition"
              >
                <i class="pi pi-check text-xs" />
                อนุมัติเลย
              </button>
            </div>
          </footer>
        </div>
      </div>
    </Transition>
  </div>
</template>
