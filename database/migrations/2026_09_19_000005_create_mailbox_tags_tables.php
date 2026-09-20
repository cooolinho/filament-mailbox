<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_tags', function (Blueprint $table) {
            $table->id();
            // Null = available in every mailbox.
            $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->default('gray');
            $table->string('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['mailbox_id', 'name']);
        });

        Schema::create('mailbox_message_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('mailbox_tags')->cascadeOnDelete();
            // Soft-deleted messages keep their assignments, so a re-imported copy can take them over.
            $table->foreignId('message_id')->constrained('mailbox_messages')->cascadeOnDelete();
            $table->string('message_key', 40)->index();
            $table->unsignedBigInteger('tagged_by')->nullable();
            $table->timestamps();

            $table->unique(['tag_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_message_tag');
        Schema::dropIfExists('mailbox_tags');
    }
};
