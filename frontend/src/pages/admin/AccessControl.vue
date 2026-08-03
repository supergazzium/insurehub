<script setup lang="ts">
// Unified access-control admin page. Merges the old /auth prototype
// (mock users/roles) and /admin/roles into one workspace with tabs.
//
// Backends used:
// - Users tab   → /admin/users (list, role assignment, per-user overrides)
// - Roles tab   → /admin/roles + /admin/permissions (full CRUD)
// - MFA tab     → placeholder / links to /auth/mfa (existing flow)
//
// Tab state is reflected in the URL query string (?tab=...) so a URL
// deep-link into a specific tab works and browser back/forward is sane.
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  fetchRoles, fetchRole, fetchPermissions,
  createRole, updateRole, setRolePermissions, deleteRole,
  fetchUsers, fetchUser, setUserRole, addUserOverride, removeUserOverride,
  type AdminRoleRow, type AdminPermissionGroup, type AdminUserRow,
} from '../../api/adminRoles'
import { ApiError } from '../../api/client'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

type TabKey = 'users' | 'roles' | 'mfa'
const validTabs: TabKey[] = ['users', 'roles', 'mfa']

const activeTab = ref<TabKey>(
  (typeof route.query.tab === 'string' && validTabs.includes(route.query.tab as TabKey))
    ? route.query.tab as TabKey
    : 'users',
)
watch(activeTab, (tab) => {
  router.replace({ query: { ...route.query, tab } })
})

// ─────────────────────────────────────────────────────────────────
// Shared state (roles are loaded once and reused across tabs)
// ─────────────────────────────────────────────────────────────────
const roles = ref<AdminRoleRow[]>([])
const permissionGroups = ref<AdminPermissionGroup[]>([])
const rolesLoading = ref(false)
const permsLoading = ref(false)
const globalError = ref<string | null>(null)

function labelFor(r: { nameTh: string; nameEn: string }): string {
  return locale.value === 'th' ? r.nameTh : r.nameEn
}

async function loadRoles(): Promise<void> {
  rolesLoading.value = true
  try {
    roles.value = await fetchRoles()
  } catch (e: unknown) {
    globalError.value = e instanceof ApiError ? e.message : 'Failed to load roles.'
  } finally {
    rolesLoading.value = false
  }
}
async function loadPermissions(): Promise<void> {
  if (permissionGroups.value.length > 0) return
  permsLoading.value = true
  try {
    permissionGroups.value = await fetchPermissions()
  } catch (e: unknown) {
    globalError.value = e instanceof ApiError ? e.message : 'Failed to load permissions.'
  } finally {
    permsLoading.value = false
  }
}
onMounted(async () => {
  await loadRoles()
  await loadPermissions()
})

// ─────────────────────────────────────────────────────────────────
// Users tab
// ─────────────────────────────────────────────────────────────────
const users = ref<AdminUserRow[]>([])
const usersLoading = ref(false)
const usersError = ref<string | null>(null)
const userSearch = ref('')
const userRoleFilter = ref<string>('all')
const savingUserId = ref<string | null>(null)

async function loadUsers(): Promise<void> {
  usersLoading.value = true
  usersError.value = null
  try {
    users.value = await fetchUsers({
      search: userSearch.value.trim() || undefined,
      roleId: userRoleFilter.value !== 'all' ? userRoleFilter.value : undefined,
    })
  } catch (e: unknown) {
    usersError.value = e instanceof ApiError ? e.message : 'Failed to load users.'
  } finally {
    usersLoading.value = false
  }
}
watch(activeTab, (tab) => {
  if (tab === 'users' && users.value.length === 0) void loadUsers()
})
watch([userSearch, userRoleFilter], () => {
  if (activeTab.value === 'users') void loadUsers()
})
onMounted(() => {
  if (activeTab.value === 'users') void loadUsers()
})

