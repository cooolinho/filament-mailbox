<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_stats_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            // Day in the time zone of the mailbox.
            $table->date('date');
            $table->unsignedInteger('inbound_count')->default(0);
            $table->unsignedInteger('outbound_count')->default(0);
            $table->unsignedInteger('auto_generated_count')->default(0);
            $table->json('inbound_by_hour');
            $table->json('outbound_by_hour');
            $table->unsignedInteger('replied_count')->default(0);
            $table->unsignedInteger('reply_minutes_p50')->nullable();
            $table->unsignedInteger('reply_minutes_p90')->nullable();
            $table->unsignedInteger('reply_business_minutes_p50')->nullable();
            $table->unsignedInteger('reply_business_minutes_p90')->nullable();
            $table->unsignedInteger('sla_met_count')->default(0);
            $table->unsignedInteger('unique_sender_domains')->default(0);
            $table->unsignedBigInteger('attachment_bytes_in')->default(0);
            $table->unsignedBigInteger('attachment_bytes_out')->default(0);
            $table->timestamps();

            $table->unique(['mailbox_id', 'date']);
            $table->index('date');
        });

        Schema::create('mailbox_stats_domains_daily', function (Blueprint $table) {
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->date('date');
            $table->string('domain');
            $table->unsignedInteger('count');

            $table->primary(['mailbox_id', 'date', 'domain']);
        });

        Schema::create('mailbox_stats_dirty_days', function (Blueprint $table) {
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->date('date');

            $table->primary(['mailbox_id', 'date']);
        });

        Schema::create('mailbox_reply_links', function (Blueprint $table) {
            $table->foreignId('inbound_message_id')->constrained('mailbox_messages')->cascadeOnDelete();
            $table->foreignId('reply_message_id')->constrained('mailbox_messages')->cascadeOnDelete();
            $table->unsignedInteger('reply_minutes');
            $table->unsignedInteger('reply_business_minutes')->nullable();

            $table->primary(['inbound_message_id', 'reply_message_id']);
            $table->index('reply_message_id');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            // {"timezone": "Europe/Berlin", "hours": [{"day": "mon", "start": "08:00", "end": "17:00"}], "holidays": ["2026-12-24"]}
            $table->json('business_hours')->nullable();
            $table->unsignedInteger('sla_minutes')->nullable();
        });

        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->boolean('is_auto_generated')->default(false);
            $table->index(['mailbox_id', 'message_id']);
            $table->index(['mailbox_id', 'in_reply_to']);
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropIndex(['mailbox_id', 'message_id']);
            $table->dropIndex(['mailbox_id', 'in_reply_to']);
            $table->dropColumn('is_auto_generated');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['business_hours', 'sla_minutes']);
        });

        Schema::dropIfExists('mailbox_reply_links');
        Schema::dropIfExists('mailbox_stats_dirty_days');
        Schema::dropIfExists('mailbox_stats_domains_daily');
        Schema::dropIfExists('mailbox_stats_daily');
    }
};
