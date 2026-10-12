<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1 — LINE Login. Store the LINE user id (the `sub` claim from LINE's OIDC
 * id_token) on the user so a linked account can sign in with LINE. Nullable +
 * unique: an account is linked only after the agent connects LINE from the
 * portal while already signed in (link-to-existing-account model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('line_user_id', 64)->nullable()->unique()->after('agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['line_user_id']);
            $t->dropColumn('line_user_id');
        });
    }
};
