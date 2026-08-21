<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase C-1 — additive rollout of the 7-state policy lifecycle.
 *
 * See docs/audit-2026-08-21/B1-state-machine.md §1 for the target enum and
 * §9 PR-1 for the rollout order.
 *
 * This migration ONLY inserts the new codes and refreshes the Thai display
 * label + group_name_th on the codes we're keeping. It does NOT delete
 * anything. `reinstated` stays in the table because the field will retire
 * only after the state-machine backfill (C-2) has migrated any live rows
 * that referenced it — and the 515-row seed has zero `reinstated` rows so
 * cleanup is trivial once C-2 lands.
 *
 * Coexistence guarantee: after this migration runs, `policy_statuses` has
 * both the old 9 codes and the 4 new ones (13 total). Readers of the
 * legacy set keep working; writers of the new set get their rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // The 10 target codes from B1 §1. Any code already present is
        // updateOrInsert-ed to refresh name_th/group_name_th so the
        // seed_policy_status_translation migration's incorrect labels
        // (e.g. `application → รอตรวจรถ`, `active → อนุมัติแล้ว`) are
        // corrected in place.
        $statuses = [
            ['code' => 'draft',      'name_th' => 'ฉบับร่าง',                'group_name_th' => 'Pre-quote'],
            ['code' => 'quotation',  'name_th' => 'ใบเสนอราคา',              'group_name_th' => 'Pre-application'],
            ['code' => 'submitted',  'name_th' => 'ส่งพิจารณา',              'group_name_th' => 'Pending'],
            ['code' => 'approved',   'name_th' => 'อนุมัติ (รอเลขกรมธรรม์)', 'group_name_th' => 'Post-underwriting'],
            ['code' => 'issued',     'name_th' => 'ออกกรมธรรม์แล้ว',         'group_name_th' => 'Post-issue'],
            ['code' => 'active',     'name_th' => 'คุ้มครองอยู่',            'group_name_th' => 'In-force'],
            ['code' => 'expired',    'name_th' => 'หมดอายุ',                 'group_name_th' => 'Ended'],
            ['code' => 'cancelled',  'name_th' => 'ยกเลิก',                  'group_name_th' => 'Ended'],
            ['code' => 'rejected',   'name_th' => 'ถูกปฏิเสธ',               'group_name_th' => 'Ended'],
            ['code' => 'lapsed',     'name_th' => 'ขาดต่ออายุ',              'group_name_th' => 'Ended'],
        ];

        foreach ($statuses as $s) {
            DB::table('policy_statuses')->updateOrInsert(
                ['code' => $s['code']],
                [
                    'name_th' => $s['name_th'],
                    'group_name_th' => $s['group_name_th'],
                    'updated_at' => $now,
                    // Only set created_at when inserting; updateOrInsert doesn't
                    // guard against this so we set it and let it be a no-op for
                    // existing rows via a subsequent update.
                    'created_at' => $now,
                ],
            );
        }

        // Preserve `quote`, `application`, `reinstated` rows for the shim
        // window. C-2 backfill will rewrite `policies.status` values that
        // still carry those codes; C-20 removes the retired rows.
    }

    public function down(): void
    {
        // Only remove codes this migration introduced. Do NOT drop
        // shared codes (submitted, issued, active, expired, cancelled,
        // lapsed) — they were inserted by seed_policy_status_translation
        // and dropping them here would break the ancestor migration's
        // down() invariant.
        DB::table('policy_statuses')
            ->whereIn('code', ['draft', 'quotation', 'approved', 'rejected'])
            ->delete();

        // Restore the ancestor migration's labels for codes we edited.
        // Values sourced from 2027_01_01_000600_seed_policy_status_translation.
        $restore = [
            'submitted' => ['name_th' => 'รอพิจารณา',      'group_name_th' => 'Pending'],
            'issued'    => ['name_th' => 'ออกกรมธรรม์แล้ว', 'group_name_th' => 'Issued'],
            'active'    => ['name_th' => 'อนุมัติแล้ว',      'group_name_th' => 'Approved'],
            'expired'   => ['name_th' => 'หมดอายุ',          'group_name_th' => 'Expired'],
            'cancelled' => ['name_th' => 'Cancel',           'group_name_th' => 'Cancelled'],
            'lapsed'    => ['name_th' => 'ขาดต่ออายุ',      'group_name_th' => 'Lapsed'],
        ];
        $now = now();
        foreach ($restore as $code => $vals) {
            DB::table('policy_statuses')
                ->where('code', $code)
                ->update([
                    'name_th' => $vals['name_th'],
                    'group_name_th' => $vals['group_name_th'],
                    'updated_at' => $now,
                ]);
        }
    }
};
