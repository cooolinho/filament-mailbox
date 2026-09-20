<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_oauth_applications', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider');
            $table->string('tenant')->nullable();
            $table->string('client_id');
            $table->text('client_secret')->nullable();
            $table->text('certificate')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
        });

        Schema::create('mailbox_oauth_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('mailbox_oauth_applications')->cascadeOnDelete();
            $table->string('grant_type');
            $table->string('account_email')->nullable();
            $table->string('account_subject')->nullable();
            $table->json('scopes');
            $table->text('refresh_token')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->string('status')->default('active');
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'account_subject']);
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('auth_mode')->default('password')->after('provider');
            $table->foreignId('oauth_connection_id')->nullable()->after('auth_mode')
                ->constrained('mailbox_oauth_connections')->nullOnDelete();
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->text('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('oauth_connection_id');
            $table->dropColumn(['auth_mode', 'smtp_host', 'smtp_port']);
        });

        Schema::dropIfExists('mailbox_oauth_connections');
        Schema::dropIfExists('mailbox_oauth_applications');
    }
};
