<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #8 — carrier contact name doubles as a department label ("Group motor")
 * and email accepts a comma-separated list of addresses. Widen both columns
 * so the longer values fit:
 *   first_name  120 -> 255  (person OR department name)
 *   email       255 -> text (several comma-separated addresses)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_contacts', function (Blueprint $t) {
            $t->string('first_name', 255)->nullable()->change();
            $t->text('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('carrier_contacts', function (Blueprint $t) {
            $t->string('first_name', 120)->nullable()->change();
            $t->string('email', 255)->nullable()->change();
        });
    }
};
