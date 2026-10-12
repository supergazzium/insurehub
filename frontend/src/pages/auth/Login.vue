<script setup lang="ts">
// Staff / internal-user login (/login). Rejects agents and points them at the
// agent login. Shares all logic with the agent page via LoginForm (#2).
import LoginForm from './LoginForm.vue'
</script>

<template>
  <LoginForm variant="staff" />
</template>
