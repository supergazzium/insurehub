// Carriers store — server-paginated list. Used by CarrierManagementV2.vue and
// by dropdowns that need the full 45-row set (small enough to fully load).

import { defineStore } from 'pinia'
import { ref } from 'vue'
import {
  fetchCarrierList,
  type CarrierListRow,
  type CarrierListFilters,
} from '../api/carriers'

export type { CarrierListRow, CarrierListFilters }

export const useCarrierStore = defineStore('carriers', () => {
  const list = ref<CarrierListRow[]>([])
  const listMeta = ref<{ currentPage: number; lastPage: number; perPage: number; total: number } | null>(null)
  const listFilters = ref<CarrierListFilters>({ page: 1, perPage: 25 })
  const listLoading = ref(false)
  const listError = ref<string | null>(null)

  async function loadPage(filters: CarrierListFilters = {}): Promise<void> {
    listFilters.value = { ...listFilters.value, ...filters }
    listLoading.value = true
    listError.value = null
    try {
      const res = await fetchCarrierList(listFilters.value)
      list.value = res.data
      const m = res.meta
      listMeta.value = m
        ? { currentPage: m.current_page, lastPage: m.last_page, perPage: m.per_page, total: m.total }
        : null
    } catch (err) {
      listError.value = err instanceof Error ? err.message : 'Failed to load carriers.'
      throw err
    } finally {
      listLoading.value = false
    }
  }

  return {
    list, listMeta, listFilters, listLoading, listError, loadPage,
  }
})
