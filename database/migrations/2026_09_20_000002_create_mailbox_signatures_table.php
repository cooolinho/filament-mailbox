<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            // Null = signature of the mailbox, otherwise a personal signature of this user.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->text('body_text')->nullable();
            // Sanitised with the outgoing profile; images keep their storage path in data-id.
            $table->longText('body_html')->nullable();
            $table->boolean('is_default_new')->default(false);
            $table->boolean('is_default_reply')->default(false);
            $table->boolean('is_default_forward')->default(false);
            $table->timestamps();

            $table->index(['mailbox_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_signatures');
    }
};
