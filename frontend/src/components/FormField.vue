<script setup lang="ts">
// Consistent label + input wrapper for create/edit forms. Renders the label,
// the field slot, and any per-field validation errors below the input.

defineProps<{
  label: string
  required?: boolean
  /** Field name matching the backend response's `errors` key. */
  errorKey?: string
  errors?: Record<string, string[]>
  hint?: string
}>()
</script>

<template>
  <div class="space-y-1">
    <label class="text-xs font-medium text-slate-500 flex items-center gap-1">
      {{ label }}
      <span v-if="required" class="text-rose-500">*</span>
    </label>
    <slot />
    <div v-if="hint" class="text-[10px] text-slate-400">{{ hint }}</div>
    <div v-if="errorKey && errors && errors[errorKey]"
      class="text-[10px] text-rose-600">
      {{ errors[errorKey][0] }}
    </div>
  </div>
</template>
