<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PolicyInstallment;
use App\Services\Policy\PremiumDue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CollectionsAuditPayments extends Command
{
    protected $signature = 'collections:audit-payments {--tenant= : จำกัดเฉพาะหน่วยงาน} {--csv= : บันทึกรายละเอียดทุกรายการเป็นไฟล์ CSV} {--limit=20 : จำนวนตัวอย่างที่แสดงต่อหมวด}';

    protected $description = 'ตรวจข้อมูลการชำระเบี้ยว่ามีการนับซ้ำหรือชำระเกินหรือไม่ (อ่านอย่างเดียว ไม่แก้ข้อมูล)';

    public function handle(): int
    {
        $tenant = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $limit = max(1, (int) $this->option('limit'));
        $findings = [
            'overpaid' => $this->overpaid($tenant),
            'duplicate_groups' => $this->duplicateGroups($tenant),
            'legacy_import_plus_real' => $this->legacyImportPlusReal($tenant),
            'legacy_import_on_web_policy' => $this->legacyImportOnWeb($tenant),
            'legacy_settled_no_payment' => $this->legacySettled($tenant),
            'web_unpaid_previously_hidden' => $this->webPreviouslyHidden($tenant),
        ];
        $labels = [
            'overpaid' => 'ยอดชำระรวมเกินเบี้ยที่ต้องชำระ',
            'duplicate_groups' => 'รายการชำระซ้ำ (กรมธรรม์ + วันที่ + ยอด + งวด เดียวกัน)',
            'legacy_import_plus_real' => 'มีรายการนำเข้าจากระบบเดิม และมีการชำระจริงเพิ่ม',
            'legacy_import_on_web_policy' => 'รายการนำเข้าจากระบบเดิมบนกรมธรรม์ที่สร้างในระบบใหม่',
            'legacy_settled_no_payment' => 'กรมธรรม์ระบบเดิมที่ไม่มีรายการชำระ (ถือว่าชำระครบ)',
            'web_unpaid_previously_hidden' => 'กรมธรรม์ระบบใหม่ที่ยังไม่ชำระ ซึ่งเคยถูกซ่อนจากหน้าติดตามเงิน',
        ];

        $this->info('ตรวจข้อมูลการชำระ '.($tenant !== null ? "หน่วยงาน {$tenant}" : 'ทุกหน่วยงาน').' — '.now()->format('d/m/Y H:i'));
        $this->table(['หมวด', 'รายละเอียด', 'จำนวน'], collect($findings)->map(fn ($rows, $key) => [$key, $labels[$key], count($rows)])->values()->all());

        foreach ($findings as $key => $rows) {
            if ($rows === [] || $key === 'legacy_settled_no_payment') {
                continue;
            }
            $this->line('');
            $this->line("<comment>{$labels[$key]}</comment> (แสดง ".min($limit, count($rows)).' จาก '.count($rows).')');
            $sample = array_slice($rows, 0, $limit);
            $this->table(array_keys($sample[0]), array_map('array_values', $sample));
        }

        $csv = $this->option('csv');
        if ($csv) {
            $fh = fopen($csv, 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, ['category', 'label', 'policy_id', 'policy_no', 'detail'], ',', '"', '');
            foreach ($findings as $key => $rows) {
                foreach ($rows as $r) {
                    fputcsv($fh, [$key, $labels[$key], $r['policy_id'] ?? '', $r['policy_no'] ?? '', json_encode($r, JSON_UNESCAPED_UNICODE)], ',', '"', '');
                }
            }
            fclose($fh);
            $this->info("บันทึกรายละเอียดที่ {$csv}");
        }

        return self::SUCCESS;
    }

    private function base(?int $tenant)
    {
        return DB::table('policies as p')
            ->whereNull('p.deleted_at')
            ->when($tenant !== null, fn ($q) => $q->where('p.tenant_id', $tenant));
    }

    private function overpaid(?int $tenant): array
    {
        $out = [];
        $this->base($tenant)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('policy_payments as pp')->whereColumn('pp.policy_id', 'p.id'))
            ->select([
                'p.id', 'p.policy_no', 'p.application_no', 'p.main_premium', 'p.compulsory_premium', 'p.total_premium_paid', 'p.imported_from_legacy',
                DB::raw('(SELECT SUM(pp.amount) FROM policy_payments pp WHERE pp.policy_id = p.id) as paid_total'),
                DB::raw('(SELECT COUNT(*) FROM policy_payments pp WHERE pp.policy_id = p.id) as paid_count'),
                DB::raw(PremiumDue::ENDORSEMENT_TOTAL_SQL.' as endorsement_total'),
            ])
            ->orderBy('p.id')
            ->chunk(500, function ($rows) use (&$out): void {
                $inst = PolicyInstallment::query()->whereIn('policy_id', $rows->pluck('id'))->get()->groupBy('policy_id');
                foreach ($rows as $r) {
                    $due = PremiumDue::due((float) $r->main_premium, (float) $r->compulsory_premium, (float) $r->total_premium_paid, $inst->get($r->id, collect()), (float) $r->endorsement_total);
                    $paid = round((float) $r->paid_total, 2);
                    if ($due > 0 && $paid - $due > 1.0) {
                        $out[] = [
                            'policy_id' => $r->id, 'policy_no' => $r->policy_no ?: $r->application_no,
                            'due' => $due, 'paid' => $paid, 'over' => round($paid - $due, 2), 'payments' => (int) $r->paid_count,
                            'legacy' => (bool) $r->imported_from_legacy ? 'yes' : 'no',
                        ];
                    }
                }
            });

        return $out;
    }

    private function duplicateGroups(?int $tenant): array
    {
        return DB::table('policy_payments as pp')
            ->join('policies as p', 'p.id', '=', 'pp.policy_id')
            ->whereNull('p.deleted_at')
            ->when($tenant !== null, fn ($q) => $q->where('p.tenant_id', $tenant))
            ->groupBy('pp.policy_id', 'p.policy_no', 'pp.payment_date', 'pp.amount', 'pp.policy_installment_id')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('pp.policy_id, p.policy_no, pp.payment_date, pp.amount, pp.policy_installment_id, COUNT(*) as n, GROUP_CONCAT(pp.id) as payment_ids')
            ->orderBy('pp.policy_id')
            ->get()
            ->map(fn ($r): array => [
                'policy_id' => $r->policy_id, 'policy_no' => $r->policy_no, 'payment_date' => $r->payment_date,
                'amount' => (float) $r->amount, 'installment_id' => $r->policy_installment_id, 'rows' => (int) $r->n, 'payment_ids' => $r->payment_ids,
            ])->all();
    }

    private function legacyImportPlusReal(?int $tenant): array
    {
        return $this->base($tenant)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('policy_payments as a')->whereColumn('a.policy_id', 'p.id')->where('a.method', 'legacyImport'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('policy_payments as b')->whereColumn('b.policy_id', 'p.id')->where('b.method', '!=', 'legacyImport'))
            ->select([
                'p.id', 'p.policy_no',
                DB::raw("(SELECT SUM(pp.amount) FROM policy_payments pp WHERE pp.policy_id = p.id AND pp.method = 'legacyImport') as legacy_amount"),
                DB::raw("(SELECT SUM(pp.amount) FROM policy_payments pp WHERE pp.policy_id = p.id AND pp.method <> 'legacyImport') as real_amount"),
            ])
            ->orderBy('p.id')
            ->get()
            ->map(fn ($r): array => ['policy_id' => $r->id, 'policy_no' => $r->policy_no, 'legacy_amount' => (float) $r->legacy_amount, 'real_amount' => (float) $r->real_amount])
            ->all();
    }

    private function legacyImportOnWeb(?int $tenant): array
    {
        return $this->base($tenant)
            ->where('p.imported_from_legacy', false)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('policy_payments as a')->whereColumn('a.policy_id', 'p.id')->where('a.method', 'legacyImport'))
            ->orderBy('p.id')
            ->get(['p.id', 'p.policy_no'])
            ->map(fn ($r): array => ['policy_id' => $r->id, 'policy_no' => $r->policy_no])
            ->all();
    }

    private function legacySettled(?int $tenant): array
    {
        return $this->base($tenant)
            ->where('p.imported_from_legacy', true)
            ->whereIn('p.status', ['active', 'issued'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('policy_payments as a')->whereColumn('a.policy_id', 'p.id'))
            ->orderBy('p.id')
            ->get(['p.id', 'p.policy_no'])
            ->map(fn ($r): array => ['policy_id' => $r->id, 'policy_no' => $r->policy_no])
            ->all();
    }

    private function webPreviouslyHidden(?int $tenant): array
    {
        return $this->base($tenant)
            ->where('p.imported_from_legacy', false)
            ->whereIn('p.status', ['active', 'issued'])
            ->where('p.total_premium_paid', '>', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('policy_payments as a')->whereColumn('a.policy_id', 'p.id'))
            ->orderBy('p.id')
            ->get(['p.id', 'p.policy_no', 'p.total_premium_paid'])
            ->map(fn ($r): array => ['policy_id' => $r->id, 'policy_no' => $r->policy_no, 'premium' => (float) $r->total_premium_paid])
            ->all();
    }
}
