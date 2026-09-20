<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Search;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Health\Checks\SearchEngineCheck;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\Extraction\AttachmentTextExtractor;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use ZipArchive;

class SearchUiAndExtractionTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $sent;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-mailbox.search.engine', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->sent = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Sent)->create(['name' => 'Sent', 'full_name' => 'Sent']);
    }

    public function test_table_searches_the_folder_or_all_folders_with_snippets(): void
    {
        $inbox = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Offer', 'text_body' => 'Here is the quarterly <offer> for you.']);
        $sent = MailboxMessage::factory()->for($this->sent, 'folder')->create(['subject' => 'Re: Offer']);
        $other = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Lunch']);

        $page = Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->searchTable('quarterly')
            ->assertCanSeeTableRecords([$inbox])
            ->assertCanNotSeeTableRecords([$sent, $other])
            ->assertTableColumnVisible('search_snippet')
            ->assertSeeHtml('<mark>quarterly</mark> &lt;offer&gt;')
            ->searchTable('offer')
            ->assertCanNotSeeTableRecords([$sent])
            ->callAction(TestAction::make('searchAllFolders')->table())
            ->assertSet('searchAllFolders', true)
            ->assertCanSeeTableRecords([$inbox, $sent])
            ->assertTableColumnVisible('folder.name');

        $page->searchTable('')
            ->assertCanSeeTableRecords([$inbox, $other])
            ->assertCanNotSeeTableRecords([$sent]);
    }

    public function test_operator_help(): void
    {
        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction(TestAction::make('searchHelp')->table())
            ->assertMountedActionModalSee(['has:attachment filename:pdf', 'older_than:30d newer_than:2w']);
    }

    public function test_text_and_office_attachments_are_extracted_into_the_index(): void
    {
        config(['filament-mailbox.search.attachments.extract_text' => true]);

        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['has_attachments' => true]);

        Storage::disk('local')->put('mailbox/notes.txt', 'Projektbudget freigegeben');
        MailboxAttachment::factory()->for($message, 'message')->create(['filename' => 'notes.txt', 'mime_type' => 'text/plain', 'storage_path' => 'mailbox/notes.txt']);

        Storage::disk('local')->put('mailbox/contract.docx', $this->docx('<w:p><w:r><w:t>Vertragsstrafe &amp; Haftung</w:t></w:r></w:p>'));
        $docx = MailboxAttachment::factory()->for($message, 'message')->create(['filename' => 'contract.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'storage_path' => 'mailbox/contract.docx']);

        $this->assertSame('Vertragsstrafe & Haftung', $docx->refresh()->extracted_text);
        $this->assertNotNull($docx->extracted_at);

        foreach (['projektbudget', 'vertragsstrafe'] as $word) {
            $this->assertSame([$message->id], app(SearchManager::class)->engine()->search(app(SearchManager::class)->parse($word), new \Cooolinho\FilamentMailbox\Search\SearchScope($this->mailbox->id))->ids);
        }
    }

    public function test_pdf_extraction_uses_pdftotext_without_shell_and_stores_errors(): void
    {
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result('Kontoauszug März'))
                ->push(Process::result('', 'Syntax Error', 1)),
        ]);

        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        Storage::disk('local')->put('mailbox/a.pdf', '%PDF-1.4');
        $good = MailboxAttachment::factory()->for($message, 'message')->create(['storage_path' => 'mailbox/a.pdf']);
        $broken = MailboxAttachment::factory()->for($message, 'message')->create(['storage_path' => 'mailbox/a.pdf']);
        $large = MailboxAttachment::factory()->for($message, 'message')->create(['storage_path' => 'mailbox/a.pdf', 'size' => 50 * 1024 * 1024]);

        $extractor = app(AttachmentTextExtractor::class);

        $this->assertTrue($extractor->extract($good));
        $this->assertFalse($extractor->extract($broken));
        $this->assertFalse($extractor->extract($large));

        $this->assertSame('Kontoauszug März', $good->refresh()->extracted_text);
        $this->assertStringContainsString('Syntax Error', $broken->refresh()->extraction_error);
        $this->assertSame('File too large.', $large->refresh()->extraction_error);

        Process::assertRan(fn ($process) => is_array($process->command) && $process->command[0] === 'pdftotext' && $process->command[1] === '-enc');
    }

    public function test_extract_command_processes_pending_attachments(): void
    {
        config(['filament-mailbox.search.attachments.extract_text' => true]);
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        Storage::disk('local')->put('mailbox/a.txt', 'Hallo');

        // Created while extraction was off.
        config(['filament-mailbox.search.attachments.extract_text' => false]);
        $attachment = MailboxAttachment::factory()->for($message, 'message')->create(['filename' => 'a.txt', 'mime_type' => 'text/plain', 'storage_path' => 'mailbox/a.txt']);
        $this->assertNull($attachment->refresh()->extracted_at);

        config(['filament-mailbox.search.attachments.extract_text' => true]);
        $this->artisan('mailbox:search-extract-attachments', ['--now' => true])->assertSuccessful();

        $this->assertSame('Hallo', $attachment->refresh()->extracted_text);
    }

    public function test_index_errors_never_break_saving_messages(): void
    {
        DB::statement('DROP TABLE mailbox_search_fts');

        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Still saved']);

        $this->assertModelExists($message);
    }

    public function test_unknown_engines_are_rejected_and_the_health_check_names_the_engine(): void
    {
        $this->assertStringContainsString('database', app(SearchEngineCheck::class)->run()->message);

        $this->expectException(InvalidArgumentException::class);
        app(SearchManager::class)->resolve('unknown');
    }

    protected function docx(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        return tap((string) file_get_contents($path), fn () => unlink($path));
    }
}
