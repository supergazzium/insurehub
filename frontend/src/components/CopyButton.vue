<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ value: string | number | null | undefined }>()
const { t } = useI18n()
const copied = ref(false)

function fallbackCopy(text: string): void {
  const el = document.createElement('textarea')
  el.value = text
  el.setAttribute('readonly', '')
  el.style.position = 'fixed'
  el.style.opacity = '0'
  document.body.appendChild(el)
  el.select()
  document.execCommand('copy')
  document.body.removeChild(el)
}

async function copy(): Promise<void> {
  const text = String(props.value ?? '').trim()
  if (!text) return
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    fallbackCopy(text)
  }
  copied.value = true
  window.setTimeout(() => (copied.value = false), 1200)
}
</script>

<template>
  <button v-if="String(value ?? '').trim() !== ''" type="button" data-testid="copy-button"
    :title="copied ? t('customers.ui.copied') : t('customers.ui.copy')"
    class="shrink-0 w-6 h-6 inline-flex items-center justify-center rounded text-slate-400 hover:text-brand-600 hover:bg-brand-50"
    @click.stop="copy">
    <i :class="['pi text-xs', copied ? 'pi-check text-emerald-600' : 'pi-copy']" />
  </button>
</template>
