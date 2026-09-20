<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('mailbox_folders')->nullOnDelete();
            $table->string('name');
            $table->string('full_name');
            $table->string('delimiter', 8)->nullable();
            $table->string('special_use')->nullable();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('last_synced_uid')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'full_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_folders');
    }
};
