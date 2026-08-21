<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C-3 — additive `kind` + `risk_schema` on product_types.
 *
 * See docs/audit-2026-08-21/B2-schema-plan.md §1 and
 * docs/audit-2026-08-21/04-product-schema.md §5 for the 26-row mapping.
 *
 * `kind` is a fixed enum-ish string that groups the 26 product_types
 * into 6 wizard branches: motor / travel / fire / health / life / misc.
 * The value drives the wizard's Step 3 dynamic risk renderer AND the
 * writer/reader shim on policies.risk_data (introduced in C-4).
 *
 * `risk_schema` is nullable JSON. Stays NULL after this migration —
 * schema authoring happens in C-9/C-10 via the AdminProductTypes UI
 * plus a seeder. Nullable so a new tenant can boot without the schema
 * pre-populated.
 *
 * Zero breaking risk: both columns nullable, no code reads them yet.
 * The ProductKind::derive() runtime helper stays as fallback until
 * every taxonomy row has kind populated (see C-3 backfill below).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_types', function (Blueprint $t): void {
            $t->string('kind', 16)->nullable()->after('sub_of');
            $t->json('risk_schema')->nullable()->after('kind');
        });

        // Backfill kind for every seeded product_type. Source: 04-product-schema.md §5.
        // Rows the tenant hasn't seeded (custom types) stay NULL — the wizard
        // then falls back to ProductKind::derive() for those.
        $kindByCode = [
            // Motor (6) + Compulsory motor / พรบ (2) → 'motor'
            'MOTOR_CLASS1_GARAGE' => 'motor',
            'MOTOR_CLASS1_DEALER' => 'motor',
            'MOTOR_CLASS23'       => 'motor',
            'MOTOR_HEAVY_GARAGE'  => 'motor',
            'MOTOR_HEAVY_DEALER'  => 'motor',
            'MOTOR_HEAVY_CLASS23' => 'motor',
            'PORROR_CAR'          => 'motor',
            'PORROR_OTHER'        => 'motor',
            // Travel (1)
            'TA_INDIVIDUAL' => 'travel',
            // Fire (4) + Property IAR/CAR/EAR (1) → 'fire'
            'FIRE_HOUSE_BASIC'   => 'fire',
            'FIRE_SME_BASIC'     => 'fire',
            'FIRE_HOUSE_PACKAGE' => 'fire',
            'FIRE_SME_PACKAGE'   => 'fire',
            'IAR_CAR_EAR'        => 'fire',
            // Misc — no dedicated risk block
            'MARINE'         => 'misc',
            'PA_INDIVIDUAL'  => 'misc',
            'MISC'           => 'misc',
            // Health (2) + Group Accident + Group Health → 'health'
            'HEALTH_ADULT'   => 'health',
            'HEALTH_CHILD'   => 'health',
            'GROUP_ACCIDENT' => 'health',
            'GROUP_HEALTH'   => 'health',
            // Life (5)
            'WHOLE_LIFE_STANDARD' => 'life',
            'ENDOWMENT_STANDARD'  => 'life',
            'ANNUITY'             => 'life',
            'TERM'                => 'life',
            'LIFE_RIDER'          => 'life',
        ];

        foreach ($kindByCode as $code => $kind) {
            DB::table('product_types')
                ->where('code', $code)
                ->update(['kind' => $kind]);
        }
    }

    public function down(): void
    {
        Schema::table('product_types', function (Blueprint $t): void {
            $t->dropColumn(['kind', 'risk_schema']);
        });
    }
};
