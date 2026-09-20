<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->index();
            $table->foreignId('snoozed_by')->nullable();
            $table->timestamp('unsnoozed_at')->nullable();
            // List order: received_at, or the time a snoozed message returned.
            $table->timestamp('sort_at')->nullable();
            // Server variant: the folder a snoozed message is moved back to.
            $table->foreignId('snoozed_from_folder_id')->nullable()->constrained('mailbox_folders')->nullOnDelete();

            $table->index(['folder_id', 'sort_at']);
        });

        // In id ranges, so large tables are not locked by one statement; idempotent.
        $max = (int) DB::table('mailbox_messages')->max('id');

        for ($from = 1; $from <= $max; $from += 5000) {
            DB::table('mailbox_messages')
                ->whereBetween('id', [$from, $from + 4999])
                ->whereNull('sort_at')
                ->update(['sort_at' => DB::raw('COALESCE(received_at, created_at)')]);
        }
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropIndex(['folder_id', 'sort_at']);
            $table->dropIndex(['snoozed_until']);
            $table->dropConstrainedForeignId('snoozed_from_folder_id');
            $table->dropColumn(['snoozed_until', 'snoozed_by', 'unsnoozed_at', 'sort_at']);
        });
    }
};
