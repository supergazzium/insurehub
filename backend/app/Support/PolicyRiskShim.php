<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Phase C-4 shim between the retired top-level risk-* columns on
 * `policies` and the new `policies.risk_data` JSON column.
 *
 * Ground truth: docs/audit-2026-08-21/B2-schema-plan.md §3.
 *
 * Lifetime: this class exists ONLY for the shim window between C-4
 * (JSON added, dual-write starts) and C-18 (retired columns dropped).
 * After C-18 the reader helper's column-fallback branch and the writer
 * helper's top-level assignment can be deleted; every remaining call
 * degrades to a bare `data_get` on `risk_data`.
 *
 * Two operations:
 *
 *   - writerDualWrite($kind, $inputRow, $existingRisk): produces the
 *     payload merged into the row Update / Create. Returns the row
 *     with BOTH the top-level column keys AND `risk_data` merged from
 *     the same input.
 *
 *   - readerField($policy, $kind, $key, $column): returns the value,
 *     preferring risk_data, falling back to the top-level column, and
 *     logging a "fallback" line so ops can prove risk_data is
 *     authoritative before the drop migration runs.
 *
 * The shape map lives here so the field lists don't drift between
 * writer and reader.
 */
class PolicyRiskShim
{
    /**
     * @var array<string, array<string, string>> [kind => [risk_data key => top_level column]]
     *
     * Every entry means "when the writer sees this key in input, write
     * both the column AND risk_data[kind][key]"; and "when the reader
     * asks for this key, prefer risk_data[kind][key] over the column".
     *
     * Motor `license_no`, `vehicle_brand`, `vehicle_model` intentionally
     * NOT in this map — they stay top-level forever per B2 §3 (list-column
     * hotspots + search index).
     */
    public const FIELDS = [
        'motor' => [
            'type_driver' => 'motor_type_driver',
            'type_vehicle' => 'motor_type_vehicle',
            'engine_no' => 'motor_engine_no',
            'chassis_no' => 'motor_chassis_no',
            'register_year' => 'motor_register_year',
            'no_passenger' => 'motor_no_passenger',
            'notes' => 'motor_notes',
        ],
        // Fire + misc share the same 3-section property/contact/coverage
        // shape post-C-20 (§1 contact, §2 sum insured, §3 notes). Column
        // names keep their legacy `property_` prefix — renaming columns
        // is out of scope. Legacy risk_data keys (insured_name, phone,
        // etc.) stay in the map so pre-C-19 rows still route correctly
        // on read; the canonical keys (contact_name, contact_phone,
        // property_address) alias to the same columns.
        'fire' => [
            'contact_name' => 'property_insured_name',
            'contact_phone' => 'property_phone',
            'property_address' => 'property_insured_address',
            'building_cov' => 'property_building_cov',
            'furniture_cov' => 'property_furniture_cov',
            'stock_cov' => 'property_stock_cov',
            'other_cov' => 'property_other_cov',
            'notes' => 'property_notes',
            // Legacy pre-C-19 keys — read-side compat only.
            'insured_name' => 'property_insured_name',
            'insured_address' => 'property_insured_address',
            'phone' => 'property_phone',
            'other_detail' => 'property_other_detail',
        ],
        'misc' => [
            'contact_name' => 'property_insured_name',
            'contact_phone' => 'property_phone',
            'property_address' => 'property_insured_address',
            'building_cov' => 'property_building_cov',
            'furniture_cov' => 'property_furniture_cov',
            'stock_cov' => 'property_stock_cov',
            'other_cov' => 'property_other_cov',
            'notes' => 'property_notes',
        ],
        'travel' => [
            'destination' => 'trip_destination',
            'start' => 'trip_start',
            'end' => 'trip_end',
            'traveler_count' => 'traveler_count',
            'traveler_passport' => 'traveler_passport',
        ],
        'life' => [
            'insured_person_name' => 'insured_person_name',
            'insured_person_id_card' => 'insured_person_id_card',
            'insured_person_birth_date' => 'insured_person_birth_date',
            'sum_assured' => 'sum_assured',
            'premium_paying_term' => 'premium_paying_term',
            'health_declaration' => 'health_declaration',
            'health_beneficiary_name' => 'health_beneficiary_name',
            'health_beneficiary_relation' => 'health_beneficiary_relation',
        ],
        // Health shares every field with life at the column level (the
        // legacy schema doesn't split them). The two entries exist so a
        // health product writes risk_data.health.* and a life product
        // writes risk_data.life.*, keeping semantic clarity in JSON
        // even though the column write is identical.
        'health' => [
            'insured_person_name' => 'insured_person_name',
            'insured_person_id_card' => 'insured_person_id_card',
            'insured_person_birth_date' => 'insured_person_birth_date',
            'sum_assured' => 'sum_assured',
            'premium_paying_term' => 'premium_paying_term',
            'health_declaration' => 'health_declaration',
            'health_beneficiary_name' => 'health_beneficiary_name',
            'health_beneficiary_relation' => 'health_beneficiary_relation',
        ],
    ];