async function changeUserRole(user: AdminUserRow, newRoleId: string): Promise<void> {
  if (user.roleId === newRoleId) return
  savingUserId.value = user.id
  try {
    const updated = await setUserRole(user.id, newRoleId)
    const idx = users.value.findIndex((u) => u.id === user.id)
    if (idx >= 0) users.value[idx] = updated
    // Refresh role counts on the roles list.
    void loadRoles()
  } catch (e: unknown) {
    usersError.value = e instanceof ApiError ? e.message : 'Failed to change role.'
  } finally {
    savingUserId.value = null
  }
}

// User detail drawer (shows overrides — the escape hatch)
const userDrawerId = ref<string | null>(null)
const userDrawer = ref<AdminUserRow | null>(null)
const drawerLoading = ref(false)

async function openUserDrawer(id: string): Promise<void> {
  userDrawerId.value = id
  drawerLoading.value = true
  try {
    userDrawer.value = await fetchUser(id)
  } catch (e: unknown) {
    usersError.value = e instanceof ApiError ? e.message : 'Failed to load user.'
    userDrawerId.value = null
  } finally {
    drawerLoading.value = false
  }
}
function closeUserDrawer(): void {
  userDrawerId.value = null
  userDrawer.value = null
}

async function grantOverride(permissionId: string, effect: 'grant' | 'deny'): Promise<void> {
  if (!userDrawer.value) return
  try {
    await addUserOverride(userDrawer.value.id, permissionId, effect)
    await openUserDrawer(userDrawer.value.id)
  } catch (e: unknown) {
    usersError.value = e instanceof ApiError ? e.message : 'Failed to grant override.'
  }
}
async function removeOverride(overrideId: string): Promise<void> {
  if (!userDrawer.value) return
  try {
    await removeUserOverride(userDrawer.value.id, overrideId)
    await openUserDrawer(userDrawer.value.id)
  } catch (e: unknown) {
    usersError.value = e instanceof ApiError ? e.message : 'Failed to remove override.'
  }
}

// ─────────────────────────────────────────────────────────────────
// Roles tab (from the old AdminRoles.vue)
// ─────────────────────────────────────────────────────────────────
const editing = ref<{
  id: string | null
  nameTh: string
  nameEn: string
  description: string
  permissionIds: Set<string>
  isSystem: boolean
  isWildcard: boolean
} | null>(null)
const roleSaving = ref(false)
const roleError = ref<string | null>(null)

async function startEditRole(role: AdminRoleRow): Promise<void> {
  const full = await fetchRole(role.id)
  editing.value = {
    id: full.id,
    nameTh: full.nameTh,
    nameEn: full.nameEn,
    description: full.description ?? '',
    permissionIds: new Set(full.permissionIds ?? []),
    isSystem: full.isSystem,
    isWildcard: full.isWildcard,
  }
}
function startCreateRole(): void {
  editing.value = {
    id: null,
    nameTh: '',
    nameEn: '',
    description: '',
    permissionIds: new Set(),
    isSystem: false,
    isWildcard: false,
  }
}
function cancelRoleEdit(): void {
  editing.value = null
  roleError.value = null
}
function togglePerm(permId: string): void {
  if (!editing.value || editing.value.isWildcard) return
  if (editing.value.permissionIds.has(permId)) editing.value.permissionIds.delete(permId)
  else editing.value.permissionIds.add(permId)
}
function toggleGroup(group: AdminPermissionGroup, allSelected: boolean): void {
  if (!editing.value || editing.value.isWildcard) return
  for (const p of group.permissions) {
    if (allSelected) editing.value.permissionIds.delete(p.id)
    else editing.value.permissionIds.add(p.id)
  }
}
function groupAllSelected(group: AdminPermissionGroup): boolean {
  if (!editing.value) return false
  return group.permissions.every((p) => editing.value!.permissionIds.has(p.id))
}
function groupSomeSelected(group: AdminPermissionGroup): boolean {
  if (!editing.value) return false
  return group.permissions.some((p) => editing.value!.permissionIds.has(p.id))
}

