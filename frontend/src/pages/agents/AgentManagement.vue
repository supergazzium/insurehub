<script setup lang="ts">
// จัดการตัวแทน — create / edit / approve / deactivate.
import { onMounted, ref, reactive, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchAgentList, fetchPendingAgents, approveAgent, rejectAgent, setAgentActive,
  type AgentListRow,
} from '../../api/agents'
import { ApiError } from '../../api/client'
import AgentsSubnav from './AgentsSubnav.vue'

const router = useRouter()

const rows = ref<AgentListRow[]>([])
const pending = ref<AgentListRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const busy = ref<string | null>(null)
const flash = ref<{ ok: boolean; text: string } | null>(null)
const meta = ref<{ currentPage: number; lastPage: number; total: number } | null>(null)

const filters = reactive({ q: '', status: 'all' as 'all' | 'active' | 'inactive' | 'pending', page: 1 })

function setFlash(ok: boolean, text: string) { flash.value = { ok, text }; setTimeout(() => flash.value = null, 3500) }

let debounce: number | undefined
async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [list, pend] = await Promise.all([
      fetchAgentList({
        q: filters.q || undefined,
        activeOnly: filters.status === 'active' || undefined,
        page: filters.page, perPage: 25,
      }),
      fetchPendingAgents().catch(() => ({ data: [] as AgentListRow[] })),
    ])
    let data = list.data
    if (filters.status === 'inactive') data = data.filter((a) => !a.active)
    if (filters.status === 'pending') data = data.filter((a) => a.approvalStatus === 'pending')
    rows.value = data
    pending.value = pend.data
    const m = list.meta
    meta.value = m ? { currentPage: m.current_page, lastPage: m.last_page, total: m.total } : null
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally { loading.value = false }
}
function reload(): void { window.clearTimeout(debounce); debounce = window.setTimeout(() => { filters.page = 1; load() }, 250) }
function goPage(p: number): void { filters.page = p; load() }

function agentName(a: AgentListRow): string {
  return `${a.firstName ?? ''} ${a.lastName ?? ''}`.trim() || a.agentCode
}

function createNew(): void { router.push({ name: 'agent-new' }) }
function edit(a: AgentListRow): void { router.push({ name: 'agent-edit-info', params: { id: a.id } }) }
function openDetail(a: AgentListRow): void { router.push({ name: 'agent-detail', params: { id: a.id } }) }

