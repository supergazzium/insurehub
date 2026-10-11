<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $t): void {
            // Scanned life / non-life license photos (#22). Stored as private
            // `local` disk paths — read back through the authenticated photo
            // endpoint, never a public /storage URL. Sit alongside the
            // existing id_card / bank_book photo columns.
            $t->string('license_life_photo_path', 512)->nullable()->after('bank_book_photo_path');
            $t->string('license_non_life_photo_path', 512)->nullable()->after('license_life_photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $t): void {
            $t->dropColumn(['license_life_photo_path', 'license_non_life_photo_path']);
        });
    }
};
