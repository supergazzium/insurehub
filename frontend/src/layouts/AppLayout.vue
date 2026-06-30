<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { MODULES, MODULE_GROUPS } from '../types/modules'

const route = useRoute()
const { t } = useI18n()

const sidebarOpen = ref(true)
const userMenuOpen = ref(false)

const groupedModules = computed(() =>
  MODULE_GROUPS.map((g) => ({
    ...g,
    modules: MODULES.filter((m) => m.group === g.key),
  })),
)

const currentCrumbs = computed(() => {
  const crumbs: { label: string; to?: string }[] = [
    { label: t('nav.dashboard'), to: '/' },
  ]
  if (route.name && route.name !== 'dashboard') {
    const mod = MODULES.find((m) => m.routeName === route.name)
    if (mod) crumbs.push({ label: t(`modules.${mod.i18nKey}.name`) })
  }
  return crumbs
})

const toggleSidebar = () => (sidebarOpen.value = !sidebarOpen.value)

function closeUserMenu(e: MouseEvent) {
  const target = e.target as HTMLElement
  if (!target.closest('.user-menu-anchor')) userMenuOpen.value = false
}
</script>

<template>
  <div class="min-h-screen flex bg-slate-50" @click="closeUserMenu">
    <!-- Sidebar -->
    <aside
      :class="[
        'bg-white border-r border-slate-200 transition-all duration-200 flex flex-col',
        sidebarOpen ? 'w-64' : 'w-[72px]',
      ]"
    >
      <div class="h-16 flex items-center gap-3 px-4 border-b border-slate-200 shrink-0 overflow-hidden">
        <RouterLink to="/" class="flex items-center gap-3 min-w-0">
          <!-- Collapsed: owl-only crop -->
          <div
            v-if="!sidebarOpen"
            class="w-10 h-10 shrink-0 rounded-lg overflow-hidden bg-white flex items-center justify-center"
          >
            <img
              src="/brand/logo.png"
              alt="InsureHub"
              class="h-10 w-auto"
              style="object-fit: cover; object-position: left center; max-width: 40px;"
            />
          </div>
          <!-- Expanded: full wordmark -->
          <img
            v-else
            src="/brand/logo.png"
            alt="InsureHub"
            class="h-9 w-auto object-contain"
          />
        </RouterLink>
      </div>

      <nav class="flex-1 overflow-y-auto py-3">
        <RouterLink
          to="/"
          class="mx-2 mb-3 px-3 py-2 rounded-lg flex items-center gap-3 text-sm transition"
          :class="[
            $route.name === 'dashboard'
              ? 'bg-brand-50 text-brand-700 font-medium'
              : 'text-slate-600 hover:bg-slate-50',
          ]"
        >
          <i class="pi pi-home shrink-0" />
          <span v-if="sidebarOpen">{{ t('nav.dashboard') }}</span>
        </RouterLink>

        <div v-for="group in groupedModules" :key="group.key" class="mb-4">
          <div
            v-if="sidebarOpen"
            class="px-5 mb-1 text-[10px] font-semibold text-slate-400 uppercase tracking-wider"
          >
            {{ group.labelTh }}
          </div>
          <div v-else class="mx-3 my-2 border-t border-slate-100" />
          <RouterLink
            v-for="m in group.modules"
            :key="m.key"
            :to="m.routePath"
            class="mx-2 px-3 py-2 rounded-lg flex items-center gap-3 text-sm transition"
            :class="[
              $route.name === m.routeName
                ? 'bg-brand-50 text-brand-700 font-medium'
                : 'text-slate-600 hover:bg-slate-50',
            ]"
            :title="!sidebarOpen ? t(`modules.${m.i18nKey}.name`) : ''"
          >
            <i :class="[m.icon, 'shrink-0']" />
            <span v-if="sidebarOpen" class="truncate">
              {{ t(`modules.${m.i18nKey}.short`) }}
            </span>
          </RouterLink>
        </div>
      </nav>

      <div class="p-3 border-t border-slate-200">
        <button
          class="w-full px-3 py-2 rounded-lg text-slate-500 hover:bg-slate-50 flex items-center gap-3 text-sm"
          @click="toggleSidebar"
        >
          <i :class="sidebarOpen ? 'pi pi-angle-double-left' : 'pi pi-angle-double-right'" />
          <span v-if="sidebarOpen">ย่อเมนู</span>
        </button>
      </div>
    </aside>

    <!-- Main column -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Topbar -->
      <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-6 shrink-0">
        <div class="flex items-center gap-3 text-sm">
          <template v-for="(c, idx) in currentCrumbs" :key="idx">
            <span v-if="idx > 0" class="text-slate-300">/</span>
            <RouterLink
              v-if="c.to"
              :to="c.to"
              class="text-slate-500 hover:text-slate-900 transition"
            >
              {{ c.label }}
            </RouterLink>
            <span v-else class="text-slate-900 font-medium">{{ c.label }}</span>
          </template>
        </div>

        <div class="flex items-center gap-3">
          <div class="relative hidden md:block">
            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
            <input
              type="search"
              :placeholder="t('common.search') + '...'"
              class="pl-9 pr-3 py-2 w-64 text-sm bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-400 focus:bg-white"
            />
          </div>

          <button class="w-9 h-9 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 relative">
            <i class="pi pi-bell" />
            <span class="absolute top-2 right-2 w-2 h-2 bg-rose-500 rounded-full" />
          </button>

          <div class="user-menu-anchor relative">
            <button
              class="flex items-center gap-2 px-2 py-1 rounded-lg hover:bg-slate-100"
              @click.stop="userMenuOpen = !userMenuOpen"
            >
              <div class="w-8 h-8 rounded-full bg-brand-600 text-white flex items-center justify-center text-sm font-medium">
                ผ
              </div>
              <div class="hidden md:block text-right">
                <div class="text-sm text-slate-900 font-medium leading-tight">ผู้ดูแลระบบ</div>
                <div class="text-[11px] text-slate-500 leading-tight">admin@insurehub.test</div>
              </div>
              <i class="pi pi-angle-down text-slate-400 text-xs" />
            </button>

            <div
              v-if="userMenuOpen"
              class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-50"
            >
              <button class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50 flex items-center gap-2 text-slate-700">
                <i class="pi pi-user text-slate-400" /> {{ t('topbar.profile') }}
              </button>
              <button class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50 flex items-center gap-2 text-slate-700">
                <i class="pi pi-cog text-slate-400" /> {{ t('topbar.settings') }}
              </button>
              <div class="border-t border-slate-100 my-1" />
              <button class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50 flex items-center gap-2 text-rose-600">
                <i class="pi pi-sign-out" /> {{ t('topbar.logout') }}
              </button>
            </div>
          </div>
        </div>
      </header>

      <!-- Content -->
      <main class="flex-1 overflow-y-auto p-6">
        <div class="max-w-7xl mx-auto">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
