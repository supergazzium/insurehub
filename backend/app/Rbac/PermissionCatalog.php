<?php

declare(strict_types=1);

namespace App\Rbac;

/**
 * The single source of truth for permission keys the app understands. A
 * permission only exists here if a controller (or middleware or Blade
 * template) actually checks it — otherwise it's dead data. The seeder
 * upserts this catalog into the `permissions` table on every deploy.
 *
 * Format per row: [key, module, name_th, name_en, description].
 *
 * Admins ticks/unticks these in the /admin/roles UI; they cannot create
 * new rows because there'd be no code enforcing them.
 */
final class PermissionCatalog
{
    /** @return list<array{key: string, module: string, name_th: string, name_en: string, description: string|null}> */
    public static function all(): array
    {
        return [
            // ── Agents ───────────────────────────────────────────────────
            ['key' => 'agents.view',     'module' => 'agents', 'name_th' => 'ดูรายชื่อตัวแทน',         'name_en' => 'View agents',       'description' => null],
            ['key' => 'agents.create',   'module' => 'agents', 'name_th' => 'เพิ่มตัวแทน',              'name_en' => 'Create agents',     'description' => null],
            ['key' => 'agents.update',   'module' => 'agents', 'name_th' => 'แก้ไขตัวแทน',              'name_en' => 'Update agents',     'description' => null],
            ['key' => 'agents.approve',  'module' => 'agents', 'name_th' => 'อนุมัติตัวแทน',            'name_en' => 'Approve agents',    'description' => null],
            ['key' => 'agents.reject',   'module' => 'agents', 'name_th' => 'ปฏิเสธตัวแทน',            'name_en' => 'Reject agents',     'description' => null],
            ['key' => 'agents.set_role', 'module' => 'agents', 'name_th' => 'กำหนดสิทธิ์ตัวแทน',        'name_en' => 'Assign agent role', 'description' => null],
            ['key' => 'agents.rank_approve', 'module' => 'agents', 'name_th' => 'อนุมัติเลื่อนระดับ', 'name_en' => 'Approve rank changes', 'description' => 'อนุมัติ/ไม่อนุมัติคำขอเลื่อนหรือปรับระดับตัวแทน'],

            // ── Customers ────────────────────────────────────────────────
            ['key' => 'customers.view',      'module' => 'customers', 'name_th' => 'ดูข้อมูลลูกค้า',              'name_en' => 'View customers',       'description' => null],
            ['key' => 'customers.create',    'module' => 'customers', 'name_th' => 'เพิ่มลูกค้า',                'name_en' => 'Create customers',     'description' => null],
            ['key' => 'customers.update',    'module' => 'customers', 'name_th' => 'แก้ไขลูกค้า',                'name_en' => 'Update customers',     'description' => null],
            ['key' => 'customers.view_pii',  'module' => 'customers', 'name_th' => 'ดูข้อมูลส่วนบุคคลของลูกค้า',   'name_en' => 'View customer PII',    'description' => 'Unmasks ID card, phone, and email fields.'],
            ['key' => 'customers.export',    'module' => 'customers', 'name_th' => 'ส่งออกข้อมูลลูกค้า',           'name_en' => 'Export customers',     'description' => null],
            ['key' => 'customers.delete',    'module' => 'customers', 'name_th' => 'ลบลูกค้า',                    'name_en' => 'Delete customers',     'description' => 'Only customers without policies can be deleted.'],

            // ── Policies ─────────────────────────────────────────────────
            ['key' => 'policies.view',    'module' => 'policies', 'name_th' => 'ดูกรมธรรม์',       'name_en' => 'View policies',    'description' => null],
            ['key' => 'policies.create',  'module' => 'policies', 'name_th' => 'สร้างกรมธรรม์',    'name_en' => 'Create policies',  'description' => null],
            ['key' => 'policies.update',  'module' => 'policies', 'name_th' => 'แก้ไขกรมธรรม์',    'name_en' => 'Update policies',  'description' => null],
            ['key' => 'policies.approve', 'module' => 'policies', 'name_th' => 'อนุมัติกรมธรรม์',  'name_en' => 'Approve policies', 'description' => null],
            ['key' => 'policies.cancel',  'module' => 'policies', 'name_th' => 'ยกเลิกกรมธรรม์',   'name_en' => 'Cancel policies',  'description' => null],
            ['key' => 'policies.verify',  'module' => 'policies', 'name_th' => 'ตรวจสอบ (Verify) กรมธรรม์', 'name_en' => 'Verify policy changes', 'description' => 'อนุมัติ/ไม่อนุมัติคำขอสร้าง แก้ไข ลบกรมธรรม์ที่ผู้อื่นคีย์'],

            // ── Quotations ───────────────────────────────────────────────
            ['key' => 'quotes.view',    'module' => 'quotes', 'name_th' => 'ดูใบเสนอราคา',       'name_en' => 'View quotes',    'description' => null],
            ['key' => 'quotes.create',  'module' => 'quotes', 'name_th' => 'สร้างใบเสนอราคา',    'name_en' => 'Create quotes',  'description' => null],
            ['key' => 'quotes.update',  'module' => 'quotes', 'name_th' => 'แก้ไขใบเสนอราคา',    'name_en' => 'Update quotes',  'description' => null],
            ['key' => 'quotes.approve', 'module' => 'quotes', 'name_th' => 'อนุมัติใบเสนอราคา',  'name_en' => 'Approve quotes', 'description' => null],

            // ── Commissions ──────────────────────────────────────────────
            ['key' => 'commissions.view',     'module' => 'commissions', 'name_th' => 'ดูค่าคอมมิชชัน',        'name_en' => 'View commissions',      'description' => null],
            ['key' => 'commissions.run',      'module' => 'commissions', 'name_th' => 'คำนวณค่าคอมมิชชัน',      'name_en' => 'Run commission cycle',  'description' => null],
            ['key' => 'commissions.approve',  'module' => 'commissions', 'name_th' => 'อนุมัติค่าคอมมิชชัน',    'name_en' => 'Approve commissions',   'description' => null],
            ['key' => 'commissions.override', 'module' => 'commissions', 'name_th' => 'ปรับแต่งค่าคอมมิชชัน',    'name_en' => 'Override commissions',  'description' => null],

            // ── Payouts ──────────────────────────────────────────────────
            ['key' => 'payouts.view',       'module' => 'payouts', 'name_th' => 'ดูรอบจ่ายเงิน',          'name_en' => 'View payouts',     'description' => null],
            ['key' => 'payouts.preview',    'module' => 'payouts', 'name_th' => 'ตรวจสอบก่อนจ่ายเงิน',    'name_en' => 'Preview payouts',  'description' => null],
            ['key' => 'payouts.approve',    'module' => 'payouts', 'name_th' => 'อนุมัติรอบจ่ายเงิน',     'name_en' => 'Approve payouts',  'description' => null],
            ['key' => 'payouts.mark_paid',  'module' => 'payouts', 'name_th' => 'ยืนยันการจ่ายเงินแล้ว',  'name_en' => 'Mark payout paid', 'description' => null],

            // ── Finance ──────────────────────────────────────────────────
            ['key' => 'finance.view_ledger', 'module' => 'finance', 'name_th' => 'ดูบัญชีแยกประเภท',    'name_en' => 'View ledger',       'description' => null],
            ['key' => 'finance.export',      'module' => 'finance', 'name_th' => 'ส่งออกข้อมูลการเงิน',  'name_en' => 'Export finance',    'description' => null],
            ['key' => 'finance.reconcile',   'module' => 'finance', 'name_th' => 'กระทบยอดการเงิน',      'name_en' => 'Reconcile finance', 'description' => null],

            ['key' => 'receipts.view',   'module' => 'receipts', 'name_th' => 'ดูการรับค่าคอมจากบริษัทประกัน',        'name_en' => 'View insurer commission receipts',   'description' => null],
            ['key' => 'receipts.record', 'module' => 'receipts', 'name_th' => 'บันทึกรับค่าคอมจากบริษัทประกัน',       'name_en' => 'Record insurer commission receipts', 'description' => null],
            ['key' => 'receipts.reopen', 'module' => 'receipts', 'name_th' => 'เปิดรายการรับค่าคอมที่รับแล้วกลับมาแก้', 'name_en' => 'Reopen received commission',         'description' => null],

            ['key' => 'payments.record', 'module' => 'payments', 'name_th' => 'บันทึกการชำระเบี้ยจากลูกค้า',       'name_en' => 'Record premium payments', 'description' => null],
            ['key' => 'payments.void',   'module' => 'payments', 'name_th' => 'ยกเลิกรายการชำระเบี้ยที่บันทึกผิด', 'name_en' => 'Void premium payments',   'description' => null],

            // ── Reports ──────────────────────────────────────────────────
            ['key' => 'reports.view_operational', 'module' => 'reports', 'name_th' => 'ดูรายงานปฏิบัติการ', 'name_en' => 'View operational reports', 'description' => null],
            ['key' => 'reports.view_financial',   'module' => 'reports', 'name_th' => 'ดูรายงานการเงิน',    'name_en' => 'View financial reports',   'description' => null],
            ['key' => 'reports.export',           'module' => 'reports', 'name_th' => 'ส่งออกรายงาน',       'name_en' => 'Export reports',           'description' => null],

            // ── Motor tariffs ────────────────────────────────────────────
            ['key' => 'motor_tariffs.view',   'module' => 'motor_tariffs', 'name_th' => 'ดูตารางเบี้ยประกันรถยนต์', 'name_en' => 'View motor tariffs',   'description' => null],
            ['key' => 'motor_tariffs.manage', 'module' => 'motor_tariffs', 'name_th' => 'จัดการตารางเบี้ยประกันรถยนต์', 'name_en' => 'Manage motor tariffs', 'description' => null],

            // ── Carriers / Products / Contracts ──────────────────────────
            ['key' => 'carriers.view',    'module' => 'carriers',  'name_th' => 'ดูบริษัทประกัน',       'name_en' => 'View carriers',    'description' => null],
            ['key' => 'carriers.manage',  'module' => 'carriers',  'name_th' => 'จัดการบริษัทประกัน',   'name_en' => 'Manage carriers',  'description' => null],
            ['key' => 'products.view',    'module' => 'products',  'name_th' => 'ดูผลิตภัณฑ์',           'name_en' => 'View products',    'description' => null],
            ['key' => 'products.manage',  'module' => 'products',  'name_th' => 'จัดการผลิตภัณฑ์',       'name_en' => 'Manage products',  'description' => null],
            ['key' => 'contracts.view',   'module' => 'contracts', 'name_th' => 'ดูสัญญา',               'name_en' => 'View contracts',   'description' => null],
            ['key' => 'contracts.manage', 'module' => 'contracts', 'name_th' => 'จัดการสัญญา',           'name_en' => 'Manage contracts', 'description' => null],

            // ── Admin (system-level) ─────────────────────────────────────
            ['key' => 'admin.users',     'module' => 'admin', 'name_th' => 'จัดการผู้ใช้งาน',       'name_en' => 'Manage users',       'description' => null],
            ['key' => 'admin.roles',     'module' => 'admin', 'name_th' => 'จัดการสิทธิ์และบทบาท',   'name_en' => 'Manage roles',       'description' => 'God-tier: whoever has this can grant themselves any other permission.'],
            ['key' => 'admin.settings',  'module' => 'admin', 'name_th' => 'จัดการตั้งค่าระบบ',      'name_en' => 'Manage settings',    'description' => null],
            ['key' => 'admin.audit_log', 'module' => 'admin', 'name_th' => 'ดูบันทึกการใช้งาน',      'name_en' => 'View audit log',     'description' => null],

            // ── Agent portal (used only by role=agent) ───────────────────
            ['key' => 'portal.view',           'module' => 'portal', 'name_th' => 'เข้าใช้งาน Portal',       'name_en' => 'Access portal',        'description' => null],
            ['key' => 'portal.update_profile', 'module' => 'portal', 'name_th' => 'แก้ไขโปรไฟล์ตัวเอง',     'name_en' => 'Update own profile',   'description' => null],

            // ── IT / systems (role=it dashboard + ops) ───────────────────
            ['key' => 'it.system_status',  'module' => 'it', 'name_th' => 'ดูสถานะระบบ',            'name_en' => 'View system status',    'description' => 'สถานะ IHOS ความพร้อมใช้งาน ข้อผิดพลาด เหตุขัดข้อง'],
            ['key' => 'it.tickets',        'module' => 'it', 'name_th' => 'จัดการ Ticket/Bug',       'name_en' => 'Manage tickets & bugs', 'description' => 'งานที่ผู้ใช้แจ้ง + bug + คำขอปรับปรุง'],
            ['key' => 'it.integrations',   'module' => 'it', 'name_th' => 'ดูสถานะ Integration',     'name_en' => 'View integrations',     'description' => 'API บริษัทประกัน การส่งข้อมูล งานอัตโนมัติที่ล้มเหลว'],
            ['key' => 'it.deploys',        'module' => 'it', 'name_th' => 'ดูประวัติ Deploy',        'name_en' => 'View deploy history',   'description' => 'เวอร์ชัน รายการเปลี่ยนแปลง ผลหลังขึ้นระบบ'],
            ['key' => 'it.security',       'module' => 'it', 'name_th' => 'ดูความปลอดภัย/สิทธิ์',      'name_en' => 'View security & access','description' => 'login ผิดปกติ บัญชีถูกล็อก คำขอสิทธิ์'],
            ['key' => 'it.data_quality',   'module' => 'it', 'name_th' => 'ดูคุณภาพข้อมูล',          'name_en' => 'View data quality',     'description' => 'รายการซ้ำ ข้อมูลขาด รายการประมวลผลไม่สำเร็จ'],
        ];
    }
}