async function saveRole(): Promise<void> {
  if (!editing.value) return
  roleSaving.value = true
  roleError.value = null
  try {
    const ids = Array.from(editing.value.permissionIds)
    if (editing.value.id === null) {
      const created = await createRole({
        nameTh: editing.value.nameTh,
        nameEn: editing.value.nameEn,
        description: editing.value.description || null,
        permissionIds: ids,
      })
      editing.value.id = created.id
    } else {
      await updateRole(editing.value.id, {
        nameTh: editing.value.nameTh,
        nameEn: editing.value.nameEn,
        description: editing.value.description || null,
      })
      if (!editing.value.isWildcard) {
        await setRolePermissions(editing.value.id, ids)
      }
    }
    await loadRoles()
    editing.value = null
  } catch (e: unknown) {
    roleError.value = e instanceof ApiError ? e.message : 'Save failed.'
  } finally {
    roleSaving.value = false
  }
}

async function deleteRoleRow(role: AdminRoleRow): Promise<void> {
  if (!window.confirm(t('accessControl.confirmDeleteRole', { name: labelFor(role) }))) return
  try {
    await deleteRole(role.id)
    await loadRoles()
  } catch (e: unknown) {
    roleError.value = e instanceof ApiError ? e.message : 'Delete failed.'
  }
}

// Map roleId → { nameTh, nameEn } for the users-table dropdown
const roleOptions = computed(() =>
  roles.value.map((r) => ({
    id: r.id,
    label: labelFor(r),
    key: r.key,
  })),
)
</script>

