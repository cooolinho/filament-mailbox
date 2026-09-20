<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_user', function (Blueprint $table) {
            $table->boolean('notify')->default(true);
            // null = the configured default folders (inbox)
            $table->json('notify_folder_ids')->nullable();
        });

        Schema::create('mailbox_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_push_subscriptions');

        Schema::table('mailbox_user', function (Blueprint $table) {
            $table->dropColumn(['notify', 'notify_folder_ids']);
        });
    }
};
