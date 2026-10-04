<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditEntry;
use App\Support\GenderFromTitle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCustomerGender extends Command
{
    protected $signature = 'customers:backfill-gender {--apply : Write the guessed values} {--tenant= : Only this tenant id}';

    protected $description = 'Guess missing customer gender from the Thai/English title prefix';

    public function handle(): int
    {
        $q = DB::table('customers')
            ->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('gender')->orWhereNotIn('gender', ['M', 'F']))
            ->when($this->option('tenant'), fn ($w, $t) => $w->where('tenant_id', $t))
            ->orderBy('id');

        $guessed = ['M' => [], 'F' => []];
        $unknown = [];
        $cleared = [];
        foreach ($q->get(['id', 'tenant_id', 'customer_type', 'gender', 'title_th', 'title_en']) as $r) {
            $g = GenderFromTitle::normalize($r->gender);
            if ($g === null && $r->customer_type !== 'corporate') {
                $g = GenderFromTitle::guess($r->title_th) ?? GenderFromTitle::guess($r->title_en);
            }
            if ($g === null) {
                if ($r->gender !== null && $r->gender !== '') {
                    $cleared[] = $r->id;
                }
                if ($r->customer_type === 'corporate') {
                    continue;
                }
                $title = trim((string) ($r->title_th ?: $r->title_en));
                $unknown[$title === '' ? '(ว่าง)' : $title] = ($unknown[$title === '' ? '(ว่าง)' : $title] ?? 0) + 1;
                continue;
            }
            $guessed[$g][] = $r;
        }

        $this->info(sprintf('%sชาย: %d  หญิง: %d  เดาไม่ได้: %d  ล้างค่าที่ไม่ถูกต้อง: %d',
            $this->option('apply') ? '' : '[dry-run] ',
            count($guessed['M']), count($guessed['F']), array_sum($unknown), count($cleared)));
        if ($unknown !== []) {
            arsort($unknown);
            $this->table(['คำนำหน้าที่เดาไม่ได้', 'จำนวน'], collect($unknown)->map(fn ($n, $t) => [$t, $n])->values()->all());
        }

        if (! $this->option('apply')) {
            $this->line('ใส่ --apply เพื่อบันทึกจริง');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($guessed, $cleared): void {
            foreach ($guessed as $gender => $rows) {
                foreach (array_chunk(array_map(fn ($r) => $r->id, $rows), 500) as $ids) {
                    DB::table('customers')->whereIn('id', $ids)->update(['gender' => $gender, 'updated_at' => now()]);
                }
            }
            foreach (array_chunk($cleared, 500) as $ids) {
                DB::table('customers')->whereIn('id', $ids)->update(['gender' => null, 'updated_at' => now()]);
            }
            $byTenant = [];
            foreach ($guessed as $gender => $rows) {
                foreach ($rows as $r) {
                    $byTenant[$r->tenant_id][$gender] = ($byTenant[$r->tenant_id][$gender] ?? 0) + 1;
                }
            }
            foreach ($byTenant as $tenantId => $counts) {
                AuditEntry::create([
                    'tenant_id' => $tenantId,
                    'occurred_at' => now(),
                    'actor' => 'system',
                    'action' => 'customer.gender_backfilled',
                    'target' => 'customers',
                    'result' => 'success',
                    'metadata' => $counts,
                ]);
            }
        });
        $this->info('บันทึกแล้ว');

        return self::SUCCESS;
    }
}
