<script setup lang="ts">
// A titled panel for list/table widgets on a role dashboard (#3).
// When `pending`, it renders a clear "data not wired yet" placeholder so the
// layout is complete but nobody mistakes an empty panel for real zero data.
defineProps<{
  title: string
  subtitle?: string
  /** Optional link target (RouterLink `to`) for a "ดูทั้งหมด" action. */
  to?: string | { name: string }
  toLabel?: string
  pending?: boolean
}>()
</script>

<template>
  <section class="card p-5">
    <div class="flex items-start justify-between gap-3 mb-3">
      <div>
        <h3 class="font-semibold text-slate-900">{{ title }}</h3>
        <p v-if="subtitle" class="text-xs text-slate-400 mt-0.5">{{ subtitle }}</p>
      </div>
      <RouterLink v-if="to" :to="to" class="shrink-0 text-xs text-brand-600 hover:text-brand-700">
        {{ toLabel ?? 'ดูทั้งหมด' }} <i class="pi pi-arrow-right text-[9px]" />
      </RouterLink>
    </div>
    <div v-if="pending" class="rounded-lg border border-dashed border-slate-200 bg-slate-50/50 px-4 py-6 text-center">
      <i class="pi pi-clock text-slate-300 text-lg" />
      <p class="mt-1 text-xs text-slate-400">ยังไม่เชื่อมข้อมูล — รอ endpoint ฝั่ง backend</p>
    </div>
    <slot v-else />
  </section>
</template>
