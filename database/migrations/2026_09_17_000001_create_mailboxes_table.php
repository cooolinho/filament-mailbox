<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider')->default('imap');
            $table->string('email');
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('encryption');
            $table->boolean('validate_cert')->default(true);
            $table->string('username');
            $table->text('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('mailbox_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->foreignId('user_id')->index();
            $table->timestamps();

            $table->unique(['mailbox_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_user');
        Schema::dropIfExists('mailboxes');
    }
};
