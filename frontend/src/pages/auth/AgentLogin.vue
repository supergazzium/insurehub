<script setup lang="ts">
// Agent login (/agent/login). Rejects internal users and points them at the
// staff login; offers agent self-registration. Shares logic via LoginForm (#2).
import LoginForm from './LoginForm.vue'
</script>

<template>
  <LoginForm variant="agent" />
</template>
