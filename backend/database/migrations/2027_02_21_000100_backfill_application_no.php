<?php

declare(strict_types=1);

use App\Support\PolicyNumbering;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Backfill application_no on every policy that is missing one, so each policy
 * has an explicit auto-run number in the canonical A<YYMMDD><4-digit-daily>
 * format (e.g. A2608170001, A2608170002, …) — the same format
 * PolicyNumbering::nextApplicationNo mints for new policies.
 *
 * Anchor date per policy (first non-null): app_date → effective_date →
 * create_date → created_at. The serial is allocated via the shared
 * PolicyNumbering allocator, so it continues any existing daily sequence for
 * that date instead of colliding. Rows are processed oldest-first (by id) for
 * a deterministic ordering. Idempotent — only rows with a NULL/empty
 * application_no are touched, so re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('policies')
            ->select('id', 'tenant_id', 'app_date', 'effective_date', 'create_date', 'created_at')
            ->whereNull('deleted_at')
            ->where(function ($q): void {
                $q->whereNull('application_no')->orWhere('application_no', '');
            })
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $anchor = $row->app_date
                ?? $row->effective_date
                ?? $row->create_date
                ?? $row->created_at;

            $date = $anchor ? Carbon::parse($anchor) : Carbon::now();
            $appNo = PolicyNumbering::nextApplicationNo((int) $row->tenant_id, $date);

            $update = ['application_no' => $appNo, 'updated_at' => now()];
            // If the policy has no app_date, stamp it with the anchor so the
            // number's date and the record agree.
            if ($row->app_date === null) {
                $update['app_date'] = $date->toDateString();
            }

            DB::table('policies')->where('id', $row->id)->update($update);
        }
    }

    public function down(): void
    {
        // No safe automatic rollback: we cannot distinguish backfilled numbers
        // from ones the app minted afterwards. Intentionally a no-op.
    }
};
