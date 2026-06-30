// Auth store backed by the Laravel API.
//
// Persists the bearer token to localStorage (via `setToken`), so a page
// refresh keeps the session. On boot, call `restore()` from `main.ts` to
// re-hydrate the `user` ref from `/auth/me`.

import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api, ApiError, getToken, setToken } from '../api/client'

export interface AuthUser {
  id: string
  name: string
  email: string
  role: string
  locale: string
  tenantId: string | null
  agentId: string | null
}

interface LoginResponse {
  token: string
  user: AuthUser
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const isAuthenticated = computed(() => user.value !== null)

  async function login(email: string, password: string, deviceName?: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await api.post<LoginResponse>('auth/login', {
        email,
        password,
        deviceName: deviceName ?? 'web',
      })
      setToken(response.token)
      user.value = response.user
    } catch (err) {
      if (err instanceof ApiError) {
        error.value = err.body?.message ?? 'Login failed.'
      } else {
        error.value = (err as Error).message
      }
      throw err
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    try {
      if (getToken() !== null) {
        await api.post('auth/logout')
      }
    } catch {
      // Best-effort — even if the server-side call fails we still clear locally.
    } finally {
      setToken(null)
      user.value = null
    }
  }

  /**
   * Re-hydrate `user` from the server using the persisted token, if any.
   * Safe to call on every app boot — it no-ops when no token is stored,
   * and clears the token if the server says it's invalid.
   */
  async function restore(): Promise<void> {
    if (getToken() === null) {
      return
    }
    loading.value = true
    try {
      const response = await api.get<{ user: AuthUser }>('auth/me')
      user.value = response.user
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        // Token is no longer valid.
        user.value = null
      } else {
        throw err
      }
    } finally {
      loading.value = false
    }
  }

  return { user, loading, error, isAuthenticated, login, logout, restore }
})
