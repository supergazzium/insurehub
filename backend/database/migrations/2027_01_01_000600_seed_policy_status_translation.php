<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seeds `policy_statuses` with rows for every value of the frontend
     * `PolicyStatus` union, plus a `policy_status_translations` mapping table
     * that lets the importer collapse many legacy labels into a single row.
     *
     * The importer looks up `policy_status_translations.legacy_label` (from
     * `insurehub_legacy.lu_policy_status.label`) to resolve the target
     * `policy_statuses.id` — which then supplies:
     *   - `policies.legacy_policy_status_id` (FK)
     *   - `policies.status` (frontend union value, copied from `code`)
     *
     * Design rationale:
     *   - `policy_statuses.code` has a UNIQUE index (see 2026_06_30_000200)
     *     so we cannot store two rows with the same code (e.g. Thai "Cancel"
     *     + English "Reject" both map to `cancelled`).
     *   - The translation table is a clean many-to-one mapping. Additional
     *     legacy labels can be added later without touching `policy_statuses`.
     */
    public function up(): void
    {
        Schema::create('policy_status_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('legacy_label')->unique();
            $table->foreignId('policy_status_id')->constrained('policy_statuses')->cascadeOnDelete();
            $table->timestamps();
        });

        $now = now();

        // One row per PolicyStatus union value (frontend/stores/policies.ts).
        // name_th is the primary Thai display label; secondary Thai labels
        // are captured via policy_status_translations below.
        $statuses = [
            ['code' => 'quote',       'name_th' => 'ใบเสนอราคา',       'group_name_th' => 'Draft'],
            ['code' => 'application', 'name_th' => 'รอตรวจรถ',           'group_name_th' => 'Pending'],
            ['code' => 'submitted',   'name_th' => 'รอพิจารณา',          'group_name_th' => 'Pending'],
            ['code' => 'issued',      'name_th' => 'ออกกรมธรรม์แล้ว',   'group_name_th' => 'Issued'],
            ['code' => 'active',      'name_th' => 'อนุมัติแล้ว',         'group_name_th' => 'Approved'],
            ['code' => 'lapsed',      'name_th' => 'ขาดต่ออายุ',         'group_name_th' => 'Lapsed'],
            ['code' => 'cancelled',   'name_th' => 'Cancel',              'group_name_th' => 'Cancelled'],
            ['code' => 'reinstated',  'name_th' => 'กลับมาคุ้มครองใหม่',  'group_name_th' => 'Reinstated'],
            ['code' => 'expired',     'name_th' => 'หมดอายุ',             'group_name_th' => 'Expired'],
        ];

        foreach ($statuses as $s) {
            DB::table('policy_statuses')->updateOrInsert(
                ['code' => $s['code']],
                [
                    'name_th' => $s['name_th'],
                    'group_name_th' => $s['group_name_th'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        // Resolve the codes → ids for the translation table.
        $idByCode = DB::table('policy_statuses')->pluck('id', 'code')->all();

        $translations = [
            // Legacy label from insurehub_legacy.lu_policy_status → policy_statuses.code
            'อนุมัติแล้ว' => 'active',
            'Cancel'      => 'cancelled',
            'Reject'      => 'cancelled',
            'รอพิจารณา'    => 'submitted',
            'รอตรวจรถ'      => 'application',
        ];

        foreach ($translations as $legacy => $code) {
            if (!isset($idByCode[$code])) {
                continue;
            }
            DB::table('policy_status_translations')->updateOrInsert(
                ['legacy_label' => $legacy],
                [
                    'policy_status_id' => $idByCode[$code],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_status_translations');

        DB::table('policy_statuses')
            ->whereIn('code', [
                'quote', 'application', 'submitted', 'issued',
                'active', 'lapsed', 'cancelled', 'reinstated', 'expired',
            ])
            ->delete();
    }
};
