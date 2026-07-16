<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import CreateModal from '../../components/CreateModal.vue'
import FormField from '../../components/FormField.vue'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{
  (e: 'close'): void
  /** Forward the created customer row so callers can react to WHICH row was
   *  created (e.g. broadcast it to another tab for auto-populate). */
  (e: 'created', row: Record<string, unknown>): void
}>()

const form = reactive({
  customerCode: '',
  customerType: 'individual' as 'individual' | 'corporate',
  titleTh: '',
  firstName: '',
  lastName: '',
  nickname: '',
  idCard: '',
  gender: '' as '' | 'ชาย' | 'หญิง' | 'other',
  birthDate: '',
  phone: '',
  email: '',
  province: '',
})

const canSubmit = computed(() =>
  form.customerCode.trim() !== '' &&
  form.firstName.trim() !== '' &&
  form.lastName.trim() !== '',
)

const payload = computed(() => ({
  customerCode: form.customerCode.trim(),
  customerType: form.customerType,
  titleTh: form.titleTh.trim() || null,
  firstName: form.firstName.trim(),
  lastName: form.lastName.trim(),
  nickname: form.nickname.trim() || null,
  idCard: form.idCard.trim() || null,
  gender: form.gender || null,
  birthDate: form.birthDate || null,
  phone: form.phone.trim() || null,
  email: form.email.trim() || null,
  province: form.province.trim() || null,
  active: true,
}))

// Autogenerate a customerCode suggestion when opening: C + timestamp seconds.
function suggestCode(): string {
  const ts = Math.floor(Date.now() / 1000).toString().slice(-7)
  return `C${ts}`
}

watch(
  () => props.open,
  (v) => {
    if (v) {
      Object.assign(form, {
        customerCode: suggestCode(),
        customerType: 'individual',
        titleTh: '', firstName: '', lastName: '', nickname: '',
        idCard: '', gender: '', birthDate: '',
        phone: '', email: '', province: '',
      })
    }
  },
)
</script>

<template>
  <CreateModal
    :open="open" entity="customers" title="New Customer"
    :payload="payload" :can-submit="canSubmit"
    @close="emit('close')" @created="(row) => emit('created', row)"
  >
    <template #default="{ fieldErrors }">
      <div class="grid grid-cols-2 gap-4">
        <FormField label="Customer code" required error-key="customerCode" :errors="fieldErrors"
          hint="Unique within tenant. Prefilled with a suggestion; adjust if needed.">
          <input v-model.trim="form.customerCode"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
        </FormField>
        <FormField label="Type" error-key="customerType" :errors="fieldErrors">
          <select v-model="form.customerType"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="individual">Individual</option>
            <option value="corporate">Corporate</option>
          </select>
        </FormField>
        <FormField label="Title (Thai)" error-key="titleTh" :errors="fieldErrors">
          <input v-model.trim="form.titleTh" placeholder="นาย / นาง / นางสาว"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Nickname" error-key="nickname" :errors="fieldErrors">
          <input v-model.trim="form.nickname"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="First name" required error-key="firstName" :errors="fieldErrors">
          <input v-model.trim="form.firstName"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Last name" required error-key="lastName" :errors="fieldErrors">
          <input v-model.trim="form.lastName"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="ID card" error-key="idCard" :errors="fieldErrors">
          <input v-model.trim="form.idCard" maxlength="13"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
        </FormField>
        <FormField label="Gender" error-key="gender" :errors="fieldErrors">
          <select v-model="form.gender"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">—</option>
            <option value="ชาย">ชาย</option>
            <option value="หญิง">หญิง</option>
            <option value="other">Other</option>
          </select>
        </FormField>
        <FormField label="Birth date" error-key="birthDate" :errors="fieldErrors">
          <input v-model="form.birthDate" type="date"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Phone" error-key="phone" :errors="fieldErrors">
          <input v-model.trim="form.phone"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Email" class="col-span-2" error-key="email" :errors="fieldErrors">
          <input v-model.trim="form.email" type="email"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Province" class="col-span-2" error-key="province" :errors="fieldErrors">
          <input v-model.trim="form.province"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
      </div>
    </template>
  </CreateModal>
</template>