async function approve(a: AgentListRow): Promise<void> {
  busy.value = a.id
  try { await approveAgent(a.id); setFlash(true, `อนุมัติ ${agentName(a)} แล้ว`); await load() }
  catch (e: unknown) { setFlash(false, e instanceof ApiError ? e.message : 'อนุมัติล้มเหลว') }
  finally { busy.value = null }
}
async function reject(a: AgentListRow): Promise<void> {
  const note = window.prompt(`เหตุผลที่ปฏิเสธ ${agentName(a)}:`)
  if (note === null || note.trim() === '') return
  busy.value = a.id
  try { await rejectAgent(a.id, note.trim()); setFlash(true, `ปฏิเสธ ${agentName(a)} แล้ว`); await load() }
  catch (e: unknown) { setFlash(false, e instanceof ApiError ? e.message : 'ปฏิเสธล้มเหลว') }
  finally { busy.value = null }
}
async function toggleActive(a: AgentListRow): Promise<void> {
  const next = !a.active
  if (!next && !window.confirm(`ปิดใช้งาน ${agentName(a)}?`)) return
  busy.value = a.id
  try { await setAgentActive(a.id, next); setFlash(true, next ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว'); await load() }
  catch (e: unknown) { setFlash(false, e instanceof ApiError ? e.message : 'ดำเนินการล้มเหลว') }
  finally { busy.value = null }
}

const STATUS_TABS = [
  { key: 'all', label: 'ทั้งหมด' }, { key: 'active', label: 'เปิดใช้งาน' },
  { key: 'inactive', label: 'ปิดใช้งาน' }, { key: 'pending', label: 'รออนุมัติ' },
] as const

onMounted(load)
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-6xl px-4 py-5">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-lg font-semibold text-slate-800">จัดการตัวแทน</h1>
        <button type="button" class="rounded bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700" @click="createNew">
          <i class="pi pi-plus text-[10px]" /> เพิ่มตัวแทน
        </button>
      </div>

      <!-- Pending approvals banner -->
      <div v-if="pending.length" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
        <div class="mb-2 text-xs font-medium text-amber-800">
          <i class="pi pi-clock text-[10px]" /> รออนุมัติ {{ pending.length }} ราย
        </div>
        <ul class="space-y-1">
          <li v-for="a in pending" :key="a.id" class="flex items-center justify-between gap-2 rounded bg-white px-2 py-1.5 text-xs">
            <span class="text-slate-700">{{ agentName(a) }} <span class="text-slate-400">{{ a.agentCode }}</span></span>
            <span class="flex gap-1.5">
              <button type="button" class="rounded bg-emerald-600 px-2 py-0.5 text-white disabled:opacity-50" :disabled="busy === a.id" @click="approve(a)">อนุมัติ</button>
              <button type="button" class="rounded border border-rose-300 px-2 py-0.5 text-rose-600 disabled:opacity-50" :disabled="busy === a.id" @click="reject(a)">ปฏิเสธ</button>
            </span>
          </li>
        </ul>
      </div>

      <!-- Filters -->
      <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="inline-flex overflow-hidden rounded-lg border border-slate-200">
          <button v-for="t in STATUS_TABS" :key="t.key" type="button"
            :class="['px-3 py-1 text-xs', filters.status === t.key ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']"
            @click="filters.status = t.key; reload()">{{ t.label }}</button>
        </div>
        <input v-model="filters.q" @input="reload" placeholder="ค้นหา ชื่อ/รหัส/อีเมล"
          class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
      </div>

      <transition name="fade">
        <div v-if="flash" :class="['mb-3 rounded px-3 py-2 text-sm', flash.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']">{{ flash.text }}</div>
      </transition>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <div v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</div>

      <div v-else class="overflow-hidden rounded-lg border border-slate-200">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-xs text-slate-500">
            <tr>
              <th class="px-3 py-2 text-left">รหัส</th>
              <th class="px-3 py-2 text-left">ชื่อ</th>
              <th class="px-3 py-2 text-left">ประเภท</th>
              <th class="px-3 py-2 text-left">ติดต่อ</th>
              <th class="px-3 py-2 text-center">สถานะ</th>
              <th class="px-3 py-2 text-right">จัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="a in rows" :key="a.id" class="hover:bg-slate-50">
              <td class="px-3 py-2 font-mono text-xs text-slate-600">{{ a.agentCode }}</td>
              <td class="px-3 py-2">
                <button type="button" class="text-slate-800 hover:text-brand-700" @click="openDetail(a)">{{ agentName(a) }}</button>
                <span v-if="a.nickname" class="ml-1 text-xs text-slate-400">({{ a.nickname }})</span>
              </td>
              <td class="px-3 py-2 text-xs text-slate-500">{{ a.agentType }} · {{ (a.level || '').toUpperCase() }}</td>
              <td class="px-3 py-2 text-xs text-slate-500">{{ a.email || a.phone || '—' }}</td>
              <td class="px-3 py-2 text-center">
                <span v-if="a.approvalStatus === 'pending'" class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-700">รออนุมัติ</span>
                <span v-else-if="a.active" class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] text-emerald-700">เปิดใช้งาน</span>
                <span v-else class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">ปิดใช้งาน</span>
              </td>
              <td class="px-3 py-2 text-right">
                <div class="flex justify-end gap-1.5">
                  <button v-if="a.approvalStatus === 'pending'" type="button" class="rounded bg-emerald-600 px-2 py-1 text-xs text-white disabled:opacity-50" :disabled="busy === a.id" @click="approve(a)">อนุมัติ</button>
                  <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50" @click="edit(a)">แก้ไข</button>
                  <button type="button"
                    :class="['rounded px-2 py-1 text-xs disabled:opacity-50', a.active ? 'border border-rose-300 text-rose-600 hover:bg-rose-50' : 'border border-emerald-300 text-emerald-600 hover:bg-emerald-50']"
                    :disabled="busy === a.id" @click="toggleActive(a)">{{ a.active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}</button>
                </div>
              </td>
            </tr>
            <tr v-if="!rows.length"><td colspan="6" class="px-3 py-10 text-center text-sm text-slate-400">ไม่มีตัวแทน</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="meta && meta.lastPage > 1" class="mt-3 flex items-center justify-end gap-2 text-xs">
        <button type="button" class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40" :disabled="filters.page <= 1" @click="goPage(filters.page - 1)">ก่อนหน้า</button>
        <span class="text-slate-500">หน้า {{ meta.currentPage }} / {{ meta.lastPage }}</span>
        <button type="button" class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40" :disabled="filters.page >= meta.lastPage" @click="goPage(filters.page + 1)">ถัดไป</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
