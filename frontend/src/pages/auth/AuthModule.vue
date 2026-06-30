<script setup lang="ts">
import { ref, computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Role = 'admin' | 'manager' | 'agent' | 'accountant' | 'viewer'
type Status = 'active' | 'invited' | 'revoked'

interface Member {
  id: string
  firstName: string
  lastName: string
  email: string
  role: Role
  status: Status
  mfa: boolean
  lastLogin: string | null
}

interface Invitation {
  id: string
  email: string
  role: Role
  invitedBy: string
  sentAt: string
  expiresAt: string
}

const tab = ref<'members' | 'invitations' | 'roles'>('members')

const members = ref<Member[]>([
  { id: 'u1', firstName: 'สมชาย', lastName: 'แก้วประเสริฐ', email: 'somchai@abc-insure.co.th', role: 'admin', status: 'active', mfa: true, lastLogin: '2026-06-05 09:12' },
  { id: 'u2', firstName: 'จิราภรณ์', lastName: 'พงษ์ศิริ', email: 'jirapron@abc-insure.co.th', role: 'manager', status: 'active', mfa: true, lastLogin: '2026-06-04 17:48' },
  { id: 'u3', firstName: 'อนุชา', lastName: 'ใจดี', email: 'anucha@abc-insure.co.th', role: 'agent', status: 'active', mfa: false, lastLogin: '2026-06-05 08:30' },
  { id: 'u4', firstName: 'พรทิพย์', lastName: 'มั่นคง', email: 'porntip@abc-insure.co.th', role: 'agent', status: 'active', mfa: false, lastLogin: '2026-06-03 14:22' },
  { id: 'u5', firstName: 'ณัฐวุฒิ', lastName: 'รัตนา', email: 'nattawut@abc-insure.co.th', role: 'accountant', status: 'active', mfa: true, lastLogin: '2026-06-05 10:05' },
  { id: 'u6', firstName: 'วรรณา', lastName: 'สุขใจ', email: 'wanna@abc-insure.co.th', role: 'viewer', status: 'revoked', mfa: false, lastLogin: '2026-05-12 16:10' },
])

const invitations = ref<Invitation[]>([
  { id: 'i1', email: 'newagent1@abc-insure.co.th', role: 'agent', invitedBy: 'สมชาย แก้วประเสริฐ', sentAt: '2026-06-04', expiresAt: '2026-06-11' },
  { id: 'i2', email: 'manager.new@abc-insure.co.th', role: 'manager', invitedBy: 'สมชาย แก้วประเสริฐ', sentAt: '2026-06-03', expiresAt: '2026-06-10' },
])

const search = ref('')
const roleFilter = ref<'all' | Role>('all')
const statusFilter = ref<'all' | Status>('all')

const filteredMembers = computed(() => {
  return members.value.filter((m) => {
    if (roleFilter.value !== 'all' && m.role !== roleFilter.value) return false
    if (statusFilter.value !== 'all' && m.status !== statusFilter.value) return false
    if (search.value) {
      const q = search.value.toLowerCase()
      const hay = `${m.firstName} ${m.lastName} ${m.email}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  })
})

const roleList: Role[] = ['admin', 'manager', 'agent', 'accountant', 'viewer']

function roleClass(role: Role) {
  const map: Record<Role, string> = {
    admin: 'bg-rose-50 text-rose-700',
    manager: 'bg-violet-50 text-violet-700',
    agent: 'bg-sky-50 text-sky-700',
    accountant: 'bg-amber-50 text-amber-700',
    viewer: 'bg-slate-100 text-slate-600',
  }
  return map[role]
}

function statusClass(status: Status) {
  const map: Record<Status, string> = {
    active: 'bg-emerald-50 text-emerald-700',
    invited: 'bg-amber-50 text-amber-700',
    revoked: 'bg-slate-100 text-slate-500',
  }
  return map[status]
}

// Invite dialog
const showInvite = ref(false)
const inviteForm = reactive({ email: '', role: 'agent' as Role, message: '' })
const inviteSubmitting = ref(false)

async function submitInvite() {
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(inviteForm.email)) return
  inviteSubmitting.value = true
  await new Promise((r) => setTimeout(r, 400))
  invitations.value.unshift({
    id: 'i' + Date.now(),
    email: inviteForm.email,
    role: inviteForm.role,
    invitedBy: 'สมชาย แก้วประเสริฐ',
    sentAt: new Date().toISOString().slice(0, 10),
    expiresAt: new Date(Date.now() + 7 * 86_400_000).toISOString().slice(0, 10),
  })
  inviteForm.email = ''
  inviteForm.role = 'agent'
  inviteForm.message = ''
  inviteSubmitting.value = false
  showInvite.value = false
}

// Revoke dialog
const revokeTarget = ref<Member | null>(null)
function confirmRevoke() {
  if (!revokeTarget.value) return
  const target = revokeTarget.value
  members.value = members.value.map((m) => (m.id === target.id ? { ...m, status: 'revoked' } : m))
  revokeTarget.value = null
}

function reactivate(m: Member) {
  members.value = members.value.map((x) => (x.id === m.id ? { ...x, status: 'active' } : x))
}

function cancelInvitation(inv: Invitation) {
  invitations.value = invitations.value.filter((i) => i.id !== inv.id)
}

function resendInvitation(inv: Invitation) {
  invitations.value = invitations.value.map((i) =>
    i.id === inv.id
      ? { ...i, sentAt: new Date().toISOString().slice(0, 10), expiresAt: new Date(Date.now() + 7 * 86_400_000).toISOString().slice(0, 10) }
      : i,
  )
}

// Role editing (inline)
const editingMember = ref<string | null>(null)
function changeRole(m: Member, role: Role) {
  members.value = members.value.map((x) => (x.id === m.id ? { ...x, role } : x))
  editingMember.value = null
}

const counts = computed(() => ({
  members: members.value.filter((m) => m.status === 'active').length,
  invitations: invitations.value.length,
}))
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.auth.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.auth.description') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <RouterLink
          to="/auth/mfa"
          class="px-4 py-2.5 border border-slate-300 text-slate-700 rounded-lg font-medium hover:bg-slate-50 transition flex items-center gap-2"
        >
          <i class="pi pi-shield" />
          <span class="hidden sm:inline">ตั้งค่า MFA</span>
        </RouterLink>
        <button
          type="button"
          @click="showInvite = true"
          class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2"
        >
          <i class="pi pi-user-plus" />
          <span class="hidden sm:inline">{{ t('auth.users.actions.invite') }}</span>
        </button>
      </div>
    </header>

    <!-- Tabs -->
    <div class="border-b border-slate-200 flex items-center gap-1">
      <button
        v-for="tk in (['members', 'invitations', 'roles'] as const)"
        :key="tk"
        type="button"
        @click="tab = tk"
        :class="[
          'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px flex items-center gap-2 transition',
          tab === tk
            ? 'border-brand-600 text-brand-700'
            : 'border-transparent text-slate-500 hover:text-slate-900',
        ]"
      >
        {{ t(`auth.users.tabs.${tk}`) }}
        <span
          v-if="tk === 'members' || tk === 'invitations'"
          :class="['inline-flex px-1.5 py-0.5 rounded text-[10px]', tab === tk ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-500']"
        >
          {{ tk === 'members' ? counts.members : counts.invitations }}
        </span>
      </button>
    </div>

    <!-- Members tab -->
    <section v-if="tab === 'members'" class="space-y-4">
      <div class="flex flex-wrap items-center gap-3">
        <div class="relative flex-1 min-w-[200px]">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input
            v-model="search"
            type="search"
            :placeholder="t('common.search') + ' (ชื่อ, อีเมล)'"
            class="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-400"
          />
        </div>
        <select
          v-model="roleFilter"
          class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400"
        >
          <option value="all">{{ t('auth.users.table.role') }}: {{ t('common.all') }}</option>
          <option v-for="r in roleList" :key="r" :value="r">
            {{ t(`auth.users.roles.${r}`) }}
          </option>
        </select>
        <select
          v-model="statusFilter"
          class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400"
        >
          <option value="all">{{ t('common.status') }}: {{ t('common.all') }}</option>
          <option value="active">{{ t('auth.users.status.active') }}</option>
          <option value="revoked">{{ t('auth.users.status.revoked') }}</option>
        </select>
      </div>

      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
              <tr>
                <th class="text-left px-4 py-3 font-medium">{{ t('auth.users.table.name') }}</th>
                <th class="text-left px-4 py-3 font-medium">{{ t('auth.users.table.role') }}</th>
                <th class="text-left px-4 py-3 font-medium">{{ t('auth.users.table.status') }}</th>
                <th class="text-left px-4 py-3 font-medium">{{ t('auth.users.table.mfa') }}</th>
                <th class="text-left px-4 py-3 font-medium">{{ t('auth.users.table.lastLogin') }}</th>
                <th class="text-right px-4 py-3 font-medium">{{ t('common.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="m in filteredMembers" :key="m.id" class="hover:bg-slate-50/50">
                <td class="px-4 py-3">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-medium shrink-0">
                      {{ m.firstName.charAt(0) }}{{ m.lastName.charAt(0) }}
                    </div>
                    <div class="min-w-0">
                      <div class="font-medium text-slate-900 truncate">{{ m.firstName }} {{ m.lastName }}</div>
                      <div class="text-xs text-slate-500 truncate">{{ m.email }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3">
                  <div v-if="editingMember === m.id" class="flex items-center gap-1">
                    <select
                      :value="m.role"
                      @change="changeRole(m, ($event.target as HTMLSelectElement).value as Role)"
                      class="px-2 py-1 text-xs border border-slate-300 rounded-md"
                    >
                      <option v-for="r in roleList" :key="r" :value="r">
                        {{ t(`auth.users.roles.${r}`) }}
                      </option>
                    </select>
                    <button @click="editingMember = null" class="p-1 text-slate-400 hover:text-slate-600">
                      <i class="pi pi-times text-xs" />
                    </button>
                  </div>
                  <span
                    v-else
                    :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', roleClass(m.role)]"
                  >
                    {{ t(`auth.users.roles.${m.role}`) }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', statusClass(m.status)]">
                    {{ t(`auth.users.status.${m.status}`) }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <span :class="['inline-flex items-center gap-1 text-xs', m.mfa ? 'text-emerald-700' : 'text-slate-400']">
                    <i :class="m.mfa ? 'pi pi-shield' : 'pi pi-times'" />
                    {{ m.mfa ? 'เปิด' : 'ปิด' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-xs text-slate-500">{{ m.lastLogin ?? '–' }}</td>
                <td class="px-4 py-3">
                  <div class="flex items-center justify-end gap-1">
                    <button
                      type="button"
                      @click="editingMember = m.id"
                      class="px-2 py-1 text-xs text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded transition"
                      :title="t('auth.users.actions.editRole')"
                    >
                      <i class="pi pi-pencil" />
                    </button>
                    <button
                      v-if="m.status === 'active'"
                      type="button"
                      @click="revokeTarget = m"
                      class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded transition"
                      :title="t('auth.users.actions.revoke')"
                    >
                      <i class="pi pi-ban" />
                    </button>
                    <button
                      v-else
                      type="button"
                      @click="reactivate(m)"
                      class="px-2 py-1 text-xs text-emerald-600 hover:bg-emerald-50 rounded transition"
                      :title="t('auth.users.actions.reactivate')"
                    >
                      <i class="pi pi-check-circle" />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!filteredMembers.length">
                <td colspan="6" class="px-4 py-10 text-center text-slate-400 text-sm">
                  {{ t('common.noData') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- Invitations tab -->
    <section v-if="tab === 'invitations'" class="space-y-4">
      <div class="card overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="text-left px-4 py-3 font-medium">{{ t('auth.users.table.email') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('auth.users.table.role') }}</th>
              <th class="text-left px-4 py-3 font-medium">เชิญโดย</th>
              <th class="text-left px-4 py-3 font-medium">ส่งเมื่อ</th>
              <th class="text-left px-4 py-3 font-medium">หมดอายุ</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="inv in invitations" :key="inv.id" class="hover:bg-slate-50/50">
              <td class="px-4 py-3 text-slate-900">{{ inv.email }}</td>
              <td class="px-4 py-3">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', roleClass(inv.role)]">
                  {{ t(`auth.users.roles.${inv.role}`) }}
                </span>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ inv.invitedBy }}</td>
              <td class="px-4 py-3 text-slate-500 text-xs">{{ inv.sentAt }}</td>
              <td class="px-4 py-3 text-slate-500 text-xs">{{ inv.expiresAt }}</td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    @click="resendInvitation(inv)"
                    class="px-2.5 py-1 text-xs border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-md transition"
                  >
                    <i class="pi pi-send mr-1" />
                    {{ t('auth.users.actions.resend') }}
                  </button>
                  <button
                    type="button"
                    @click="cancelInvitation(inv)"
                    class="px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded-md transition"
                  >
                    {{ t('auth.users.actions.cancelInvite') }}
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!invitations.length">
              <td colspan="6" class="px-4 py-10 text-center text-slate-400 text-sm">
                {{ t('common.noData') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Roles tab -->
    <section v-if="tab === 'roles'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div v-for="r in roleList" :key="r" class="card p-5">
        <div class="flex items-center gap-3 mb-3">
          <span :class="['w-2 h-2 rounded-full', roleClass(r)]" />
          <h3 class="font-semibold text-slate-900">{{ t(`auth.users.roles.${r}`) }}</h3>
        </div>
        <p class="text-sm text-slate-500 leading-relaxed">
          {{ t(`auth.users.roles.${r}Desc`) }}
        </p>
        <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-400">
          {{ members.filter((m) => m.role === r && m.status === 'active').length }} ผู้ใช้ที่กำลังใช้งาน
        </div>
      </div>
    </section>

    <!-- Invite dialog -->
    <div v-if="showInvite" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" @click.self="showInvite = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <h3 class="font-semibold text-slate-900">{{ t('auth.users.inviteDialog.title') }}</h3>
          <button @click="showInvite = false" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>
        <form class="px-5 py-4 space-y-4" @submit.prevent="submitInvite">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">
              {{ t('auth.users.inviteDialog.email') }}
            </label>
            <input
              v-model="inviteForm.email"
              type="email"
              required
              :placeholder="t('auth.users.inviteDialog.emailPlaceholder')"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">
              {{ t('auth.users.inviteDialog.role') }}
            </label>
            <select
              v-model="inviteForm.role"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            >
              <option v-for="r in roleList" :key="r" :value="r">
                {{ t(`auth.users.roles.${r}`) }} – {{ t(`auth.users.roles.${r}Desc`) }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">
              {{ t('auth.users.inviteDialog.message') }}
            </label>
            <textarea
              v-model="inviteForm.message"
              rows="3"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
            />
          </div>
        </form>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button
            type="button"
            @click="showInvite = false"
            class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            @click="submitInvite"
            :disabled="inviteSubmitting"
            class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-50 flex items-center gap-2"
          >
            <i v-if="inviteSubmitting" class="pi pi-spin pi-spinner" />
            <span>{{ t('auth.users.inviteDialog.send') }}</span>
          </button>
        </footer>
      </div>
    </div>

    <!-- Revoke confirmation -->
    <div v-if="revokeTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" @click.self="revokeTarget = null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-5 py-5">
          <div class="w-10 h-10 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mb-3">
            <i class="pi pi-exclamation-triangle" />
          </div>
          <h3 class="font-semibold text-slate-900">{{ t('auth.users.revokeDialog.title') }}</h3>
          <p class="text-sm text-slate-500 mt-1.5">{{ t('auth.users.revokeDialog.message') }}</p>
          <div class="mt-3 p-3 bg-slate-50 border border-slate-100 rounded-lg text-sm">
            <div class="font-medium text-slate-900">{{ revokeTarget.firstName }} {{ revokeTarget.lastName }}</div>
            <div class="text-xs text-slate-500">{{ revokeTarget.email }}</div>
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button
            type="button"
            @click="revokeTarget = null"
            class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            @click="confirmRevoke"
            class="px-4 py-2 text-sm rounded-lg bg-rose-600 text-white font-medium hover:bg-rose-700"
          >
            {{ t('auth.users.actions.revoke') }}
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>
