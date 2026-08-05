<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds product-kind-specific context fields to `policies`:
 *   - Travel: trip destination, trip start/end, traveler count, passport
 *   - Life / Health: insured person (name / ID / birth date),
 *                    sum assured, premium-paying term
 *   - Health only: single-beneficiary pair
 *   - Life / Health: free-text health declaration
 *
 * All columns are nullable — a motor policy never fills these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $t): void {
            // Travel block.
            $t->string('trip_destination', 128)->nullable()->after('property_notes');
            $t->date('trip_start')->nullable()->after('trip_destination');
            $t->date('trip_end')->nullable()->after('trip_start');
            $t->unsignedSmallInteger('traveler_count')->nullable()->after('trip_end');
            $t->string('traveler_passport', 32)->nullable()->after('traveler_count');

            // Life / Health insured person (defaults to policyholder but
            // can differ — e.g. parent buying health policy for child).
            $t->string('insured_person_name', 255)->nullable()->after('traveler_passport');
            $t->string('insured_person_id_card', 32)->nullable()->after('insured_person_name');
            $t->date('insured_person_birth_date')->nullable()->after('insured_person_id_card');

            // Sum assured — separate from `coverage` because that column
            // is used loosely across product kinds; sum_assured is the
            // life-specific "how much is paid on claim" figure.
            $t->decimal('sum_assured', 15, 2)->nullable()->after('insured_person_birth_date');

            // Premium-paying term in years. Nullable = single-pay / n/a.
            $t->unsignedSmallInteger('premium_paying_term')->nullable()->after('sum_assured');

            // Free-text health declaration summary (yes/no + notes).
            $t->text('health_declaration')->nullable()->after('premium_paying_term');

            // Health-only single beneficiary (life keeps the multi-row
            // policy_beneficiaries table).
            $t->string('health_beneficiary_name', 255)->nullable()->after('health_declaration');
            $t->string('health_beneficiary_relation', 64)->nullable()->after('health_beneficiary_name');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $t): void {
            $t->dropColumn([
                'trip_destination', 'trip_start', 'trip_end',
                'traveler_count', 'traveler_passport',
                'insured_person_name', 'insured_person_id_card', 'insured_person_birth_date',
                'sum_assured', 'premium_paying_term', 'health_declaration',
                'health_beneficiary_name', 'health_beneficiary_relation',
            ]);
        });
    }
};
