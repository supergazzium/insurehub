// Thin API wrapper for /admin/roles + /admin/permissions + /admin/users.
// Backed by the AdminRoleController and AdminUserController on the server.

import { api } from './client'

export interface RoleLabel {
  th: string
  en: string
}

export interface AdminRoleRow {
  id: string
  key: string
  nameTh: string
  nameEn: string
  description: string | null
  isSystem: boolean
  isWildcard: boolean
  userCount: number
  permissionIds?: string[]
}

export interface AdminPermission {
  id: string
  key: string
  nameTh: string
  nameEn: string
  description: string | null
}

export interface AdminPermissionGroup {
  module: string
  permissions: AdminPermission[]
}

export interface AdminUserRow {
  id: string
  name: string
  email: string
  active: boolean
  roleId: string | null
  roleKey: string | null
  roleLabel: RoleLabel | null
  overrides?: Array<{
    id: string
    permissionId: string
    permissionKey: string
    effect: 'grant' | 'deny'
  }>
}

export async function fetchRoles(): Promise<AdminRoleRow[]> {
  const res = await api.get<{ data: AdminRoleRow[] }>('admin/roles')
  return res.data
}

export async function fetchRole(id: string): Promise<AdminRoleRow> {
  const res = await api.get<{ data: AdminRoleRow }>(`admin/roles/${id}`)
  return res.data
}

export async function createRole(body: {
  nameTh: string
  nameEn: string
  description?: string | null
  key?: string
  permissionIds?: string[]
}): Promise<AdminRoleRow> {
  const res = await api.post<{ data: AdminRoleRow }>('admin/roles', body)
  return res.data
}

export async function updateRole(
  id: string,
  body: { nameTh?: string; nameEn?: string; description?: string | null },
): Promise<AdminRoleRow> {
  const res = await api.patch<{ data: AdminRoleRow }>(`admin/roles/${id}`, body)
  return res.data
}

export async function setRolePermissions(id: string, permissionIds: string[]): Promise<AdminRoleRow> {
  const res = await api.put<{ data: AdminRoleRow }>(`admin/roles/${id}/permissions`, { permissionIds: permissionIds.map((v) => Number(v)) })
  return res.data
}

export async function deleteRole(id: string): Promise<void> {
  await api.delete(`admin/roles/${id}`)
}

export async function fetchPermissions(): Promise<AdminPermissionGroup[]> {
  const res = await api.get<{ data: AdminPermissionGroup[] }>('admin/permissions')
  return res.data
}

export async function fetchUsers(params: { search?: string; roleId?: string } = {}): Promise<AdminUserRow[]> {
  const q = new URLSearchParams()
  if (params.search) q.set('search', params.search)
  if (params.roleId) q.set('roleId', params.roleId)
  const qs = q.toString()
  const res = await api.get<{ data: AdminUserRow[] }>(`admin/users${qs ? '?' + qs : ''}`)
  return res.data
}

export async function fetchUser(id: string): Promise<AdminUserRow> {
  const res = await api.get<{ data: AdminUserRow }>(`admin/users/${id}`)
  return res.data
}

export async function setUserRole(userId: string, roleId: string): Promise<AdminUserRow> {
  const res = await api.patch<{ data: AdminUserRow }>(`admin/users/${userId}/role`, { roleId: Number(roleId) })
  return res.data
}

export async function addUserOverride(
  userId: string,
  permissionId: string,
  effect: 'grant' | 'deny',
): Promise<{ id: string; permissionId: string; effect: 'grant' | 'deny' }> {
  const res = await api.post<{ data: { id: string; permissionId: string; effect: 'grant' | 'deny' } }>(
    `admin/users/${userId}/overrides`,
    { permissionId: Number(permissionId), effect },
  )
  return res.data
}

export async function removeUserOverride(userId: string, overrideId: string): Promise<void> {
  await api.delete(`admin/users/${userId}/overrides/${overrideId}`)
}
