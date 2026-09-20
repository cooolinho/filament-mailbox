<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Used when the server has no folder with the \Junk role.
            $table->foreignId('spam_folder_id')->nullable()->after('archive_folder_id')->constrained('mailbox_folders')->nullOnDelete();
        });

        Schema::create('mailbox_blocked_senders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            // user@example.com or *@example.com, lowercase.
            $table->string('pattern');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_blocked_senders');

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spam_folder_id');
        });
    }
};
