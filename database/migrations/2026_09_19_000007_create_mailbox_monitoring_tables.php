<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->string('trigger', 32);
            $table->string('status', 16)->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('queue_wait_ms')->nullable();
            $table->unsignedInteger('folders')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('deleted')->default(0);
            $table->string('error_type', 32)->nullable()->index();
            // Redacted, never message contents.
            $table->text('error')->nullable();
            $table->json('folder_stats')->nullable();
            $table->json('provider_stats')->nullable();

            $table->index(['mailbox_id', 'started_at']);
        });

        Schema::create('mailbox_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->cascadeOnDelete();
            $table->string('rule', 32);
            $table->string('severity', 16);
            $table->text('message');
            $table->timestamp('opened_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();

            $table->index(['rule', 'mailbox_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_alerts');
        Schema::dropIfExists('mailbox_sync_runs');
    }
};