<template>
  <div class="p-6 max-w-7xl mx-auto">
    <div class="mb-6">
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('accessControl.title') }}</h1>
      <p class="text-slate-500 text-sm mt-1">{{ t('accessControl.subtitle') }}</p>
    </div>

    <div v-if="globalError" class="mb-4 px-3 py-2 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm">
      {{ globalError }}
    </div>

    <!-- Tabs -->
    <div class="border-b border-slate-200 mb-6">
      <nav class="flex gap-1">
        <button v-for="tk in validTabs" :key="tk" @click="activeTab = tk"
          :class="[
            'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
            activeTab === tk
              ? 'border-brand-500 text-brand-600'
              : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300',
          ]">
          <i :class="{
            'pi pi-users mr-1': tk === 'users',
            'pi pi-shield mr-1': tk === 'roles',
            'pi pi-key mr-1': tk === 'mfa',
          }" />
          {{ t(`accessControl.tabs.${tk}`) }}
        </button>
      </nav>
    </div>

    <!-- ─── Users tab ─────────────────────────────────────────── -->
    <section v-if="activeTab === 'users'" class="space-y-4">
      <div class="flex items-center gap-3">
        <input v-model="userSearch" :placeholder="t('accessControl.users.searchPlaceholder')"
          class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
        <select v-model="userRoleFilter"
          class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500">
          <option value="all">{{ t('accessControl.users.allRoles') }}</option>
          <option v-for="r in roleOptions" :key="r.id" :value="r.id">{{ r.label }}</option>
        </select>
      </div>

      <div v-if="usersError" class="px-3 py-2 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        {{ usersError }}
      </div>

      <div v-if="usersLoading" class="text-slate-500 text-sm">
        <i class="pi pi-spin pi-spinner mr-1" /> {{ t('accessControl.loading') }}
      </div>
      <div v-else-if="users.length === 0" class="text-slate-500 text-sm py-8 text-center bg-slate-50 rounded-lg">
        {{ t('accessControl.users.empty') }}
      </div>
      <div v-else class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-700">
            <tr>
              <th class="text-left px-4 py-3">{{ t('accessControl.users.col.name') }}</th>
              <th class="text-left px-4 py-3">{{ t('accessControl.users.col.email') }}</th>
              <th class="text-left px-4 py-3">{{ t('accessControl.users.col.role') }}</th>
              <th class="text-left px-4 py-3">{{ t('accessControl.users.col.active') }}</th>
              <th class="text-right px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in users" :key="u.id" class="border-t border-slate-100 hover:bg-slate-50">
              <td class="px-4 py-3 font-medium text-slate-900">{{ u.name }}</td>
              <td class="px-4 py-3 text-slate-500">{{ u.email }}</td>
              <td class="px-4 py-3">
                <select :value="u.roleId ?? ''"
                  :disabled="savingUserId === u.id"
                  @change="(e) => changeUserRole(u, (e.target as HTMLSelectElement).value)"
                  class="px-2 py-1 border border-slate-300 rounded text-sm focus:outline-none focus:border-brand-500">
                  <option v-for="r in roleOptions" :key="r.id" :value="r.id">{{ r.label }}</option>
                </select>
                <i v-if="savingUserId === u.id" class="pi pi-spin pi-spinner text-brand-500 ml-2" />
              </td>
              <td class="px-4 py-3">
                <span :class="u.active
                  ? 'inline-block px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-xs'
                  : 'inline-block px-2 py-0.5 rounded bg-slate-200 text-slate-700 text-xs'">
                  {{ u.active ? t('accessControl.users.active') : t('accessControl.users.inactive') }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <button @click="openUserDrawer(u.id)"
                  class="px-3 py-1 rounded border border-slate-300 text-xs hover:bg-slate-100">
                  <i class="pi pi-sliders-h mr-1" /> {{ t('accessControl.users.overrides') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ─── Roles tab ─────────────────────────────────────────── -->
    <section v-else-if="activeTab === 'roles'" class="space-y-4">
      <div v-if="!editing" class="flex items-center justify-between">
        <p class="text-sm text-slate-500">{{ t('accessControl.roles.hint') }}</p>
        <button @click="startCreateRole"
          class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">
          <i class="pi pi-plus mr-1" /> {{ t('accessControl.roles.newRole') }}
        </button>
      </div>

      <div v-if="roleError" class="px-3 py-2 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        {{ roleError }}
      </div>

      <div v-if="rolesLoading" class="text-slate-500 text-sm">
        <i class="pi pi-spin pi-spinner mr-1" /> {{ t('accessControl.loading') }}
      </div>

      <!-- Roles list -->
      <div v-else-if="!editing" class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-700">
            <tr>
              <th class="text-left px-4 py-3">{{ t('accessControl.roles.col.name') }}</th>
              <th class="text-left px-4 py-3">{{ t('accessControl.roles.col.key') }}</th>
              <th class="text-left px-4 py-3">{{ t('accessControl.roles.col.users') }}</th>
              <th class="text-left px-4 py-3">{{ t('accessControl.roles.col.flags') }}</th>
              <th class="text-right px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in roles" :key="r.id" class="border-t border-slate-100 hover:bg-slate-50">
              <td class="px-4 py-3 font-medium text-slate-900">{{ labelFor(r) }}
                <div v-if="r.description" class="text-xs text-slate-500 font-normal">{{ r.description }}</div>
              </td>
              <td class="px-4 py-3 text-slate-500 font-mono text-xs">{{ r.key }}</td>
              <td class="px-4 py-3 text-slate-700">{{ r.userCount }}</td>
              <td class="px-4 py-3">
                <span v-if="r.isWildcard" class="inline-block px-2 py-0.5 rounded bg-amber-100 text-amber-800 text-xs">{{ t('accessControl.roles.wildcard') }}</span>
                <span v-if="r.isSystem" class="inline-block px-2 py-0.5 rounded bg-slate-200 text-slate-700 text-xs ml-1">{{ t('accessControl.roles.system') }}</span>
              </td>
              <td class="px-4 py-3 text-right">
                <button @click="startEditRole(r)"
                  class="px-3 py-1 rounded border border-slate-300 text-xs hover:bg-slate-100 mr-1">
                  <i class="pi pi-pencil mr-1" /> {{ t('accessControl.edit') }}
                </button>
                <button v-if="!r.isSystem && r.userCount === 0" @click="deleteRoleRow(r)"
                  class="px-3 py-1 rounded border border-rose-300 text-xs text-rose-600 hover:bg-rose-50">
                  <i class="pi pi-trash mr-1" /> {{ t('accessControl.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Role edit form -->
      <div v-else class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="flex items-start justify-between mb-4">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">
              {{ editing.id === null ? t('accessControl.roles.newRole') : t('accessControl.roles.editRole') }}
            </h2>
            <p v-if="editing.isWildcard" class="text-xs text-amber-700 mt-1">
              <i class="pi pi-info-circle mr-1" /> {{ t('accessControl.roles.wildcardNote') }}
            </p>
          </div>
          <button @click="cancelRoleEdit" class="text-slate-500 hover:text-slate-700 text-sm">
            <i class="pi pi-times mr-1" /> {{ t('accessControl.cancel') }}
          </button>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('accessControl.roles.field.nameTh') }} *</label>
            <input v-model="editing.nameTh"
              class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('accessControl.roles.field.nameEn') }} *</label>
            <input v-model="editing.nameEn"
              class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
          </div>
        </div>
        <div class="mb-6">
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('accessControl.roles.field.description') }}</label>
          <textarea v-model="editing.description" rows="2"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500"></textarea>
        </div>

        <h3 class="text-sm font-semibold text-slate-900 mb-3">{{ t('accessControl.roles.permissions') }}</h3>
        <div v-if="permsLoading" class="text-slate-500 text-sm mb-4">
          <i class="pi pi-spin pi-spinner mr-1" /> {{ t('accessControl.loading') }}
        </div>
        <div v-else class="space-y-4">
          <div v-for="group in permissionGroups" :key="group.module" class="border border-slate-200 rounded-lg overflow-hidden">
            <div class="bg-slate-50 px-4 py-2 flex items-center justify-between">
              <label class="flex items-center gap-2 text-sm font-medium text-slate-800 cursor-pointer">
                <input type="checkbox"
                  :checked="groupAllSelected(group)"
                  :disabled="editing.isWildcard"
                  @change="toggleGroup(group, groupAllSelected(group))" />
                <span class="uppercase text-xs">{{ group.module }}</span>
                <span v-if="groupSomeSelected(group) && !groupAllSelected(group)" class="text-xs text-brand-600 font-normal">
                  ({{ group.permissions.filter((p) => editing!.permissionIds.has(p.id)).length }}/{{ group.permissions.length }})
                </span>
              </label>
            </div>
            <div class="p-3 grid grid-cols-2 gap-2">
              <label v-for="p in group.permissions" :key="p.id"
                class="flex items-start gap-2 px-2 py-1.5 rounded hover:bg-slate-50 cursor-pointer">
                <input type="checkbox"
                  :checked="editing.permissionIds.has(p.id)"
                  :disabled="editing.isWildcard"
                  @change="togglePerm(p.id)"
                  class="mt-0.5" />
                <div class="flex-1 min-w-0">
                  <div class="text-sm text-slate-800">{{ locale === 'th' ? p.nameTh : p.nameEn }}</div>
                  <div class="text-xs text-slate-400 font-mono truncate">{{ p.key }}</div>
                  <div v-if="p.description" class="text-xs text-slate-500 mt-0.5">{{ p.description }}</div>
                </div>
              </label>
            </div>
          </div>
        </div>

        <div class="mt-6 flex items-center justify-end gap-2">
          <button @click="cancelRoleEdit" class="px-4 py-2 rounded-lg border border-slate-300 text-sm hover:bg-slate-50">
            {{ t('accessControl.cancel') }}
          </button>
          <button @click="saveRole" :disabled="roleSaving || !editing.nameTh || !editing.nameEn"
            class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 disabled:opacity-50">
            <i v-if="roleSaving" class="pi pi-spin pi-spinner mr-1" />
            {{ t('accessControl.save') }}
          </button>
        </div>
      </div>
    </section>

    <!-- ─── MFA tab (existing flow linked here) ───────────────── -->
    <section v-else-if="activeTab === 'mfa'" class="space-y-4">
      <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-2">{{ t('accessControl.mfa.title') }}</h2>
        <p class="text-sm text-slate-600 mb-4">{{ t('accessControl.mfa.body') }}</p>
        <RouterLink to="/auth/mfa" class="inline-block px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">
          <i class="pi pi-key mr-1" /> {{ t('accessControl.mfa.setup') }}
        </RouterLink>
      </div>
    </section>

    <!-- ─── User overrides drawer ─────────────────────────────── -->
    <div v-if="userDrawerId !== null" class="fixed inset-0 bg-black/40 z-40 flex justify-end" @click.self="closeUserDrawer">
      <div class="bg-white w-full max-w-lg h-full overflow-y-auto p-6 shadow-xl">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-semibold text-slate-900">{{ t('accessControl.users.overrides') }}</h2>
          <button @click="closeUserDrawer" class="text-slate-500 hover:text-slate-800">
            <i class="pi pi-times" />
          </button>
        </div>
        <div v-if="drawerLoading" class="text-slate-500 text-sm">
          <i class="pi pi-spin pi-spinner mr-1" /> {{ t('accessControl.loading') }}
        </div>
        <div v-else-if="userDrawer">
          <div class="mb-4 pb-4 border-b border-slate-100">
            <div class="text-sm font-medium text-slate-900">{{ userDrawer.name }}</div>
            <div class="text-xs text-slate-500">{{ userDrawer.email }}</div>
            <div class="text-xs text-slate-600 mt-1">
              {{ t('accessControl.users.col.role') }}:
              <span class="font-medium">{{ userDrawer.roleLabel ? (locale === 'th' ? userDrawer.roleLabel.th : userDrawer.roleLabel.en) : '—' }}</span>
            </div>
          </div>

          <p class="text-xs text-slate-500 mb-3">{{ t('accessControl.users.overridesHint') }}</p>

          <div v-if="(userDrawer.overrides ?? []).length === 0" class="text-sm text-slate-500 mb-6 italic">
            {{ t('accessControl.users.noOverrides') }}
          </div>
          <div v-else class="space-y-2 mb-6">
            <div v-for="o in userDrawer.overrides ?? []" :key="o.id"
              class="flex items-center justify-between px-3 py-2 rounded border border-slate-200">
              <div>
                <span :class="o.effect === 'grant'
                  ? 'inline-block px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-xs mr-2'
                  : 'inline-block px-2 py-0.5 rounded bg-rose-100 text-rose-800 text-xs mr-2'">
                  {{ o.effect === 'grant' ? t('accessControl.users.grant') : t('accessControl.users.deny') }}
                </span>
                <span class="text-sm font-mono text-slate-700">{{ o.permissionKey }}</span>
              </div>
              <button @click="removeOverride(o.id)"
                class="text-rose-600 hover:text-rose-800 text-xs">
                <i class="pi pi-times" />
              </button>
            </div>
          </div>

          <div class="pt-4 border-t border-slate-100">
            <div class="text-xs font-semibold text-slate-700 uppercase mb-2">{{ t('accessControl.users.addOverride') }}</div>
            <div v-for="group in permissionGroups" :key="group.module" class="mb-3">
              <div class="text-xs font-medium text-slate-500 uppercase mb-1">{{ group.module }}</div>
              <div class="space-y-1">
                <div v-for="p in group.permissions" :key="p.id"
                  class="flex items-center justify-between px-2 py-1 text-sm">
                  <div class="min-w-0 flex-1">
                    <div class="text-slate-800">{{ locale === 'th' ? p.nameTh : p.nameEn }}</div>
                    <div class="text-xs text-slate-400 font-mono truncate">{{ p.key }}</div>
                  </div>
                  <div class="flex gap-1 ml-2">
                    <button @click="grantOverride(p.id, 'grant')"
                      class="px-2 py-0.5 border border-emerald-300 text-emerald-700 rounded text-xs hover:bg-emerald-50">
                      + {{ t('accessControl.users.grant') }}
                    </button>
                    <button @click="grantOverride(p.id, 'deny')"
                      class="px-2 py-0.5 border border-rose-300 text-rose-700 rounded text-xs hover:bg-rose-50">
                      − {{ t('accessControl.users.deny') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
