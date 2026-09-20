<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            // "Starred" navigation badge and view.
            $table->index(['mailbox_id', 'is_flagged']);
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropIndex(['mailbox_id', 'is_flagged']);
        });
    }
};
