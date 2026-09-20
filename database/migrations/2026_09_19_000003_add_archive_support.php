<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Used when the server has no folder with the \Archive role.
            $table->foreignId('archive_folder_id')->nullable()->after('initial_sync_days')->constrained('mailbox_folders')->nullOnDelete();
        });

        Schema::table('mailbox_messages', function (Blueprint $table) {
            // Folder a message was moved to without a known new identity; the next sync restores it there.
            $table->foreignId('pending_move_to')->nullable()->after('folder_id')->constrained('mailbox_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_move_to');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archive_folder_id');
        });
    }
};
