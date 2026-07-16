<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scratch queue for legacy applications that couldn't be imported because a
        // referenced client/agent/product/company code did not resolve.
        // Populated by the `insurehub:import` command from `stg_application`
        // rows that were dropped in the 04_transform step (304 rows in the first run).
        Schema::create('applications_import_failures', function (Blueprint $table): void {
            $table->id();
            $table->string('application_code', 32)->index();
            $table->string('reason', 32); // missing_client | missing_agent | missing_product | missing_company | other
            $table->text('detail')->nullable();
            $table->longText('raw_json')->nullable(); // full source row for later triage
            $table->timestamp('imported_at')->useCurrent();
            $table->boolean('resolved')->default(false);
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['reason', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications_import_failures');
    }
};
