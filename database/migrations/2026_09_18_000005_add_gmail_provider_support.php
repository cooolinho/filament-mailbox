<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Mailbox-wide cursor for providers whose change feed spans all folders (Gmail history id).
            $table->json('sync_cursor')->nullable()->after('last_sync_error');
            $table->unsignedSmallInteger('initial_sync_days')->nullable()->after('sync_cursor');
            $table->timestamp('watch_expires_at')->nullable()->after('initial_sync_days');
        });

        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->string('thread_id')->nullable()->index()->after('remote_id');
            $table->index(['remote_id', 'mailbox_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropIndex(['remote_id', 'mailbox_id']);
            $table->dropIndex(['thread_id']);
            $table->dropColumn('thread_id');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['sync_cursor', 'initial_sync_days', 'watch_expires_at']);
        });
    }
};
