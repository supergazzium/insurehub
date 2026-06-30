<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const router = useRouter()

const step = ref(1)
const submitting = ref(false)
const done = ref(false)

const agency = reactive({
  name: '',
  nameEn: '',
  taxId: '',
  phone: '',
  oicLicense: '',
  address: '',
  district: '',
  amphoe: '',
  province: '',
  postcode: '',
})

const owner = reactive({
  firstName: '',
  lastName: '',
  email: '',
  phone: '',
  password: '',
  confirmPassword: '',
})

const agreed = ref(false)

const steps = computed(() => [
  { n: 1, label: t('auth.register.steps.agency') },
  { n: 2, label: t('auth.register.steps.owner') },
  { n: 3, label: t('auth.register.steps.review') },
])

const step1Valid = computed(
  () =>
    agency.name.trim().length > 1 &&
    /^\d{13}$/.test(agency.taxId) &&
    agency.phone.length >= 9 &&
    agency.address.trim().length > 3 &&
    agency.province.trim().length > 0 &&
    /^\d{5}$/.test(agency.postcode),
)

const passwordStrong = computed(
  () =>
    owner.password.length >= 8 &&
    /[A-Z]/.test(owner.password) &&
    /[0-9]/.test(owner.password) &&
    /[^A-Za-z0-9]/.test(owner.password),
)

const passwordsMatch = computed(
  () => owner.password.length > 0 && owner.password === owner.confirmPassword,
)

const step2Valid = computed(
  () =>
    owner.firstName.trim().length > 0 &&
    owner.lastName.trim().length > 0 &&
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(owner.email) &&
    owner.phone.length >= 9 &&
    passwordStrong.value &&
    passwordsMatch.value,
)

function goNext() {
  if (step.value === 1 && !step1Valid.value) return
  if (step.value === 2 && !step2Valid.value) return
  step.value = Math.min(3, step.value + 1)
}

function goBack() {
  step.value = Math.max(1, step.value - 1)
}

async function submit() {
  if (!agreed.value) return
  submitting.value = true
  await new Promise((r) => setTimeout(r, 700))
  submitting.value = false
  done.value = true
  setTimeout(() => router.push('/login'), 2500)
}
</script>

