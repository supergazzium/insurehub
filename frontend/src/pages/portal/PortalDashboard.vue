<script setup lang="ts">
// Agent portal home — welcome banner, personal summary card, quick links.
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { fetchMyAgent, fetchMyRelations, type MyAgent, type AgentRelations } from '../../api/portal'

const { t } = useI18n()
const me = ref<MyAgent | null>(null)
const loading = ref(false)

// สายงาน — ต้นสาย (upline) + ลูกทีม (downline) of the signed-in agent.
const relations = ref<AgentRelations | null>(null)

// Level-dot colour by rank, mirroring the back-office agent view.
const REL_LEVEL_DOT: Record<string, string> = {
  l1: 'bg-slate-300', l2: 'bg-sky-400', l3: 'bg-emerald-400',
  l4: 'bg-amber-400', l5: 'bg-violet-500',
}

onMounted(async () => {
  loading.value = true
  try {
    const res = await fetchMyAgent()
    me.value = res.data
  } finally {
    loading.value = false
  }
  // Relations load independently so a failure here doesn't blank the page.
  try {
    relations.value = (await fetchMyRelations())
  } catch { /* leave null — section shows an empty state */ }
})

const quickLinks = [
  { key: 'quote', icon: 'pi pi-comments', to: 'https://line.me/', external: true },
  { key: 'carriers', icon: 'pi pi-building', to: '/carriers', external: false },
  { key: 'products', icon: 'pi pi-th-large', to: '/products', external: false },
  { key: 'commission', icon: 'pi pi-percentage', to: '/commissions/rebates', external: false },
  { key: 'compareHealth', icon: 'pi pi-heart', to: 'https://example.com/compare/health', external: true },
  { key: 'compareMotor', icon: 'pi pi-car', to: 'https://example.com/compare/motor', external: true },
]
</script>

