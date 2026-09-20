<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_search_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_uid')->unique();
            $table->string('type', 40);
            $table->json('message_ids')->nullable();
            $table->string('status', 20)->default('enqueued')->index();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_search_tasks');
    }
};
