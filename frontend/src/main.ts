import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
import 'primeicons/primeicons.css'

import './style.css'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

const pinia = createPinia()
app.use(pinia)
app.use(router)
app.use(i18n)
app.use(PrimeVue, {
  theme: {
    preset: Aura,
    options: {
      darkModeSelector: '.dark-mode',
    },
  },
})

// Re-hydrate the auth session from the persisted token before mounting,
// so route guards and stores can read `user` synchronously on first paint.
// Errors are swallowed — the login flow handles them.
await useAuthStore(pinia)
  .restore()
  .catch(() => undefined)

app.mount('#app')