    /**
     * The runtime helper `ProductKind::derive()` uses a slightly different
     * vocabulary than product_types.kind (`property` vs `fire`, `other`
     * vs `misc`). Both are valid inputs to the shim; this alias table
     * normalizes any caller onto the canonical kind used by FIELDS.
     */
    private const KIND_ALIASES = [
        'property' => 'fire',   // ProductKind::derive returns 'property' for อัคคีภัย
        'other' => 'misc',      // ProductKind::derive returns 'other' for unknown
    ];

    /**
     * Normalize a caller-supplied kind onto the shim's canonical vocabulary.
     * Idempotent: unknown kinds pass through unchanged.
     */
    public static function canonicalKind(string $kind): string
    {
        return self::KIND_ALIASES[$kind] ?? $kind;
    }

    /**
     * Returns the (short) list of kinds the shim knows about.
     *
     * @return list<string>
     */
    public static function knownKinds(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * Dual-write: given a kind and an input payload keyed by risk_data
     * shape (`type_driver`, `chassis_no`, ...), returns a flat array
     * ready to merge into a Policy row's update payload. Every field
     * lands in BOTH its top-level column AND `risk_data[kind][key]`.
     *
     * Non-mapped keys pass through as top-level columns (so unknown
     * risk fields don't silently disappear). The caller is responsible
     * for passing $existingRiskData so unrelated kinds' values survive
     * a partial update.
     *
     * @param  string                         $kind         one of self::knownKinds()
     * @param  array<string, mixed>           $inputByRiskKey  keys as they appear in risk_data
     * @param  array<string, array<string, mixed>>|null $existingRiskData  from Policy->risk_data
     * @return array{columns: array<string,mixed>, risk_data: array<string,array<string,mixed>>}
     */
    public static function writerDualWrite(string $kind, array $inputByRiskKey, ?array $existingRiskData = null): array
    {
        $kind = self::canonicalKind($kind);
        $fieldMap = self::FIELDS[$kind] ?? [];
        $columns = [];
        $riskData = $existingRiskData ?? [];
        $riskData[$kind] = $riskData[$kind] ?? [];

        foreach ($inputByRiskKey as $key => $value) {
            $riskData[$kind][$key] = $value;
            $col = $fieldMap[$key] ?? null;
            if ($col !== null) {
                $columns[$col] = $value;
            }
        }

        return ['columns' => $columns, 'risk_data' => $riskData];
    }

    /**
     * Reader: prefer risk_data value, fall back to top-level column,
     * log the fallback to the `risk_shim` channel so ops can prove
     * risk_data is authoritative before the drop migration runs.
     *
     * Callers pass the model instance and the exact top-level column
     * name so this helper stays free of magic string mapping —
     * clearer at the call site + easier to grep out during the C-18
     * cleanup.
     *
     * @param  object $policy  a Policy model (or stdClass row from the list query)
     */
    public static function readerField(object $policy, string $kind, string $key, ?string $legacyColumn = null): mixed
    {
        $kind = self::canonicalKind($kind);
        $risk = $policy->risk_data ?? null;
        if (is_array($risk) && isset($risk[$kind]) && array_key_exists($key, $risk[$kind])) {
            return $risk[$kind][$key];
        }

        if ($legacyColumn !== null && isset($policy->{$legacyColumn}) && $policy->{$legacyColumn} !== null) {
            Log::channel('risk_shim')->info('risk_data fallback', [
                'policy_id' => $policy->id ?? null,
                'kind' => $kind,
                'key' => $key,
                'column' => $legacyColumn,
            ]);

            return $policy->{$legacyColumn};
        }

        return null;
    }

    /**
     * Convenience for PolicyResource: reads every field under a kind
     * from the shim, returning a fully populated assoc array ready to
     * emit as JSON. Fields with no value on either side are omitted
     * so downstream consumers don't have to check for null-vs-missing.
     *
     * @return array<string, mixed>
     */
    public static function readerAll(object $policy, string $kind): array
    {
        $kind = self::canonicalKind($kind);
        $out = [];
        foreach (self::FIELDS[$kind] ?? [] as $key => $col) {
            $val = self::readerField($policy, $kind, $key, $col);
            if ($val !== null) {
                $out[$key] = $val;
            }
        }

        return $out;
    }
}
