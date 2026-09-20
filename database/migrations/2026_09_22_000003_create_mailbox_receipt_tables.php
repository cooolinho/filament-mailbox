<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            // Address of a read receipt request (Disposition-Notification-To).
            $table->string('mdn_requested_to')->nullable();
            $table->string('mdn_status', 20)->nullable(); // pending, unsafe, sent, ignored, not_applicable
            // The message is itself a read receipt or delivery report.
            $table->boolean('is_receipt')->default(false)->index();
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            // null = filament-mailbox.read_receipts.request_by_default
            $table->boolean('request_read_receipts')->nullable();
        });

        Schema::create('mailbox_receipt_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->foreignId('outgoing_message_id')->nullable()->constrained('mailbox_outgoing_messages')->nullOnDelete();
            // Without angle brackets.
            $table->string('message_id');
            $table->string('type', 20); // read, delivery
            $table->json('recipients');
            $table->timestamps();

            $table->index(['mailbox_id', 'message_id']);
        });

        Schema::create('mailbox_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('mailbox_receipt_requests')->cascadeOnDelete();
            $table->foreignId('receipt_message_id')->nullable()->constrained('mailbox_messages')->nullOnDelete();
            $table->string('recipient');
            $table->string('disposition', 40); // displayed, deleted, ... / delivered, failed, delayed
            $table->text('diagnostic')->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->unique(['request_id', 'recipient', 'disposition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_receipts');
        Schema::dropIfExists('mailbox_receipt_requests');

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('request_read_receipts');
        });

        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropIndex(['is_receipt']);
            $table->dropColumn(['mdn_requested_to', 'mdn_status', 'is_receipt']);
        });
    }
};
