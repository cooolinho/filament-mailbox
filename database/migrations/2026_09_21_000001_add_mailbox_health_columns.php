<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Denormalised by the health listener, so the dashboard needs no query per row.
            $table->string('health', 16)->default('unknown')->index();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_run_status', 16)->nullable();
            $table->unsignedInteger('last_run_duration_ms')->nullable();
            $table->string('last_error_type', 32)->nullable();
            $table->unsignedSmallInteger('failing_folders')->default(0);
        });

        // Existing mailboxes: the last synchronisation reached the server.
        DB::table('mailboxes')->whereNotNull('last_synced_at')->update(['last_success_at' => DB::raw('last_synced_at')]);
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropIndex(['health']);
            $table->dropColumn(['health', 'consecutive_failures', 'last_success_at', 'last_run_status', 'last_run_duration_ms', 'last_error_type', 'failing_folders']);
        });
    }
};
