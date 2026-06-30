<script setup lang="ts">
const highlights = [
  { icon: 'pi pi-sitemap', title: 'รองรับโครงสร้าง MLM', desc: 'สายงานตัวแทนหลายชั้น คำนวณค่าคอมฯ แบบ differential override' },
  { icon: 'pi pi-shield', title: 'ปลอดภัยและตรวจสอบได้', desc: 'แยกข้อมูลรายหน่วยงาน บันทึกการตรวจสอบทุกการดำเนินการ' },
  { icon: 'pi pi-chart-line', title: 'รายงานครบวงจร', desc: 'ยอดขาย ค่าคอมฯ การจ่ายเงิน และผลการแนะนำ' },
]
</script>

<template>
  <div class="min-h-screen flex bg-slate-50">
    <!-- Brand side -->
    <aside class="hidden lg:flex flex-col w-1/2 xl:w-[45%] bg-gradient-to-br from-brand-700 via-brand-600 to-brand-800 text-white p-12 relative overflow-hidden">
      <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle at 20% 20%, white 1px, transparent 1px), radial-gradient(circle at 60% 70%, white 1px, transparent 1px); background-size: 80px 80px;" />

      <div class="relative z-10 flex flex-col h-full">
        <RouterLink to="/login" class="inline-flex items-center bg-white/95 rounded-xl px-3 py-2 shadow-sm">
          <img src="/brand/logo.png" alt="InsureHub" class="h-9 w-auto object-contain" />
        </RouterLink>

        <div class="my-auto py-16">
          <h2 class="text-3xl xl:text-4xl font-semibold leading-snug">
            ยกระดับการบริหารหน่วยงาน<br />ประกันภัยของคุณ
          </h2>
          <p class="text-brand-100 mt-3 max-w-md">
            ระบบจัดการตัวแทน ลูกค้า กรมธรรม์ และค่าคอมมิชชั่นแบบครบวงจรในที่เดียว
          </p>

          <ul class="mt-10 space-y-5">
            <li v-for="h in highlights" :key="h.title" class="flex items-start gap-3">
              <div class="w-9 h-9 rounded-lg bg-white/10 backdrop-blur flex items-center justify-center shrink-0">
                <i :class="h.icon" />
              </div>
              <div>
                <div class="font-medium">{{ h.title }}</div>
                <div class="text-brand-100 text-sm">{{ h.desc }}</div>
              </div>
            </li>
          </ul>
        </div>

        <div class="text-brand-100 text-xs">
          © {{ new Date().getFullYear() }} InsureHub. สงวนลิขสิทธิ์
        </div>
      </div>
    </aside>

    <!-- Form side -->
    <main class="flex-1 flex flex-col">
      <!-- Mobile brand bar -->
      <div class="lg:hidden h-16 flex items-center justify-between px-6 bg-white border-b border-slate-200">
        <RouterLink to="/login">
          <img src="/brand/logo.png" alt="InsureHub" class="h-8 w-auto object-contain" />
        </RouterLink>
      </div>

      <div class="flex-1 flex items-center justify-center p-6 sm:p-10">
        <div class="w-full max-w-md">
          <RouterView />
        </div>
      </div>
    </main>
  </div>
</template>
