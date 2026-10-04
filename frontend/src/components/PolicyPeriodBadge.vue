<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  label: string | null | undefined
  size?: 'sm' | 'md'
}>(), { size: 'sm' })

const { t } = useI18n()

const parts = computed(() => {
  const m = /^(\d+)#(?:\s+\$(\d+))?(?:\s+(\d+)\/(\d+))?$/.exec((props.label ?? '').trim())
  if (!m) return null
  return {
    year: Number(m[1]),
    endorsement: m[2] ? Number(m[2]) : null,
    no: m[3] ? Number(m[3]) : null,
    total: m[4] ? Number(m[4]) : null,
  }
})

const title = computed(() => {
  const p = parts.value
  if (!p) return ''
  const bits = [t('periodBadge.year', { n: p.year })]
  if (p.endorsement) bits.push(t('periodBadge.endorsement', { n: p.endorsement }))
  if (p.no && p.total) bits.push(t('periodBadge.installment', { no: p.no, total: p.total }))
  return bits.join(' · ')
})
</script>

<template>
  <span
    v-if="label"
    :title="title"
    class="inline-flex items-center gap-1 rounded border border-teal-200 bg-teal-50 font-mono text-teal-800 whitespace-nowrap"
    :class="size === 'md' ? 'px-2 py-0.5 text-xs' : 'px-1.5 py-px text-[11px]'"
    data-testid="policy-period-badge"
  >
    <template v-if="parts">
      <span class="font-semibold">{{ parts.year }}#</span>
      <span v-if="parts.endorsement" class="text-amber-700">${{ parts.endorsement }}</span>
      <span v-if="parts.no && parts.total">{{ parts.no }}/{{ parts.total }}</span>
    </template>
    <template v-else>{{ label }}</template>
  </span>
</template>
