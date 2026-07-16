// Products store — server-paginated list. Used by ProductManagementV2.vue.

import { defineStore } from 'pinia'
import { ref } from 'vue'
import {
  fetchProductList,
  type ProductListRow,
  type ProductListFilters,
} from '../api/products'

export type { ProductListRow, ProductListFilters }

export const useProductStore = defineStore('products', () => {
  const list = ref<ProductListRow[]>([])
  const listMeta = ref<{ currentPage: number; lastPage: number; perPage: number; total: number } | null>(null)
  const listFilters = ref<ProductListFilters>({ page: 1, perPage: 25 })
  const listLoading = ref(false)
  const listError = ref<string | null>(null)

  async function loadPage(filters: ProductListFilters = {}): Promise<void> {
    listFilters.value = { ...listFilters.value, ...filters }
    listLoading.value = true
    listError.value = null
    try {
      const res = await fetchProductList(listFilters.value)
      list.value = res.data
      const m = res.meta
      listMeta.value = m
        ? { currentPage: m.current_page, lastPage: m.last_page, perPage: m.per_page, total: m.total }
        : null
    } catch (err) {
      listError.value = err instanceof Error ? err.message : 'Failed to load products.'
      throw err
    } finally {
      listLoading.value = false
    }
  }

  return {
    list, listMeta, listFilters, listLoading, listError, loadPage,
  }
})
