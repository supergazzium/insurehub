<script setup lang="ts">
// Agent portal shell — visually distinct from the back-office AppLayout.
//
// Design goals:
// - Clean top nav bar, no sidebar (feels more like an app than a dashboard).
// - Larger touch targets, mobile-friendly.
// - Agent code badge visible in the header — agents identify by code.
// - Branded gradient background so it never feels like the same product
//   as the back office.
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { fetchMyAgent, type MyAgent } from '../api/portal'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const auth = useAuthStore()

const me = ref<MyAgent | null>(null)
const userMenuOpen = ref(false)
const mobileMenuOpen = ref(false)

const navItems = [
  { key: 'dashboard', name: 'portal-dashboard', icon: 'pi pi-home' },
  { key: 'profile',   name: 'portal-profile',   icon: 'pi pi-user' },
  { key: 'referral',  name: 'portal-referral',  icon: 'pi pi-share-alt' },
  { key: 'earnings',  name: 'portal-earnings',  icon: 'pi pi-wallet' },
  { key: 'settings',  name: 'portal-settings',  icon: 'pi pi-cog' },
]

const displayName = computed(() => {
  if (me.value) return `${me.value.firstName} ${me.value.lastName}`.trim()
  return auth.user?.name ?? ''
})
const agentCode = computed(() => me.value?.agentCode ?? '')
const initials = computed(() => {
  const first = me.value?.firstName?.[0] ?? auth.user?.name?.[0] ?? ''
  const last = me.value?.lastName?.[0] ?? ''
  return (first + last).toUpperCase() || 'A'
})

async function loadMe(): Promise<void> {
  try {
    const res = await fetchMyAgent()
    me.value = res.data
  } catch {
    /* silent — header falls back to auth.user.name */
  }
}
onMounted(loadMe)

async function doLogout(): Promise<void> {
  userMenuOpen.value = false
  try {
    await auth.logout()
  } finally {
    void router.push({ name: 'public-home' })
  }
}

function closeMenus(e: MouseEvent): void {
  const target = e.target as HTMLElement
  if (!target.closest('.portal-user-menu-anchor')) userMenuOpen.value = false
  if (!target.closest('.portal-mobile-menu-anchor')) mobileMenuOpen.value = false
}

function switchLocale(): void {
  locale.value = locale.value === 'th' ? 'en' : 'th'
  localStorage.setItem('insurehub.locale', locale.value)
}
</script>

<template>
  <div class="min-h-screen bg-gradient-to-br from-brand-50 via-white to-emerald-50" @click="closeMenus">
    <!-- Header -->
    <header class="bg-white/90 backdrop-blur border-b border-slate-200 sticky top-0 z-30 shadow-sm">
      <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="h-16 flex items-center justify-between gap-4">
          <!-- Logo + agent code -->
          <div class="flex items-center gap-3 min-w-0">
            <RouterLink :to="{ name: 'portal-dashboard' }" class="flex items-center gap-2 shrink-0">
              <img src="/brand/logo.png" alt="InsureHub" class="h-9 w-auto object-contain" />
            </RouterLink>
            <div v-if="agentCode" class="hidden sm:block px-2.5 py-1 rounded-md bg-brand-50 border border-brand-200">
              <span class="text-[10px] uppercase text-brand-600 font-semibold tracking-wider">{{ t('portalLayout.agentCode') }}</span>
              <span class="text-sm font-mono font-semibold text-brand-800 ml-1.5">{{ agentCode }}</span>
            </div>
          </div>

          <!-- Desktop nav -->
          <nav class="hidden md:flex items-center gap-1">
            <RouterLink v-for="n in navItems" :key="n.key"
              :to="{ name: n.name }"
              :class="[
                'px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                route.name === n.name
                  ? 'bg-brand-100 text-brand-700'
                  : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50',
              ]">
              <i :class="[n.icon, 'mr-1']" />
              {{ t(`portalLayout.nav.${n.key}`) }}
            </RouterLink>
          </nav>

          <!-- Right cluster -->
          <div class="flex items-center gap-2">
            <button @click.stop="switchLocale"
              class="hidden sm:inline-flex px-2 py-1 text-xs font-medium text-slate-600 hover:text-slate-900 rounded">
              {{ locale === 'th' ? 'EN' : 'ไทย' }}
            </button>

            <!-- Avatar / user menu -->
            <div class="relative portal-user-menu-anchor">
              <button @click.stop="userMenuOpen = !userMenuOpen"
                class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-full hover:bg-slate-100">
                <div class="w-8 h-8 rounded-full bg-brand-600 text-white flex items-center justify-center font-semibold text-xs">
                  {{ initials }}
                </div>
                <span class="hidden sm:inline text-sm font-medium text-slate-800 max-w-[10rem] truncate">{{ displayName }}</span>
                <i class="pi pi-chevron-down text-xs text-slate-500" />
              </button>
              <div v-if="userMenuOpen"
                class="absolute right-0 mt-2 w-52 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-40">
                <div class="px-3 py-2 border-b border-slate-100">
                  <div class="text-sm font-medium text-slate-900 truncate">{{ displayName }}</div>
                  <div class="text-xs text-slate-500 truncate">{{ auth.user?.email }}</div>
                </div>
                <RouterLink :to="{ name: 'portal-profile' }" @click="userMenuOpen = false"
                  class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                  <i class="pi pi-user text-slate-400" /> {{ t('portalLayout.userMenu.profile') }}
                </RouterLink>
                <RouterLink :to="{ name: 'portal-settings' }" @click="userMenuOpen = false"
                  class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                  <i class="pi pi-cog text-slate-400" /> {{ t('portalLayout.userMenu.settings') }}
                </RouterLink>
                <button @click="doLogout"
                  class="w-full flex items-center gap-2 px-3 py-2 text-sm text-rose-600 hover:bg-rose-50 border-t border-slate-100 mt-1">
                  <i class="pi pi-sign-out" /> {{ t('portalLayout.userMenu.logout') }}
                </button>
              </div>
            </div>

            <!-- Mobile hamburger -->
            <button @click.stop="mobileMenuOpen = !mobileMenuOpen"
              class="md:hidden portal-mobile-menu-anchor p-2 rounded hover:bg-slate-100">
              <i class="pi pi-bars text-slate-700" />
            </button>
          </div>
        </div>

        <!-- Mobile nav drawer -->
        <div v-if="mobileMenuOpen" class="md:hidden pb-3 pt-1 border-t border-slate-100">
          <RouterLink v-for="n in navItems" :key="n.key"
            :to="{ name: n.name }"
            @click="mobileMenuOpen = false"
            :class="[
              'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium',
              route.name === n.name
                ? 'bg-brand-100 text-brand-700'
                : 'text-slate-700 hover:bg-slate-50',
            ]">
            <i :class="n.icon" />
            {{ t(`portalLayout.nav.${n.key}`) }}
          </RouterLink>
        </div>
      </div>
    </header>

    <!-- Main content -->
    <main class="max-w-6xl mx-auto px-4 sm:px-6 py-6">
      <RouterView />
    </main>

    <!-- Footer -->
    <footer class="mt-8 py-6 text-center text-xs text-slate-400">
      <div>InsureHub — {{ t('portalLayout.footer.agentPortal') }}</div>
    </footer>
  </div>
</template>
