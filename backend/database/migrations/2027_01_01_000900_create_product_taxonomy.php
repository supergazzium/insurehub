<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_taxonomy', function (Blueprint $table): void {
            // `group` is a reserved keyword in MySQL, hence the trailing underscore
            // on the column name; the API exposes it as `group` in camelCase.
            $table->id();
            $table->string('group_', 32);
            $table->string('category', 128);
            $table->string('subcategory', 128)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['group_', 'category', 'subcategory'], 'pt_group_cat_sub_unique');
            $table->index(['group_', 'active']);
        });

        $now = now();
        $rows = [
            // Life + Main
            ['Life', 'ประเภทสามัญ', 'ประกันตลอดชีพ'],
            ['Life', 'ประเภทสามัญ', 'ประกันสะสมทรัพย์'],
            ['Life', 'ประเภทสามัญ', 'ประกันบำนาญ'],
            ['Life', 'ประเภทสามัญ', 'ชั่วระยะเวลา'],
            ['PA', 'ประเภทสามัญ', 'ประกันอุบัติเหตุ'],
            // Group is split by carrier insureType so the "ชีวิต" subcategory
            // only appears for life carriers. Frontend translates the visible
            // "Group" label ↔ Group-Life / Group-NL at read/write time.
            ['Group-Life', 'ประกันกลุ่ม', 'ประกันกลุ่มชีวิต'],
            ['Group-Life', 'ประกันกลุ่ม', 'ประกันกลุ่มMRTA'],
            ['Group-Life', 'ประกันกลุ่ม', 'ประกันกลุ่มสุขภาพ'],
            ['Group-Life', 'ประกันกลุ่ม', 'ประกันกลุ่มอุบัติเหตุ'],
            ['Group-NL', 'ประกันกลุ่ม', 'ประกันกลุ่มMRTA'],
            ['Group-NL', 'ประกันกลุ่ม', 'ประกันกลุ่มสุขภาพ'],
            ['Group-NL', 'ประกันกลุ่ม', 'ประกันกลุ่มอุบัติเหตุ'],
            // Life + Rider
            ['Rider', 'ประเภทสามัญ', 'อนุสัญญา'],
            // Non-life + Main (Group shares 'ประกันกลุ่ม' with Life+Group above; separate group row lives here)
            // Motor
            ['Motor', 'การประกันรถโดยความสมัครใจ', 'ป1.'],
            ['Motor', 'การประกันรถโดยความสมัครใจ', 'ป2.'],
            ['Motor', 'การประกันรถโดยความสมัครใจ', 'ป2Plus'],
            ['Motor', 'การประกันรถโดยความสมัครใจ', 'ป3.'],
            ['Motor', 'การประกันรถโดยความสมัครใจ', 'ป3Plus'],
            ['Motor', 'การประกันรถโดยข้อบังคับแห่งกฏหมาย', 'พรบ'],
            // Non-Motor
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'ขนส่ง'],
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'Marine'],
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'ประกันการเดินทาง'],
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'เบ็ดเตล็ด'],
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'ประกันอุบัติเหตุส่วนบุคคล'],
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'วิชาชีพ'],
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'สัตว์เลี้ยง'],
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'สุขภาพ'],
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'Public Liability'],
            ['Non-Motor', 'การประกันภัยเบ็ดเตล็ด', 'CPM'],
            ['Non-Motor', 'การประกันอัคคีภัย', 'อัคคีภัยพื้นฐาน'],
            ['Non-Motor', 'การประกันอัคคีภัย', 'อัคคีภัยPackage'],
            ['Non-Motor', 'การประกันอัคคีภัย', 'อัคคีภัย IAR'],
            // Tax
            ['Tax', 'ต่อภาษี', 'ต่อภาษี'],
        ];

        $order = 0;
        foreach ($rows as [$group, $category, $sub]) {
            DB::table('product_taxonomy')->insert([
                'group_' => $group,
                'category' => $category,
                'subcategory' => $sub,
                'sort_order' => $order++,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_taxonomy');
    }
};
