<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_search_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id')->primary();
            $table->unsignedBigInteger('mailbox_id');
            $table->unsignedBigInteger('folder_id')->index();
            $table->timestamp('received_at')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_flagged')->default(false);
            $table->boolean('has_attachments')->default(false);
            // Lower-case, normalised texts.
            $table->text('from_text')->nullable();
            $table->text('to_text')->nullable();
            $table->text('cc_text')->nullable();
            $table->string('subject', 500)->nullable();
            $table->mediumText('body_text')->nullable();
            $table->text('attachment_names')->nullable();
            $table->mediumText('attachment_text')->nullable();

            $table->foreign('message_id')->references('id')->on('mailbox_messages')->cascadeOnDelete();
            $table->index(['mailbox_id', 'received_at']);
        });

        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => Schema::table('mailbox_search_documents', function (Blueprint $table) {
                $table->fullText(['subject', 'from_text', 'to_text', 'cc_text', 'body_text', 'attachment_names', 'attachment_text'], 'mailbox_search_documents_fulltext');
            }),
            'pgsql' => $this->postgres(),
            'sqlite' => DB::statement("CREATE VIRTUAL TABLE mailbox_search_fts USING fts5(subject, from_text, to_text, cc_text, body_text, attachment_names, attachment_text, tokenize = 'unicode61 remove_diacritics 2')"),
            default => null,
        };

        Schema::table('mailbox_attachments', function (Blueprint $table) {
            $table->mediumText('extracted_text')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->string('extraction_error')->nullable();
        });
    }

    protected function postgres(): void
    {
        $language = preg_replace('/[^a-z_]/', '', (string) config('filament-mailbox.search.postgres_language', 'german')) ?: 'simple';

        DB::statement("ALTER TABLE mailbox_search_documents ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('{$language}', coalesce(subject, '')), 'A') ||
            setweight(to_tsvector('simple', coalesce(from_text, '') || ' ' || coalesce(to_text, '') || ' ' || coalesce(cc_text, '') || ' ' || coalesce(attachment_names, '')), 'B') ||
            setweight(to_tsvector('{$language}', coalesce(body_text, '') || ' ' || coalesce(attachment_text, '')), 'C')
        ) STORED");
        DB::statement('CREATE INDEX mailbox_search_documents_vector ON mailbox_search_documents USING GIN (search_vector)');
    }

    public function down(): void
    {
        Schema::table('mailbox_attachments', function (Blueprint $table) {
            $table->dropColumn(['extracted_text', 'extracted_at', 'extraction_error']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS mailbox_search_fts');
        }

        Schema::dropIfExists('mailbox_search_documents');
    }
};
