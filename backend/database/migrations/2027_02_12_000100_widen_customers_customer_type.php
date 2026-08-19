<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The `customer_type` column is varchar(16), which was enough for
     * `individual` (10) and `corporate` (9). Adding `foreign_individual`
     * (18) blows past that. Widen the column to varchar(32) so the
     * literal fits with headroom for future values (`recovery_agent`,
     * `government`, etc.) without another migration.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE customers MODIFY customer_type VARCHAR(32) NOT NULL DEFAULT 'individual'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE customers MODIFY customer_type VARCHAR(16) NOT NULL DEFAULT 'individual'");
    }
};
