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
        Schema::table('rank_promotions', function (Blueprint $table): void {
            $table->foreignId('requested_by_user_id')->nullable()->after('requested_at')->constrained('users')->nullOnDelete();
            $table->text('request_note')->nullable()->after('requested_by_user_id');
            $table->index(['agent_id', 'status']);
        });

        if (Schema::hasColumn('ranks', 'monthly_avg_target')) {
            Schema::table('ranks', function (Blueprint $table): void {
                $table->dropColumn('monthly_avg_target');
            });
        }

        $lv1 = DB::table('ranks')->where('level', 1)->value('id');
        if ($lv1 !== null) {
            DB::table('agents')->whereNull('rank_id')->update(['rank_id' => $lv1, 'level' => 'l1']);
        }
    }

    public function down(): void
    {
        Schema::table('ranks', function (Blueprint $table): void {
            $table->decimal('monthly_avg_target', 15, 2)->default(0)->after('name_en');
        });

        Schema::table('rank_promotions', function (Blueprint $table): void {
            $table->dropIndex(['agent_id', 'status']);
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn('request_note');
        });
    }
};
