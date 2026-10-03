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
        Schema::table('policies', function (Blueprint $table) {
            $table->date('approved_date')->nullable()->after('status_note');
            $table->string('approval_ref', 64)->nullable()->after('approved_date');
            $table->text('approval_note')->nullable()->after('approval_ref');
        });

        $approvedAt = DB::table('policy_events')
            ->where('type', 'underwritingApproved')
            ->groupBy('policy_id')
            ->select('policy_id', DB::raw('MAX(occurred_at) as occurred_at'))
            ->get();
        foreach ($approvedAt as $row) {
            DB::table('policies')
                ->where('id', $row->policy_id)
                ->whereNull('approved_date')
                ->update(['approved_date' => substr((string) $row->occurred_at, 0, 10)]);
        }
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn(['approved_date', 'approval_ref', 'approval_note']);
        });
    }
};
