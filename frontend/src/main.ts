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

// Re-hydrate the auth session from the persisted token BEFORE app.use(router).
// Vue Router fires its initial navigation the moment it's installed as a
// plugin — not when the app mounts — so the router.beforeEach guard runs
// before restore() would otherwise finish. If the guard checks
// auth.isAuthenticated while restore() is still awaiting /auth/me, it sees a
// null user and redirects to /login, and no further navigation retriggers.
// Doing the await here means the guard reads a fully-populated store on the
// first tick. Errors are swallowed — an invalid token just leaves the user
// logged out, which is the correct fallback.
await useAuthStore(pinia)
  .restore()
  .catch(() => undefined)

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

app.mount('#app')
