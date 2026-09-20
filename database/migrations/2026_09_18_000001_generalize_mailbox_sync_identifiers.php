<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-neutral identifiers and sync cursors.
 *
 * The IMAP columns uid, uid_validity and last_synced_uid are kept for one
 * release (unused) and removed afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->string('remote_id')->nullable()->after('full_name');
            $table->json('sync_cursor')->nullable()->after('last_synced_uid');
        });

        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->string('remote_id')->nullable()->after('folder_id');
            $table->unsignedBigInteger('uid')->nullable()->change();
            $table->unsignedBigInteger('uid_validity')->nullable()->change();
            $table->boolean('is_flagged')->default(false)->after('is_read');
            $table->boolean('is_answered')->default(false)->after('is_flagged');
            $table->json('keywords')->nullable()->after('is_answered');
        });

        // Idempotent and chunked, so it can be re-run on large installations.
        DB::table('mailbox_folders')->whereNull('remote_id')->chunkById(500, function ($folders): void {
            foreach ($folders as $folder) {
                DB::table('mailbox_folders')->where('id', $folder->id)->update([
                    'remote_id' => $folder->full_name,
                    'sync_cursor' => $folder->uid_validity === null ? null : json_encode([
                        'uid_validity' => (int) $folder->uid_validity,
                        'last_uid' => (int) $folder->last_synced_uid,
                    ]),
                ]);
            }
        });

        DB::table('mailbox_messages')->whereNull('remote_id')->chunkById(500, function ($messages): void {
            foreach ($messages as $message) {
                DB::table('mailbox_messages')->where('id', $message->id)->update([
                    'remote_id' => $message->uid_validity.':'.$message->uid,
                ]);
            }
        });

        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->unique(['folder_id', 'remote_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropUnique(['folder_id', 'remote_id']);
        });

        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropColumn(['remote_id', 'is_flagged', 'is_answered', 'keywords']);
        });

        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->dropColumn(['remote_id', 'sync_cursor']);
        });
    }
};
