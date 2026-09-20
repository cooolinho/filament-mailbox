<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_outgoing_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            // Sender; the send permission is checked again when the message is sent.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status', 20)->index(); // scheduled, sending, sent, failed, cancelled
            $table->json('to');
            $table->json('cc')->nullable();
            $table->text('bcc')->nullable(); // encrypted
            $table->string('subject');
            $table->longText('body');
            $table->longText('body_html')->nullable();
            $table->string('message_id')->unique();
            $table->string('in_reply_to')->nullable();
            $table->json('references')->nullable();
            $table->string('provider_thread_id')->nullable();
            $table->string('forwarded_message_id')->nullable();
            $table->json('headers')->nullable();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('mailbox_messages')->nullOnDelete();
            // Marked as forwarded once the message is sent.
            $table->foreignId('forward_of_message_id')->nullable()->constrained('mailbox_messages')->nullOnDelete();
            $table->boolean('forward_as_attachment')->default(false);
            $table->timestamp('send_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at']);
        });

        Schema::create('mailbox_outgoing_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outgoing_message_id')->constrained('mailbox_outgoing_messages')->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->string('content_id')->nullable();
            $table->boolean('inline')->default(false);
            $table->string('disk');
            $table->string('storage_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_outgoing_attachments');
        Schema::dropIfExists('mailbox_outgoing_messages');
    }
};
