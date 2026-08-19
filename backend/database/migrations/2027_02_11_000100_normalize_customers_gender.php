<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalize customers.gender to single-letter codes 'M' / 'F'.
     *
     * The column has accumulated a mix of values over migrations: `male`,
     * `female`, `other`, `ชาย`, `หญิง`, plus a stray null. The new
     * customer form only accepts M or F; standardizing storage now so the
     * request validator can enforce a strict `in:M,F` rule and the UI can
     * render Thai labels from a single source of truth.
     *
     * `other` values and anything unrecognized are set to NULL — the
     * business rule says the create form only offers ชาย / หญิง, so
     * lingering `other` rows lose fidelity but never appear as a picked
     * option in the new UI anyway.
     */
    public function up(): void
    {
        DB::update("UPDATE customers SET gender='M' WHERE gender IN ('M','m','male','Male','ชาย')");
        DB::update("UPDATE customers SET gender='F' WHERE gender IN ('F','f','female','Female','หญิง')");
        DB::update("UPDATE customers SET gender=NULL WHERE gender IS NOT NULL AND gender NOT IN ('M','F')");
    }

    public function down(): void
    {
        // No-op — the migration is a data cleanup; reversing to arbitrary
        // legacy values (male / female / other) would be a fabrication.
    }
};
