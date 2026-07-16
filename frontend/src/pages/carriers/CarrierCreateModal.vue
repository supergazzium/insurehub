<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import CreateModal from '../../components/CreateModal.vue'
import FormField from '../../components/FormField.vue'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'created'): void
}>()

const form = reactive({
  code: '',
  name: '',
  nameEn: '',
  nicknameTh: '',
  type: '' as '' | 'life' | 'non-life' | 'tax',
  subType: '',
  oicInsureComCode: '',
  compInsureCode: '',
  taxId: '',
  phone: '',
  email: '',
  website: '',
})

const canSubmit = computed(() => form.code.trim() !== '' && form.name.trim() !== '')

const payload = computed(() => ({
  code: form.code.trim(),
  name: form.name.trim(),
  nameEn: form.nameEn.trim() || null,
  nicknameTh: form.nicknameTh.trim() || null,
  type: form.type || null,
  subType: form.subType.trim() || null,
  oicInsureComCode: form.oicInsureComCode.trim() || null,
  compInsureCode: form.compInsureCode.trim() || null,
  taxId: form.taxId.trim() || null,
  phone: form.phone.trim() || null,
  email: form.email.trim() || null,
  website: form.website.trim() || null,
  active: true,
}))

watch(
  () => props.open,
  (v) => {
    if (v) {
      Object.assign(form, {
        code: '', name: '', nameEn: '', nicknameTh: '', type: '',
        subType: '', oicInsureComCode: '', compInsureCode: '', taxId: '', phone: '', email: '', website: '',
      })
    }
  },
)
</script>

<template>
  <CreateModal
    :open="open" entity="carriers" title="New Carrier"
    :payload="payload" :can-submit="canSubmit"
    @close="emit('close')" @created="emit('created')"
  >
    <template #default="{ fieldErrors }">
      <div class="grid grid-cols-2 gap-4">
        <FormField label="Code" required error-key="code" :errors="fieldErrors" hint="Max 8 chars, unique within tenant.">
          <input v-model.trim="form.code" placeholder="AIA"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 uppercase" />
        </FormField>
        <FormField label="Type" error-key="type" :errors="fieldErrors">
          <select v-model="form.type"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">—</option>
            <option value="life">Life</option>
            <option value="non-life">Non-life</option>
            <option value="tax">Tax</option>
          </select>
        </FormField>
        <FormField label="Name (Thai)" required class="col-span-2" error-key="name" :errors="fieldErrors">
          <input v-model.trim="form.name" placeholder="บริษัท ..."
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Name (English)" class="col-span-2" error-key="nameEn" :errors="fieldErrors">
          <input v-model.trim="form.nameEn"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Nickname (Thai)" error-key="nicknameTh" :errors="fieldErrors">
          <input v-model.trim="form.nicknameTh"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Sub-type" error-key="subType" :errors="fieldErrors">
          <select v-model="form.subType"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">—</option>
            <option value="direct">Direct</option>
            <option value="partner">Partner</option>
          </select>
        </FormField>
        <FormField label="OIC Insurance Code" error-key="oicInsureComCode" :errors="fieldErrors" hint="Regulator (คปภ.) company code.">
          <input v-model.trim="form.oicInsureComCode" placeholder="e.g. 2014"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Company Insure Code" error-key="compInsureCode" :errors="fieldErrors" hint="Internal / company-issued insurer code.">
          <input v-model.trim="form.compInsureCode"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Tax ID" error-key="taxId" :errors="fieldErrors">
          <input v-model.trim="form.taxId"
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
        <FormField label="Website" class="col-span-2" error-key="website" :errors="fieldErrors">
          <input v-model.trim="form.website"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
      </div>
    </template>
  </CreateModal>
</template>
