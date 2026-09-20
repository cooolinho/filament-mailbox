<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_receipts', function (Blueprint $table) {
            $table->string('status_code', 16)->nullable(); // e.g. 5.1.1
            $table->string('source', 20)->default('mdn'); // mdn, dsn, bounce_heuristic, webhook
            $table->string('reporting_mta')->nullable();
        });

        Schema::table('mailbox_outgoing_messages', function (Blueprint $table) {
            $table->string('dsn_notify', 30)->nullable(); // "SUCCESS,FAILURE,DELAY" | "FAILURE,DELAY"
            $table->boolean('dsn_supported')->nullable(); // answer of the SMTP server (EHLO)
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_outgoing_messages', function (Blueprint $table) {
            $table->dropColumn(['dsn_notify', 'dsn_supported']);
        });

        Schema::table('mailbox_receipts', function (Blueprint $table) {
            $table->dropColumn(['status_code', 'source', 'reporting_mta']);
        });
    }
};
