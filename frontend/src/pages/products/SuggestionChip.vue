<script setup lang="ts">
// Small "Suggested: X [✓ Use] [✕ Ignore]" chip rendered below a form field
// when the source product has a value the current field is still at its
// default for. Emits `use` and `ignore` — the parent applies the value.
defineProps<{
  label: string
  value: string
}>()
defineEmits<{
  (e: 'use'): void
  (e: 'ignore'): void
}>()
</script>

<template>
  <div class="mt-1 flex items-center gap-1.5 text-[11px] text-amber-800">
    <i class="pi pi-lightbulb text-amber-500" />
    <span>{{ label }}:</span>
    <span class="font-medium">{{ value }}</span>
    <button type="button" @click="$emit('use')"
      class="ml-1 px-1.5 py-0.5 rounded border border-amber-300 bg-amber-50 hover:bg-amber-100 text-amber-800 font-medium">
      ✓ Use
    </button>
    <button type="button" @click="$emit('ignore')"
      class="px-1.5 py-0.5 rounded text-amber-600 hover:text-amber-800">
      ✕
    </button>
  </div>
</template>
