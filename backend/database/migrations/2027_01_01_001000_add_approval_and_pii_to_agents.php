<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of the Insurance Agent Portal:
 *
 *   1. Add approval workflow columns (pending → approved / rejected).
 *   2. Add photo-path columns for profile / national-ID / bank-book photos.
 *   3. Widen the PII columns that will now hold Laravel `encrypted` ciphertext.
 *      Laravel's default encryption produces base64-encoded ciphertext that's
 *      well over the original varchar(20) / varchar(32) widths. We use TEXT to
 *      avoid future overflow.
 *   4. One-off backfill: encrypt every existing plaintext value in the four
 *      PII columns (id_card, bank_account_no, bank_account_name,
 *      license_number). Idempotent — a value that already decrypts is
 *      re-encrypted only if we can't decrypt it (i.e. it was still plaintext).
 *
 * Add-only. `down()` restores the schema shape but does NOT decrypt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $t): void {
            $t->string('approval_status', 16)->default('approved')->after('active');
            $t->text('approval_note')->nullable()->after('approval_status');
            $t->foreignId('approved_by_user_id')->nullable()->after('approval_note')
                ->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $t->timestamp('rejected_at')->nullable()->after('approved_at');
            $t->string('signup_source', 128)->nullable()->after('rejected_at');
            $t->string('profile_photo_path', 512)->nullable()->after('signup_source');
            $t->string('id_card_photo_path', 512)->nullable()->after('profile_photo_path');
            $t->string('bank_book_photo_path', 512)->nullable()->after('id_card_photo_path');
            $t->index(['tenant_id', 'approval_status'], 'agents_tenant_approval_idx');
        });

        // Widen PII columns to hold ciphertext.
        DB::statement('ALTER TABLE agents MODIFY id_card TEXT NULL');
        DB::statement('ALTER TABLE agents MODIFY bank_account_no TEXT NULL');
        DB::statement('ALTER TABLE agents MODIFY bank_account_name TEXT NULL');
        DB::statement('ALTER TABLE agents MODIFY license_number TEXT NULL');

        // Backfill: encrypt existing plaintext values in place.
        $encryptCols = ['id_card', 'bank_account_no', 'bank_account_name', 'license_number'];
        DB::table('agents')->orderBy('id')->chunkById(500, function ($rows) use ($encryptCols): void {
            foreach ($rows as $row) {
                $updates = [];
                foreach ($encryptCols as $col) {
                    $value = $row->{$col} ?? null;
                    if ($value === null || $value === '') {
                        continue;
                    }
                    // If it already decrypts, it's ciphertext — skip.
                    try {
                        Crypt::decryptString((string) $value);
                        continue;
                    } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                        // Fall through: plaintext, needs encrypting.
                    }
                    $updates[$col] = Crypt::encryptString((string) $value);
                }
                if ($updates !== []) {
                    DB::table('agents')->where('id', $row->id)->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        // NOTE: does not decrypt data; ciphertext will overflow the original
        // narrow widths, so we restore the widths but leave whatever text is there.
        Schema::table('agents', function (Blueprint $t): void {
            $t->dropIndex('agents_tenant_approval_idx');
            $t->dropConstrainedForeignId('approved_by_user_id');
            $t->dropColumn([
                'approval_status', 'approval_note', 'approved_at', 'rejected_at',
                'signup_source', 'profile_photo_path', 'id_card_photo_path', 'bank_book_photo_path',
            ]);
        });
        DB::statement('ALTER TABLE agents MODIFY id_card VARCHAR(20) NULL');
        DB::statement('ALTER TABLE agents MODIFY bank_account_no VARCHAR(64) NULL');
        DB::statement('ALTER TABLE agents MODIFY bank_account_name VARCHAR(255) NULL');
        DB::statement('ALTER TABLE agents MODIFY license_number VARCHAR(32) NULL');
    }
};
