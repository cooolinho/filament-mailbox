<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            // Creator; only they (or managers) may edit the draft.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('mailbox_messages')->nullOnDelete();
            $table->string('mode', 20)->default('new'); // new, reply, reply_all, forward
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject')->nullable();
            $table->string('body_format', 10)->default('text'); // text, html
            $table->longText('body')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('quoted')->nullable();
            $table->longText('quoted_html')->nullable();
            $table->unsignedBigInteger('signature_id')->nullable();
            $table->boolean('as_attachment')->default(false);
            $table->json('original_attachment_ids')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();
            // Current version in the drafts folder of the server.
            $table->unsignedBigInteger('remote_folder_id')->nullable();
            $table->string('remote_id')->nullable();
            $table->string('server_message_id')->nullable()->index();
            // Incremented with every save; the server copy is current when both match.
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('server_revision')->nullable();
            $table->timestamp('server_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mailbox_draft_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('mailbox_drafts')->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->string('disk');
            $table->string('storage_path');
            $table->timestamps();
        });

        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('is_answered');
            $table->foreignId('draft_id')->nullable()->after('is_draft')->constrained('mailbox_drafts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draft_id');
            $table->dropColumn('is_draft');
        });

        Schema::dropIfExists('mailbox_draft_attachments');
        Schema::dropIfExists('mailbox_drafts');
    }
};
