<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('policies as p')
            ->leftJoin('policy_rebates as r', 'r.policy_id', '=', 'p.id')
            ->where('p.imported_from_legacy', true)
            ->where('p.com_rec_check', 'Complete')
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.carrier_id')
            ->select(['p.id', 'p.tenant_id', 'p.carrier_id', 'p.policy_year', 'p.comm_carrier_to_hub_amount', 'r.calculated_amount'])
            ->orderBy('p.id')
            ->chunk(500, function ($policies) use ($now): void {
                foreach ($policies as $p) {
                    $expected = round((float) ($p->comm_carrier_to_hub_amount ?: $p->calculated_amount ?: 0), 2);
                    $existing = DB::table('commission_receivables')
                        ->where('policy_id', $p->id)
                        ->where('commission_type', 'MAIN')
                        ->where('line_no', 0)
                        ->first();

                    $values = [
                        'status' => 'Received',
                        'received_amount' => $expected,
                        'received_date' => null,
                        'source' => 'legacy',
                        'note' => 'รับแล้วในระบบเดิม',
                        'updated_at' => $now,
                    ];

                    if ($existing === null) {
                        $id = DB::table('commission_receivables')->insertGetId($values + [
                            'tenant_id' => $p->tenant_id,
                            'policy_id' => $p->id,
                            'insurer_id' => $p->carrier_id,
                            'policy_year' => $p->policy_year,
                            'commission_type' => 'MAIN',
                            'line_no' => 0,
                            'expected_amount' => $expected,
                            'version' => 0,
                            'created_at' => $now,
                        ]);
                    } elseif ($existing->status === 'Pending' && $existing->statement_amount === null) {
                        $id = $existing->id;
                        DB::table('commission_receivables')->where('id', $id)->update($values + [
                            'received_amount' => (float) $existing->expected_amount,
                            'version' => (int) $existing->version + 1,
                        ]);
                    } else {
                        continue;
                    }

                    DB::table('audit_entries')->insert([
                        'tenant_id' => $p->tenant_id,
                        'user_id' => null,
                        'occurred_at' => $now,
                        'actor' => 'system',
                        'action' => 'receivable.legacy_received',
                        'target' => "receivable:{$id}",
                        'result' => 'success',
                        'metadata' => json_encode(['old' => ['status' => $existing->status ?? null], 'new' => ['status' => 'Received', 'source' => 'legacy'], 'reason' => 'Com Rec Check = Complete ในระบบเดิม'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('commission_receivables')->where('source', 'legacy')->delete();
    }
};
