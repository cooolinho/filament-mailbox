<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // UPN or address of a shared mailbox; accessed as /users/{remote_user}.
            $table->string('remote_user')->nullable()->after('oauth_connection_id');
            $table->string('host')->nullable()->change();
            $table->unsignedInteger('port')->nullable()->change();
            $table->string('encryption')->nullable()->change();
            $table->string('username')->nullable()->change();
        });

        Schema::create('mailbox_graph_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained('mailbox_folders')->cascadeOnDelete();
            $table->string('subscription_id')->unique();
            $table->text('client_state');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_graph_subscriptions');

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('remote_user');
        });
    }
};
