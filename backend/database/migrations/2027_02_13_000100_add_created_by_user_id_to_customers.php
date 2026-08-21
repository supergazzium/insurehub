<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `created_by_user_id` so the detail drawer can attribute customers
 * created by users who are NOT agents (Platform Admin, back-office). The
 * existing `created_by_agent_id` remains for agent-created rows; the two
 * columns are mutually exclusive at the write site — one is set, the
 * other stays NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('created_by_agent_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
