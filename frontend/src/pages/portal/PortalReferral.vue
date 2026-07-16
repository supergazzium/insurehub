<script setup lang="ts">
// Personal referral link + downline list. Referred agents auto-attribute
// via ?ref=<token> when they open /register-agent.
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchReferralLink, fetchDownline, type ReferralLinkInfo, type MyAgent } from '../../api/portal'

const { t } = useI18n()

const link = ref<ReferralLinkInfo | null>(null)
const downline = ref<MyAgent[]>([])
const loading = ref(false)
const copied = ref(false)

onMounted(async () => {
  loading.value = true
  try {
    const [l, d] = await Promise.all([fetchReferralLink(), fetchDownline()])
    link.value = l
    downline.value = d.data
  } finally {
    loading.value = false
  }
})

async function copyLink(): Promise<void> {
  if (!link.value) return
  try {
    await navigator.clipboard.writeText(link.value.url)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch { /* clipboard blocked */ }
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('portal.referral.title') }}</h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('portal.referral.subtitle') }}</p>
    </header>

    <section class="card p-5">
      <h2 class="text-sm font-semibold text-slate-600 mb-3">{{ t('portal.referral.myLink') }}</h2>
      <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
      <div v-else-if="link" class="space-y-3">
        <div class="flex items-center gap-2">
          <input :value="link.url" readonly
            class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono bg-slate-50" />
          <button type="button" class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 flex items-center gap-1.5"
            @click="copyLink">
            <i :class="copied ? 'pi pi-check' : 'pi pi-copy'" class="text-xs" />
            {{ copied ? t('portal.referral.copied') : t('portal.referral.copy') }}
          </button>
        </div>
        <div class="grid grid-cols-3 gap-4 text-sm pt-2">
          <div><div class="text-xs text-slate-400">{{ t('portal.referral.clicks') }}</div>
            <div class="font-semibold text-slate-900 text-lg">{{ link.clicks.toLocaleString() }}</div></div>
          <div><div class="text-xs text-slate-400">{{ t('portal.referral.signups') }}</div>
            <div class="font-semibold text-slate-900 text-lg">{{ link.signups.toLocaleString() }}</div></div>
          <div><div class="text-xs text-slate-400">{{ t('portal.referral.pending') }}</div>
            <div class="font-semibold text-amber-700 text-lg">{{ link.pendingSignups.toLocaleString() }}</div></div>
        </div>
      </div>
    </section>

    <section class="card overflow-hidden">
      <header class="px-5 py-4 border-b border-slate-100">
        <h2 class="text-sm font-semibold text-slate-600">
          {{ t('portal.referral.downline') }}
          <span class="text-slate-500 font-normal">({{ downline.length }})</span>
        </h2>
      </header>
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th class="px-4 py-2 text-left">{{ t('portal.field.name') }}</th>
            <th class="px-4 py-2 text-left">{{ t('portal.field.agentCode') }}</th>
            <th class="px-4 py-2 text-left">{{ t('portal.field.phone') }}</th>
            <th class="px-4 py-2 text-left">{{ t('portal.field.lineId') }}</th>
            <th class="px-4 py-2 text-left">{{ t('portal.field.joinedAt') }}</th>
            <th class="px-4 py-2 text-left">{{ t('portal.field.approvalStatus') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!downline.length">
            <td colspan="6" class="px-4 py-6 text-center text-slate-400 text-xs">
              {{ t('portal.referral.empty') }}
            </td>
          </tr>
          <tr v-for="a in downline" :key="a.id">
            <td class="px-4 py-2">{{ a.firstName }} {{ a.lastName }}</td>
            <td class="px-4 py-2 font-mono text-xs">{{ a.agentCode }}</td>
            <td class="px-4 py-2">{{ a.phone || '—' }}</td>
            <td class="px-4 py-2">{{ a.lineId || '—' }}</td>
            <td class="px-4 py-2">{{ a.joinedAt || '—' }}</td>
            <td class="px-4 py-2">
              <span :class="{
                'bg-emerald-50 text-emerald-700': a.approvalStatus === 'approved',
                'bg-amber-50 text-amber-700': a.approvalStatus === 'pending',
                'bg-rose-50 text-rose-700': a.approvalStatus === 'rejected',
              }" class="inline-flex px-2 py-0.5 rounded-md text-xs">{{ a.approvalStatus }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
