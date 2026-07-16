<script setup lang="ts">
// Short recruit URL: /r/{token} → /register-agent?ref={token}
// Doing the redirect client-side (rather than a 302 from the backend) means
// the referred user hits the SPA once and the register page's onMounted
// takes it from there — including the click-counter tick against the backend.
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

onMounted(() => {
  const token = typeof route.params.token === 'string' ? route.params.token : ''
  void router.replace({ name: 'register-agent', query: token ? { ref: token } : {} })
})
</script>

<template>
  <div class="max-w-md mx-auto py-16 text-center text-slate-500 text-sm">
    <i class="pi pi-spin pi-spinner mb-2" />
    <div>Redirecting…</div>
  </div>
</template>