<template>
  <div v-if="done" class="text-center py-8">
    <div class="w-16 h-16 mx-auto bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mb-4">
      <i class="pi pi-check text-3xl" />
    </div>
    <h2 class="text-xl font-semibold text-slate-900">{{ t('auth.register.success.title') }}</h2>
    <p class="text-slate-500 mt-2 text-sm">{{ t('auth.register.success.message') }}</p>
  </div>

  <div v-else>
    <h1 class="text-2xl font-semibold text-slate-900">{{ t('auth.register.title') }}</h1>
    <p class="text-slate-500 mt-1.5 text-sm">{{ t('auth.register.subtitle') }}</p>

    <!-- Stepper -->
    <ol class="flex items-center gap-2 mt-8 mb-6">
      <li v-for="(s, i) in steps" :key="s.n" class="flex items-center gap-2 flex-1">
        <div
          :class="[
            'w-7 h-7 rounded-full text-xs font-semibold flex items-center justify-center shrink-0',
            step >= s.n
              ? 'bg-brand-600 text-white'
              : 'bg-slate-100 text-slate-400 border border-slate-200',
          ]"
        >
          <i v-if="step > s.n" class="pi pi-check text-[10px]" />
          <span v-else>{{ s.n }}</span>
        </div>
        <span
          :class="[
            'text-xs hidden sm:inline',
            step === s.n ? 'text-slate-900 font-medium' : 'text-slate-500',
          ]"
        >
          {{ s.label }}
        </span>
        <div v-if="i < steps.length - 1" class="flex-1 h-px bg-slate-200" />
      </li>
    </ol>

    <!-- Step 1: Agency info -->
    <form v-if="step === 1" class="space-y-4" @submit.prevent="goNext">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.register.agency.name') }} <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="agency.name"
          type="text"
          required
          :placeholder="t('auth.register.agency.namePlaceholder')"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.register.agency.nameEn') }}
        </label>
        <input
          v-model="agency.nameEn"
          type="text"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.register.agency.taxId') }} <span class="text-rose-500">*</span>
          </label>
          <input
            v-model="agency.taxId"
            type="text"
            required
            maxlength="13"
            inputmode="numeric"
            placeholder="0000000000000"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.register.agency.phone') }} <span class="text-rose-500">*</span>
          </label>
          <input
            v-model="agency.phone"
            type="tel"
            required
            placeholder="02-xxx-xxxx"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.register.agency.oicLicense') }}
        </label>
        <input
          v-model="agency.oicLicense"
          type="text"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.register.agency.address') }} <span class="text-rose-500">*</span>
        </label>
        <textarea
          v-model="agency.address"
          rows="2"
          required
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
        />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.register.agency.district') }}
          </label>
          <input
            v-model="agency.district"
            type="text"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.register.agency.amphoe') }}
          </label>
          <input
            v-model="agency.amphoe"
            type="text"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.register.agency.province') }} <span class="text-rose-500">*</span>
          </label>
          <input
            v-model="agency.province"
            type="text"
            required
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.register.agency.postcode') }} <span class="text-rose-500">*</span>
          </label>
          <input
            v-model="agency.postcode"
            type="text"
            required
            maxlength="5"
            inputmode="numeric"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>
      </div>

      <div class="pt-2">
        <button
          type="submit"
          :disabled="!step1Valid"
          class="w-full py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ t('auth.register.next') }}
        </button>
      </div>
    </form>

    <!-- Step 2: Owner account -->
    <form v-else-if="step === 2" class="space-y-4" @submit.prevent="goNext">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.register.owner.firstName') }} <span class="text-rose-500">*</span>
          </label>
          <input
            v-model="owner.firstName"
            type="text"
            required
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.register.owner.lastName') }} <span class="text-rose-500">*</span>
          </label>
          <input
            v-model="owner.lastName"
            type="text"
            required
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.register.owner.email') }} <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="owner.email"
          type="email"
          required
          autocomplete="email"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.register.owner.phone') }} <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="owner.phone"
          type="tel"
          required
          placeholder="08x-xxx-xxxx"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.register.owner.password') }} <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="owner.password"
          type="password"
          required
          autocomplete="new-password"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
        <p class="text-xs mt-1.5" :class="passwordStrong ? 'text-emerald-600' : 'text-slate-500'">
          <i :class="passwordStrong ? 'pi pi-check-circle' : 'pi pi-info-circle'" class="mr-1" />
          {{ t('auth.register.owner.passwordHint') }}
        </p>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.register.owner.confirmPassword') }} <span class="text-rose-500">*</span>
        </label>
        <input
          v-model="owner.confirmPassword"
          type="password"
          required
          autocomplete="new-password"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
        <p
          v-if="owner.confirmPassword && !passwordsMatch"
          class="text-xs mt-1.5 text-rose-600"
        >
          <i class="pi pi-times-circle mr-1" />
          {{ t('auth.reset.mismatch') }}
        </p>
      </div>

      <div class="grid grid-cols-2 gap-3 pt-2">
        <button
          type="button"
          @click="goBack"
          class="py-2.5 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition"
        >
          {{ t('auth.register.back') }}
        </button>
        <button
          type="submit"
          :disabled="!step2Valid"
          class="py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ t('auth.register.next') }}
        </button>
      </div>
    </form>

    <!-- Step 3: Review -->
    <div v-else class="space-y-5">
      <div class="card p-4 space-y-3 text-sm">
        <h3 class="font-semibold text-slate-900 mb-2">{{ t('auth.register.steps.agency') }}</h3>
        <dl class="grid grid-cols-3 gap-y-2">
          <dt class="text-slate-500 col-span-1">{{ t('auth.register.agency.name') }}</dt>
          <dd class="col-span-2 text-slate-900">{{ agency.name }}</dd>
          <dt class="text-slate-500 col-span-1">{{ t('auth.register.agency.taxId') }}</dt>
          <dd class="col-span-2 text-slate-900">{{ agency.taxId }}</dd>
          <dt class="text-slate-500 col-span-1">{{ t('auth.register.agency.phone') }}</dt>
          <dd class="col-span-2 text-slate-900">{{ agency.phone }}</dd>
          <dt class="text-slate-500 col-span-1">{{ t('auth.register.agency.address') }}</dt>
          <dd class="col-span-2 text-slate-900">
            {{ agency.address }} {{ agency.district }} {{ agency.amphoe }} {{ agency.province }} {{ agency.postcode }}
          </dd>
        </dl>
      </div>

      <div class="card p-4 space-y-3 text-sm">
        <h3 class="font-semibold text-slate-900 mb-2">{{ t('auth.register.steps.owner') }}</h3>
        <dl class="grid grid-cols-3 gap-y-2">
          <dt class="text-slate-500 col-span-1">ชื่อ-นามสกุล</dt>
          <dd class="col-span-2 text-slate-900">{{ owner.firstName }} {{ owner.lastName }}</dd>
          <dt class="text-slate-500 col-span-1">{{ t('auth.register.owner.email') }}</dt>
          <dd class="col-span-2 text-slate-900">{{ owner.email }}</dd>
          <dt class="text-slate-500 col-span-1">{{ t('auth.register.owner.phone') }}</dt>
          <dd class="col-span-2 text-slate-900">{{ owner.phone }}</dd>
        </dl>
      </div>

      <label class="flex items-start gap-2 cursor-pointer">
        <input
          v-model="agreed"
          type="checkbox"
          class="mt-0.5 w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
        />
        <span class="text-sm text-slate-600">
          {{ t('auth.register.review.agreement') }}
          <a href="#" class="text-brand-600 hover:text-brand-700 font-medium">{{ t('auth.register.review.terms') }}</a>
          {{ t('auth.register.review.and') }}
          <a href="#" class="text-brand-600 hover:text-brand-700 font-medium">{{ t('auth.register.review.privacy') }}</a>
        </span>
      </label>

      <div class="grid grid-cols-2 gap-3">
        <button
          type="button"
          @click="goBack"
          class="py-2.5 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition"
        >
          {{ t('auth.register.back') }}
        </button>
        <button
          type="button"
          :disabled="!agreed || submitting"
          @click="submit"
          class="py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
        >
          <i v-if="submitting" class="pi pi-spin pi-spinner" />
          <span>{{ t('auth.register.review.submit') }}</span>
        </button>
      </div>
    </div>

    <p class="text-center text-sm text-slate-500 mt-6">
      มีบัญชีอยู่แล้ว?
      <RouterLink to="/login" class="text-brand-600 hover:text-brand-700 font-medium ml-1">
        {{ t('auth.login.submit') }}
      </RouterLink>
    </p>
  </div>
</template>
