<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-(agent, YYYY-MM) volume accumulator.
     *
     * Personal volume: sum of `policy_payments.amount` where the payment's
     *   policy.writing_agent_id = this agent, bucketed by payment_date's
     *   YYYY-MM.
     *
     * Team volume: personal + sum of personal_sales_volume from every
     *   DIRECT AND TRANSITIVE downline whose current rank_level is STRICTLY
     *   LOWER than this agent's current rank_level (per Excel Sheet2 rule 3:
     *   "การนับผลงาน เพื่อปรับตำแหน่ง นับจากสายงานที่มีระดับต่ำกว่าสายงานนั้น").
     *
     *   IMPORTANT: this is "current rank", not "rank at time of sale". If a
     *   downline is promoted mid-month past their upline, they stop counting
     *   for the upline's team volume immediately. That's why the volume
     *   observer recomputes affected uplines on promotion, and the nightly
     *   reconciliation exists as a safety net.
     *
     * Rolling 3-month volume: team_sales_volume for this month plus the
     *   two prior months. Recomputed on every write to this row and on
     *   any upline recompute — cheap because it's three-row sum.
     *
     * This table is derived data; nightly reconciliation (artisan
     * `mgm:reconcile-volumes`) rebuilds it from the source truth (policies
     * + payments + agents.rank_id). Fast to rebuild for a single month;
     * O(payments × depth) for a full backfill.
     */
    public function up(): void
    {
        Schema::create('member_volume_accumulations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            // YYYY-MM. String rather than date for exact partition semantics.
            $table->string('period_year_month', 7);
            $table->decimal('personal_sales_volume', 15, 2)->default(0);
            $table->decimal('team_sales_volume', 15, 2)->default(0);
            $table->decimal('rolling_3_month_volume', 15, 2)->default(0);
            // When the observer / reconciliation last touched this row.
            // Used to detect stale rows if a payment was recorded but the
            // observer didn't fire (crash mid-transaction, etc.).
            $table->timestamp('recomputed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'agent_id', 'period_year_month'], 'mva_tenant_agent_period_unique');
            $table->index(['tenant_id', 'period_year_month'], 'mva_tenant_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_volume_accumulations');
    }
};
