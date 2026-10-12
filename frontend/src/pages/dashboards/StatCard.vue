<script setup lang="ts">
// A single KPI stat tile used across every role dashboard (#3).
import { TONE_CLASSES, type Tone } from './widgets'

defineProps<{
  label: string
  value: string | number
  /** Small caption under / beside the value (e.g. "เทียบเดือนก่อน +12%"). */
  hint?: string
  icon?: string
  tone?: Tone
  /** Show a dashed "ยังไม่เชื่อมข้อมูล" treatment when the metric has no backing data yet. */
  pending?: boolean
  busy?: boolean
}>()
</script>

<template>
  <div :class="['card p-5', pending ? 'border-dashed' : '']" :aria-busy="busy">
    <div class="flex items-center justify-between">
      <div v-if="icon" :class="['w-10 h-10 rounded-lg flex items-center justify-center', TONE_CLASSES[tone ?? 'slate']]">
        <i :class="icon" />
      </div>
      <span v-if="hint" class="text-xs text-slate-500 text-right">{{ hint }}</span>
    </div>
    <div class="mt-4">
      <div class="text-2xl font-semibold" :class="pending ? 'text-slate-300' : 'text-slate-900'">
        {{ pending ? '—' : value }}
      </div>
      <div class="text-sm text-slate-500 mt-0.5">{{ label }}</div>
      <div v-if="pending" class="mt-1 text-[10px] text-amber-600">ยังไม่เชื่อมข้อมูล</div>
    </div>
  </div>
</template>
