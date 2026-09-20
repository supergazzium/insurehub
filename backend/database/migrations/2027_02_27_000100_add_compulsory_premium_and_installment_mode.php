<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installment engine (per Insurehub_Installment_Calculation_Spec):
 *   - compulsory_premium (พ.ร.บ.) — billed IN FULL on the first งวด only,
 *     tracked separately from main_premium.
 *   - installment_mode — A / B / C, deciding who bears the contract fee and
 *     the interest (customer vs agent). Null until the operator picks one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->decimal('compulsory_premium', 15, 2)->default(0)->after('vat');
            $table->string('installment_mode', 1)->nullable()->after('premium_paying_term');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn(['compulsory_premium', 'installment_mode']);
        });
    }
};
