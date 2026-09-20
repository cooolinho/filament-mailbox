<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique();
            // {"layout": "split", ...}
            $table->json('preferences');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_user_preferences');
    }
};