<template>
  <div class="space-y-6">
    <!-- Welcome banner -->
    <section class="rounded-xl bg-gradient-to-r from-brand-600 to-brand-500 text-white p-6">
      <div class="text-sm opacity-90">{{ t('portal.dashboard.welcome') }}</div>
      <h1 class="text-2xl font-semibold mt-1">
        {{ me?.firstName || '' }} {{ me?.lastName || '' }}
      </h1>
      <p class="text-sm opacity-90 mt-2 max-w-2xl">
        {{ t('portal.dashboard.howto') }}
      </p>
      <div v-if="me?.approvalStatus === 'pending'"
        class="mt-4 inline-block px-3 py-1.5 rounded-md bg-amber-100 text-amber-900 text-xs">
        <i class="pi pi-clock mr-1" /> {{ t('portal.dashboard.pendingApproval') }}
      </div>
    </section>

    <!-- Personal summary -->
    <section class="card p-5">
      <h2 class="text-sm font-semibold text-slate-600 mb-3">{{ t('portal.dashboard.summary') }}</h2>
      <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
      <dl v-else-if="me" class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div><dt class="text-xs text-slate-400">{{ t('portal.field.name') }}</dt>
          <dd class="font-medium text-slate-900">{{ me.firstName }} {{ me.lastName }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('portal.field.agentCode') }}</dt>
          <dd class="font-mono text-slate-900">{{ me.agentCode }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('portal.field.phone') }}</dt>
          <dd class="text-slate-900">{{ me.phone || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('portal.field.email') }}</dt>
          <dd class="text-slate-900 truncate">{{ me.email || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('portal.field.lineId') }}</dt>
          <dd class="text-slate-900">{{ me.lineId || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('portal.field.joinedAt') }}</dt>
          <dd class="text-slate-900">{{ me.joinedAt || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('portal.field.approvalStatus') }}</dt>
          <dd>
            <span :class="{
              'bg-emerald-50 text-emerald-700': me.approvalStatus === 'approved',
              'bg-amber-50 text-amber-700': me.approvalStatus === 'pending',
              'bg-rose-50 text-rose-700': me.approvalStatus === 'rejected',
            }" class="inline-flex px-2 py-0.5 rounded-md text-xs">
              {{ me.approvalStatus }}
            </span>
          </dd></div>
      </dl>
    </section>

    <!-- สายงาน — ต้นสาย (upline) & ลูกทีม (downline) -->
    <section class="card p-5">
      <h2 class="text-sm font-semibold text-slate-600 mb-3">สายงานของฉัน</h2>

      <!-- Upline: me → up to the top -->
      <div class="mb-4">
        <div class="mb-1.5 text-xs font-medium text-slate-500">ต้นสาย (ขึ้นไปด้านบน)</div>
        <div v-if="!relations || relations.upline.length === 0" class="text-xs text-slate-400">
          — ไม่มีต้นสาย (คุณเป็นระดับบนสุด) —
        </div>
        <div v-else class="flex flex-wrap items-center gap-1.5">
          <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white">
            <span :class="['h-2 w-2 rounded-full', REL_LEVEL_DOT[relations.self.level ?? ''] ?? 'bg-white/60']" />
            {{ relations.self.name || relations.self.agentCode }}
            <span class="opacity-70">{{ relations.self.level }}</span>
          </span>
          <template v-for="u in relations.upline" :key="u.id">
            <i class="pi pi-arrow-up text-[9px] text-slate-300" />
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-700">
              <span :class="['h-2 w-2 rounded-full', REL_LEVEL_DOT[u.level ?? ''] ?? 'bg-slate-300']" />
              {{ u.name || u.agentCode }} <span class="opacity-60">{{ u.level }}</span>
            </span>
          </template>
        </div>
      </div>

      <!-- Downline: my direct team -->
      <div class="border-t border-slate-100 pt-4">
        <div class="mb-1.5 flex items-center gap-2 text-xs font-medium text-slate-500">
          ลูกทีม (ตัวแทนที่ฉันแนะนำ)
          <span v-if="relations" class="rounded-full bg-slate-100 px-1.5 text-[10px] text-slate-600">{{ relations.downline.length }}</span>
        </div>
        <div v-if="!relations || relations.downline.length === 0" class="text-xs text-slate-400">
          — ยังไม่มีลูกทีม · ชวนเพื่อนเป็นตัวแทนได้ที่เมนู "ลิงก์แนะนำ" —
        </div>
        <div v-else class="flex flex-wrap gap-1.5">
          <span v-for="d in relations.downline" :key="d.id"
            :class="['inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs',
              d.active ? 'border-slate-200 bg-white text-slate-700' : 'border-slate-100 bg-slate-50 text-slate-400 line-through']">
            <span :class="['h-2 w-2 rounded-full', REL_LEVEL_DOT[d.level ?? ''] ?? 'bg-slate-300']" />
            {{ d.name || d.agentCode }} <span class="opacity-60">{{ d.level }}</span>
          </span>
        </div>
      </div>
    </section>

    <!-- Quick links -->
    <section>
      <h2 class="text-sm font-semibold text-slate-600 mb-3">{{ t('portal.dashboard.quickLinks') }}</h2>
      <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
        <template v-for="link in quickLinks" :key="link.key">
          <RouterLink v-if="!link.external" :to="link.to"
            class="card p-4 hover:border-brand-400 transition flex items-center gap-3">
            <div class="w-10 h-10 rounded-md bg-brand-50 text-brand-600 flex items-center justify-center">
              <i :class="link.icon" />
            </div>
            <div>
              <div class="font-medium text-slate-900 text-sm">{{ t(`portal.link.${link.key}.title`) }}</div>
              <div class="text-xs text-slate-500 mt-0.5">{{ t(`portal.link.${link.key}.body`) }}</div>
            </div>
          </RouterLink>
          <a v-else :href="link.to" target="_blank" rel="noopener"
            class="card p-4 hover:border-brand-400 transition flex items-center gap-3">
            <div class="w-10 h-10 rounded-md bg-brand-50 text-brand-600 flex items-center justify-center">
              <i :class="link.icon" />
            </div>
            <div>
              <div class="font-medium text-slate-900 text-sm">
                {{ t(`portal.link.${link.key}.title`) }}
                <i class="pi pi-external-link text-[10px] text-slate-400 ml-1" />
              </div>
              <div class="text-xs text-slate-500 mt-0.5">{{ t(`portal.link.${link.key}.body`) }}</div>
            </div>
          </a>
        </template>
      </div>
    </section>
  </div>
</template>
