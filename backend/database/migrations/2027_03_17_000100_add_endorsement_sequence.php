<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('endorsement_count')->default(0)->after('policy_year');
        });

        Schema::table('policy_events', function (Blueprint $table): void {
            $table->unsignedSmallInteger('endorsement_seq')->nullable()->after('type');
            $table->timestamp('voided_at')->nullable()->after('payload');
            $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by_user_id');
            $table->unique(['policy_id', 'endorsement_seq']);
        });

        $policyIds = DB::table('policy_events')
            ->where('type', 'like', 'endorsement.%')
            ->distinct()
            ->pluck('policy_id');

        foreach ($policyIds as $policyId) {
            $ids = DB::table('policy_events')
                ->where('policy_id', $policyId)
                ->where('type', 'like', 'endorsement.%')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->pluck('id');
            $seq = 0;
            foreach ($ids as $id) {
                $seq++;
                DB::table('policy_events')->where('id', $id)->update(['endorsement_seq' => $seq]);
            }
            DB::table('policies')->where('id', $policyId)->update(['endorsement_count' => $seq]);
        }
    }

    public function down(): void
    {
        Schema::table('policy_events', function (Blueprint $table): void {
            $table->dropUnique(['policy_id', 'endorsement_seq']);
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropColumn(['endorsement_seq', 'voided_at', 'void_reason']);
        });

        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn('endorsement_count');
        });
    }
};
