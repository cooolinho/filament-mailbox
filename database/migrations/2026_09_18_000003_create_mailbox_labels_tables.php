<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->string('source');
            $table->string('remote_key');
            $table->string('name');
            $table->string('color', 20)->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->unique(['mailbox_id', 'source', 'remote_key']);
        });

        Schema::create('mailbox_message_labels', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained('mailbox_messages')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('mailbox_labels')->cascadeOnDelete();

            $table->primary(['message_id', 'label_id']);
            $table->index('label_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_message_labels');
        Schema::dropIfExists('mailbox_labels');
    }
};
