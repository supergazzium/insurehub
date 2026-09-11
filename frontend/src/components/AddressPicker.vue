<script setup lang="ts">
// Cascading Thai address editor (จังหวัด → อำเภอ/เขต → ตำบล/แขวง → รหัสไปรษณีย์),
// used on the customer edit page for both the registered and mailing blocks.
//
// Mirrors the create page's dropdown behaviour (SearchSelect + cascade +
// postcode auto-fill) but instead of feeding one submit payload, it PATCHes
// each field back the moment it changes — matching the inline-save UX of the
// rest of the edit page. The parent maps our four logical slots onto its own
// field keys via the `fields` prop:
//   registered → { province: 'province', district: 'amphoe', subDistrict: 'district', postcode: 'postcode' }
//   mailing    → { province: 'mailing.province', district: 'mailing.district', subDistrict: 'mailing.subDistrict', postcode: 'mailing.postcode' }
// (On the registered block the backend column names are shifted: the app's
// `amphoe` is the district lookup and the app's `district` is the sub-district
// lookup — see CustomerRequest::toModel. The mailing block keeps the natural
// district/subDistrict naming.)

import { onMounted, ref, watch } from 'vue'
import SearchSelect, { type SearchOption } from './SearchSelect.vue'
import { fetchProvinces, fetchDistricts, fetchSubDistricts } from '../api/portal'
import { api, ApiError } from '../api/client'

const props = defineProps<{
  entity: string
  id: string
  /** Logical-slot → PATCH field-key mapping (supports dotted nested keys). */
  fields: { province: string; district: string; subDistrict: string; postcode: string }
  province: string | null
  district: string | null
  subDistrict: string | null
  postcode: string | null
}>()

const emit = defineEmits<{
  (e: 'update', field: string, value: string | null): void
}>()

// Local mirrors of the four values so the SearchSelects have a v-model.
const province = ref(props.province ?? '')
const district = ref(props.district ?? '')
const subDistrict = ref(props.subDistrict ?? '')
const postcode = ref(props.postcode ?? '')
// Last value we persisted, so the manual-override save only PATCHes on a real
// change (whether the value came from auto-fill or from the operator typing).
let lastSavedPostcode = props.postcode ?? ''

// Option lists.
const provinces = ref<string[]>([])
const districts = ref<string[]>([])
const subDistricts = ref<{ name: string; postcode: string }[]>([])

const provinceOptions = ref<SearchOption[]>([])
const districtOptions = ref<SearchOption[]>([])
const subDistrictOptions = ref<SearchOption[]>([])

function rebuildProvinceOptions(): void {
  provinceOptions.value = provinces.value.map((p) => ({ value: p, label: p }))
}
function rebuildDistrictOptions(): void {
  districtOptions.value = districts.value.map((d) => ({ value: d, label: d }))
}
function rebuildSubDistrictOptions(): void {
  subDistrictOptions.value = subDistricts.value.map((s) => ({ value: s.name, label: s.name, sublabel: s.postcode }))
}

const saveError = ref<string | null>(null)

/** PATCH one field (dotted keys expand to a nested object) and notify parent. */
async function patch(field: string, value: string | null): Promise<void> {
  saveError.value = null
  const parts = field.split('.')
  const root: Record<string, unknown> = {}
  let cursor = root
  for (let i = 0; i < parts.length - 1; i++) {
    const child: Record<string, unknown> = {}
    cursor[parts[i]] = child
    cursor = child
  }
  cursor[parts[parts.length - 1]] = value
  try {
    await api.patch(`${props.entity}/${props.id}`, root)
    emit('update', field, value)
  } catch (e: unknown) {
    saveError.value = e instanceof ApiError ? (e.body?.message ?? `HTTP ${e.status}`) : 'บันทึกไม่สำเร็จ'
  }
}

// Guard so hydration on mount doesn't fire the cascade-clear + PATCH logic.
const hydrating = ref(true)

onMounted(async () => {
  try {
    provinces.value = (await fetchProvinces()).data
    rebuildProvinceOptions()
    if (province.value) {
      districts.value = (await fetchDistricts(province.value)).data
      rebuildDistrictOptions()
    }
    if (province.value && district.value) {
      subDistricts.value = (await fetchSubDistricts(province.value, district.value)).data
      rebuildSubDistrictOptions()
    }
  } catch { /* lookups are best-effort; the fields still edit as free text values */ }
  hydrating.value = false
})

watch(province, async (p) => {
  if (hydrating.value) return
  // Reset downstream selections when the province changes.
  district.value = ''
  subDistrict.value = ''
  postcode.value = ''
  districts.value = []
  subDistricts.value = []
  rebuildDistrictOptions()
  rebuildSubDistrictOptions()
  if (p) {
    try { districts.value = (await fetchDistricts(p)).data; rebuildDistrictOptions() } catch { /* silent */ }
  }
  await patch(props.fields.province, p || null)
  await patch(props.fields.district, null)
  await patch(props.fields.subDistrict, null)
  await patch(props.fields.postcode, null)
  lastSavedPostcode = ''
})

watch(district, async (d) => {
  if (hydrating.value) return
  subDistrict.value = ''
  postcode.value = ''
  subDistricts.value = []
  rebuildSubDistrictOptions()
  if (province.value && d) {
    try { subDistricts.value = (await fetchSubDistricts(province.value, d)).data; rebuildSubDistrictOptions() } catch { /* silent */ }
  }
  await patch(props.fields.district, d || null)
  await patch(props.fields.subDistrict, null)
  await patch(props.fields.postcode, null)
  lastSavedPostcode = ''
})

watch(subDistrict, async (s) => {
  if (hydrating.value) return
  const row = subDistricts.value.find((r) => r.name === s)
  postcode.value = row?.postcode ?? postcode.value
  await patch(props.fields.subDistrict, s || null)
  if (row?.postcode) {
    await patch(props.fields.postcode, row.postcode)
    lastSavedPostcode = row.postcode
  }
})

// Postcode is auto-filled from the chosen sub-district but stays editable as a
// manual override — save on blur / Enter, only when the value actually changed
// from what we last persisted.
async function savePostcode(): Promise<void> {
  if (hydrating.value) return
  const next = postcode.value.trim()
  if (next === lastSavedPostcode) return
  await patch(props.fields.postcode, next || null)
  lastSavedPostcode = next
}
</script>

<template>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
    <div>
      <div class="text-xs text-slate-400 mb-1">จังหวัด</div>
      <SearchSelect v-model="province" :options="provinceOptions" placeholder="พิมพ์เพื่อค้นหาจังหวัด…" />
    </div>
    <div>
      <div class="text-xs text-slate-400 mb-1">อำเภอ / เขต</div>
      <SearchSelect v-model="district" :options="districtOptions"
        :placeholder="province ? 'เลือกอำเภอ/เขต…' : 'เลือกจังหวัดก่อน'" />
    </div>
    <div>
      <div class="text-xs text-slate-400 mb-1">ตำบล / แขวง</div>
      <SearchSelect v-model="subDistrict" :options="subDistrictOptions"
        :placeholder="district ? 'เลือกตำบล/แขวง…' : 'เลือกอำเภอ/เขตก่อน'" />
    </div>
    <div>
      <div class="text-xs text-slate-400 mb-1">รหัสไปรษณีย์</div>
      <input v-model.trim="postcode" inputmode="numeric" maxlength="5"
        class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
        placeholder="—"
        @blur="savePostcode" @keyup.enter="savePostcode" />
    </div>
    <p v-if="saveError" class="col-span-2 md:col-span-4 text-xs text-rose-600">{{ saveError }}</p>
  </div>
</template>
