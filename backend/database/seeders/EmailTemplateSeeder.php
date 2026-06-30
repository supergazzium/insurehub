<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seed built-in email templates from real-world drafts in
 * `Email Template/Template/*.docx`. Five insurance-type templates:
 *
 *   - CAR — Construction All Risk
 *   - D&O — Directors & Officers
 *   - IAR — Industrial All Risk
 *   - Group — group insurance (health / life / PA)
 *   - Motor — vehicle insurance
 *
 * Variables follow the frontend `TEMPLATE_VARIABLES` convention: camelCase
 * names like `{{carrierCode}}`, `{{clientName}}`, `{{caseId}}`. The original
 * `{{IC Code}}` / `{{ISH Code}}` placeholders from the docx files are mapped
 * to `{{carrierCode}}` and `{{caseId}}` respectively.
 */
class EmailTemplateSeeder extends Seeder
{
    private const SIGNATURE = "\n\n-- \nInsureHub Support Team\nโบรกเกอร์ประกันชีวิตและวินาศภัย\nTel: 062-702-6662\nMailto: operation@insurehub.co.th";

    /**
     * @return list<array{
     *   label:string, description:string, icon:string, department:string,
     *   subject:string, body:string
     * }>
     */
    private function templates(): array
    {
        return [
            // ── 1. Motor ─────────────────────────────────────────────────────
            [
                'label' => 'ขอราคา ประกันรถยนต์',
                'description' => 'จองงาน + ขอข้อเสนอประกันรถยนต์ ชั้น 1 / 2+ / 3+',
                'icon' => 'pi pi-car',
                'department' => 'new_business',
                'subject' => '[{{carrierCode}}] ขอราคาประกันรถยนต์ {{clientName}} // {{caseId}}',
                'body' => <<<TXT
เรียนเจ้าหน้าที่

ขอราคาประกันชั้น 1 ซ่อมห้าง และ ชั้น 2+
ผู้เอาประกัน: {{clientName}}
ยี่ห้อ/รุ่น: (ระบุ)
เลขทะเบียน: (ระบุ)
ปี: (ระบุ)
เลขตัวรถ: (ระบุ)

รายการจดทะเบียนตามแนบค่ะ

ขอขอบพระคุณค่ะ
TXT.self::SIGNATURE,
            ],

            // ── 2. IAR ───────────────────────────────────────────────────────
            [
                'label' => 'ขอราคา ประกัน IAR',
                'description' => 'จองงาน + ขอข้อเสนอประกัน IAR (Industrial All Risk)',
                'icon' => 'pi pi-building',
                'department' => 'new_business',
                'subject' => '[{{carrierCode}}] จองงาน + ขอราคาประกัน IAR // {{clientName}} // {{caseId}}',
                'body' => <<<TXT
เรียนเจ้าหน้าที่

จองงาน + ขอข้อเสนอประกัน IAR
ชื่อบริษัท: {{clientName}}
ที่ตั้ง: (ระบุที่อยู่)
ประกันหมด: (ระบุวันที่)
ลักษณะธุรกิจ: (ระบุ)

รายละเอียดทรัพย์สินเอาประกันภัย
- อาคารสำนักงาน / โรงงาน / สิ่งปลูกสร้าง: (ระบุทุน)
- เครื่องจักร / อุปกรณ์: (ระบุทุน)
- สต๊อกสินค้า: (ระบุทุน)

รวมทุนเอาประกันภัย: (ระบุยอดรวม)

ประวัติ Loss / Claim: (ระบุ)

รบกวนขอข้อเสนอที่แข่งขันได้ค่ะ

ขอขอบพระคุณค่ะ
TXT.self::SIGNATURE,
            ],

            // ── 3. CAR ───────────────────────────────────────────────────────
            [
                'label' => 'ขอราคา ประกัน CAR',
                'description' => 'จองงาน + ขอข้อเสนอประกัน CAR (Construction All Risk)',
                'icon' => 'pi pi-cog',
                'department' => 'new_business',
                'subject' => '[{{carrierCode}}] ขอข้อเสนอราคาประกัน CAR // {{clientName}} // {{caseId}}',
                'body' => <<<TXT
เรียนเจ้าหน้าที่

ขอข้อเสนอราคาประกัน CAR
- ผู้เอาประกันภัย: {{clientName}}
- ผู้รับจ้าง: (ระบุชื่อผู้รับจ้าง)
- สถานที่ก่อสร้าง: (ระบุที่อยู่หน้างาน)
- ระยะเวลาก่อสร้าง: (ระบุจำนวนวัน)
- มูลค่างานก่อสร้าง: (ระบุมูลค่า)
- ลักษณะงาน: (ระบุ)

เอกสารแนบ:
- สัญญาจ้าง
- BOQ
- Construction Detail
- รูปถ่ายหน้างาน

สอบถามเพิ่มเติม ติดต่อ (ระบุผู้ประสานงาน)

ขอขอบพระคุณค่ะ
TXT.self::SIGNATURE,
            ],

            // ── 4. D&O ───────────────────────────────────────────────────────
            [
                'label' => 'ขอราคา ประกัน D&O',
                'description' => 'จองงาน + ขอข้อเสนอประกันความรับผิดของกรรมการ',
                'icon' => 'pi pi-id-card',
                'department' => 'new_business',
                'subject' => '[{{carrierCode}}] จองงาน + ขอราคา ประกัน D&O // {{clientName}} // {{caseId}}',
                'body' => <<<TXT
เรียนเจ้าหน้าที่

จองงาน + ขอราคา ประกัน D&O (Directors & Officers Liability)
- บริษัท: {{clientName}}
- เลขทะเบียน: (ระบุ)
- ประเภทธุรกิจ: (ระบุ)
- งบการเงินล่าสุด: (ระบุปี)
- ประกันเดิม: (ระบุ / หรือ ยังไม่เคยมีประกัน)

เอกสารขอนำส่งให้อีกครั้งหลังจองงานได้ค่ะ

ขอขอบพระคุณค่ะ
TXT.self::SIGNATURE,
            ],

            // ── 5. Group ─────────────────────────────────────────────────────
            [
                'label' => 'ขอราคา ประกันกลุ่ม',
                'description' => 'จองงาน + ขอข้อเสนอประกันสุขภาพ/อุบัติเหตุ/ชีวิตกลุ่ม',
                'icon' => 'pi pi-users',
                'department' => 'new_business',
                'subject' => '[{{carrierCode}}] จองงาน + ขอราคา ประกันสุขภาพกลุ่ม // {{clientName}} // {{caseId}}',
                'body' => <<<TXT
เรียนทีมงาน

จองงาน + ขอราคาประกันกลุ่ม / สุขภาพกลุ่ม / อุบัติเหตุกลุ่ม
- บริษัท: {{clientName}}
- ที่อยู่: (ระบุ)
- ประเภทธุรกิจ: (ระบุ)
- จำนวนพนักงาน: (ระบุจำนวน)
- ประกันเดิม: (ระบุบริษัท + วันหมดอายุ)

แผนที่ขอข้อเสนอ:
- ค่ารักษาพยาบาล: (ระบุวงเงิน)
- เสียชีวิต: (ระบุวงเงิน)
- หรือ ขอข้อเสนอตามแผนเดิม (แนบหน้ากรมธรรม์)

ข้อมูลที่นำส่ง:
- รายชื่อพนักงาน / เพศ-อายุ-ตำแหน่ง
- ประวัติการเคลม
- หน้ากรมธรรม์เดิม

ขอขอบพระคุณค่ะ
TXT.self::SIGNATURE,
            ],
        ];
    }

    public function run(): void
    {
        $tenantId = Tenant::where('slug', 'insurehub')->value('id');
        if ($tenantId === null) {
            throw new \RuntimeException('TenantSeeder must run before EmailTemplateSeeder.');
        }

        $inserted = 0;
        foreach ($this->templates() as $tpl) {
            EmailTemplate::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'label' => $tpl['label'],
                    'is_built_in' => true,
                ],
                [
                    'description' => $tpl['description'],
                    'icon' => $tpl['icon'],
                    'department' => $tpl['department'],
                    'subject' => $tpl['subject'],
                    'body' => $tpl['body'],
                    'active' => true,
                ],
            );
            $inserted++;
        }
        $this->command?->info('  email_templates: inserted/updated '.$inserted);
    }
}
