<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->boolean('is_subscribed')->default(true)->after('is_active');
            $table->unsignedInteger('message_count')->nullable()->after('is_subscribed');
            $table->unsignedInteger('unseen_count')->nullable()->after('message_count');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('unseen_count');
            $table->timestamp('statistics_updated_at')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->dropColumn(['is_subscribed', 'message_count', 'unseen_count', 'size_bytes', 'statistics_updated_at']);
        });
    }
};
