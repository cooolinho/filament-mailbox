<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->foreignId('folder_id')->constrained('mailbox_folders')->cascadeOnDelete();
            $table->unsignedBigInteger('uid');
            $table->unsignedBigInteger('uid_validity');
            $table->string('message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();
            $table->string('from_address')->nullable()->index();
            $table->string('from_name')->nullable();
            $table->json('reply_to')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->nullable()->index();
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['folder_id', 'uid_validity', 'uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_messages');
    }
};
