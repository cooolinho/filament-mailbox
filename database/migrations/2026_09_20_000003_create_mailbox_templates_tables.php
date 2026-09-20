<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_templates', function (Blueprint $table) {
            $table->id();
            // Null = available in every mailbox.
            $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable()->index();
            $table->string('subject')->nullable();
            $table->text('body_text')->nullable();
            // Sanitised with the outgoing profile; images keep their storage path in data-id.
            $table->longText('body_html')->nullable();
            // Compose contexts: new, reply, forward
            $table->json('contexts');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
        });

        Schema::create('mailbox_template_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('mailbox_templates')->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->string('disk');
            $table->string('storage_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_template_attachments');
        Schema::dropIfExists('mailbox_templates');
    }
};
