<script setup lang="ts">
defineProps<{
  open: boolean
  agentName: string
  active: boolean
  busy?: boolean
  error?: string | null
}>()
defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>()
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" data-testid="agent-active-dialog" @click.self="$emit('cancel')">
    <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
      <h3 class="text-base font-semibold text-slate-800">
        {{ active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }} {{ agentName }}?
      </h3>
      <ul v-if="active" class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li>ตัวแทน<b>เข้าระบบพอร์ทัลไม่ได้</b> และถูกออกจากระบบทันที</li>
        <li><b>เลือกเป็นผู้ขาย</b>ในกรมธรรม์ใหม่ไม่ได้ (กรมธรรม์เดิมไม่เปลี่ยน)</li>
        <li>ไม่ได้รับค่าแนะนำ/ส่วนต่างบริหารจากยอดขายของลูกทีมหลังจากนี้</li>
        <li>ค่าคอมที่ค้างจ่าย<b>ยังจ่ายตามปกติ</b></li>
      </ul>
      <p v-else class="mt-3 text-sm text-slate-600">ตัวแทนจะเข้าระบบพอร์ทัลได้ เลือกเป็นผู้ขายได้ และกลับมารับค่าคอมจากสายงานตามปกติ</p>
      <p v-if="error" class="mt-3 text-sm text-rose-600">{{ error }}</p>
      <div class="mt-5 flex justify-end gap-2">
        <button type="button" class="rounded border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50" @click="$emit('cancel')">ยกเลิก</button>
        <button type="button" data-testid="agent-active-confirm"
          :class="['rounded px-3 py-1.5 text-sm text-white disabled:opacity-50', active ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700']"
          :disabled="busy" @click="$emit('confirm')">
          {{ active ? 'ยืนยันปิดใช้งาน' : 'ยืนยันเปิดใช้งาน' }}
        </button>
      </div>
    </div>
  </div>
</template>
