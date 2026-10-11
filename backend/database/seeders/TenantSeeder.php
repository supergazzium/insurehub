<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'insurehub'],
            [
                'name' => 'อินชัวร์ฮับ โบรกเกอร์',
                'name_en' => 'InsureHub Broker',
                'tax_id' => null,
                'oic_license' => null,
                'phone' => null,
                'email' => 'ops@insurehub.co.th',
                'website' => 'https://insurehub.co.th',
                'address' => '55/72',
                'district' => 'บางเชือกหนัง',
                'amphoe' => 'ตลิ่งชัน',
                'province' => 'กรุงเทพมหานคร',
                'postcode' => '10170',
                'commission_mode' => 'asEarned',
                'payout_cycle' => 'monthly',
                'payout_min_balance' => 500.00,
                'payout_auto_approve' => false,
                'brand_color' => '#0e74e8',
                'email_signature' => "อินชัวร์ฮับ โบรกเกอร์\n02-xxx-xxxx",
                'active' => true,
            ],
        );

        // Default super-admin login (development seed only).
        User::updateOrCreate(
            ['email' => 'admin@insurehub.co.th'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Platform Admin',
                'password' => Hash::make('insurehub'),
                'role' => 'super_admin',
                'role_id' => \App\Models\Role::query()->where('key', 'super_admin')->value('id'),
                'locale' => 'th',
                'active' => true,
            ],
        );

        User::updateOrCreate(
            ['email' => 'staff@insurehub.co.th'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'เจ้าหน้าที่คีย์งาน (ทดสอบ)',
                'password' => Hash::make('insurehub'),
                'role' => 'sales_support',
                'role_id' => \App\Models\Role::query()->where('key', 'sales_support')->value('id'),
                'locale' => 'th',
                'active' => true,
            ],
        );

        $this->command?->info("  tenant: #{$tenant->id} {$tenant->slug}");
    }
}
