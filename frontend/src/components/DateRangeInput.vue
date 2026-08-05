<script setup lang="ts">
// Paired [from] [to] date picker with automatic "end can't be before start"
// enforcement. Left field's max = to. Right field's min = from. If the
// user changes `from` to a date after `to`, we clamp `to` up to match.
//
// Model: { from: string; to: string } — both ISO YYYY-MM-DD or ''.
import { computed } from 'vue'
import DateInput from './DateInput.vue'

const props = defineProps<{
  from: string | null | undefined
  to: string | null | undefined
  min?: string | null
  max?: string | null
  disabled?: boolean
  separator?: string
}>()

const emit = defineEmits<{
  (e: 'update:from', v: string): void
  (e: 'update:to', v: string): void
}>()

const fromModel = computed<string>({
  get: () => props.from ?? '',
  set: (v) => {
    emit('update:from', v)
    // If the new start is past the current end, drag end forward.
    if (v && props.to && v > props.to) emit('update:to', v)
  },
})
const toModel = computed<string>({
  get: () => props.to ?? '',
  set: (v) => emit('update:to', v),
})

// The right picker cannot go earlier than `from`. The left picker cannot
// go later than `to` (unless `to` is unset).
const rightMin = computed(() => (props.from || props.min) ?? undefined)
const leftMax = computed(() => (props.to || props.max) ?? undefined)
</script>

<template>
  <div class="flex items-center gap-2">
    <div class="flex-1">
      <DateInput v-model="fromModel" :min="min ?? undefined" :max="leftMax" :disabled="disabled" placeholder="dd/mm/yyyy" />
    </div>
    <span class="text-slate-400 text-xs shrink-0">{{ separator ?? 'ถึง' }}</span>
    <div class="flex-1">
      <DateInput v-model="toModel" :min="rightMin" :max="max ?? undefined" :disabled="disabled" placeholder="dd/mm/yyyy" />
    </div>
  </div>
</template>
