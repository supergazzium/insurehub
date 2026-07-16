// Reports store — caches the dashboard KPIs so the topbar/dashboard/other
// widgets can share a single fetch without duplicating requests.
// Individual report pages (expiring-soon, commission-ledger, etc.) call
// their API clients directly since they need filter-specific fetches.

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchDashboardKpis, type DashboardKpis } from '../api/reports'

export const useReportsStore = defineStore('reports', () => {
  const kpis = ref<DashboardKpis | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const loadedAt = ref<number | null>(null)

  async function loadKpis(force = false): Promise<void> {
    // 30-second cache — dashboard rarely changes faster than that.
    if (!force && kpis.value && loadedAt.value && Date.now() - loadedAt.value < 30_000) {
      return
    }
    loading.value = true
    error.value = null
    try {
      const res = await fetchDashboardKpis()
      kpis.value = res.data
      loadedAt.value = Date.now()
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to load KPIs'
    } finally {
      loading.value = false
    }
  }

  function reset(): void {
    kpis.value = null
    loadedAt.value = null
    error.value = null
  }

  return { kpis, loading, error, loadKpis, reset }
})
