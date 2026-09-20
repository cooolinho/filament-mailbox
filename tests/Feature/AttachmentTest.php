<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\AttachmentService;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class AttachmentTest extends TestCase
{
    protected MailboxMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->fakeProvider();

        $mailbox = Mailbox::factory()->create();
        $mailbox->users()->attach($this->user);

        $this->message = MailboxMessage::factory()
            ->for(MailboxFolder::factory()->for($mailbox)->inbox(), 'folder')
            ->create(['has_attachments' => true, 'is_read' => true]);
    }

    public function test_attachments_are_listed_on_the_message(): void
    {
        $attachment = $this->attachment('report.pdf', str_repeat('a', 2048));

        Livewire::test(ViewMessage::class, ['record' => $this->message->mailbox_id, 'message' => $this->message->id])
            ->assertOk()
            ->assertSee('report.pdf')
            ->assertSee('application/pdf')
            ->assertSee('2 KB')
            ->assertSee(app(AttachmentService::class)->downloadUrl($attachment), false);
    }

    public function test_attachment_can_be_downloaded(): void
    {
        $attachment = $this->attachment('report.pdf', '%PDF-1.4 content');

        $response = $this->get(app(AttachmentService::class)->downloadUrl($attachment));

        $response->assertOk();
        $response->assertDownload('report.pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertSame('%PDF-1.4 content', $response->streamedContent());
    }

    public function test_missing_file_returns_not_found(): void
    {
        $attachment = $this->attachment('gone.txt', 'x');
        Storage::disk('local')->delete($attachment->storage_path);

        $this->get(app(AttachmentService::class)->downloadUrl($attachment))->assertNotFound();
    }

    public function test_guests_cannot_download_attachments(): void
    {
        $attachment = $this->attachment('secret.txt', 'secret');
        $url = app(AttachmentService::class)->downloadUrl($attachment);

        auth()->logout();

        $this->get($url)->assertRedirect();
    }

    public function test_download_filename_is_sanitised(): void
    {
        $attachment = $this->attachment("evil\"\r\nX-Injected: 1.html", 'x');

        $response = $this->get(app(AttachmentService::class)->downloadUrl($attachment));

        $response->assertOk();
        $this->assertStringNotContainsString("\n", $response->headers->get('Content-Disposition'));
        $this->assertFalse($response->headers->has('X-Injected'));
    }

    public function test_attachment_import_stores_file_on_configured_disk(): void
    {
        Storage::fake('mail-attachments');
        config(['filament-mailbox.attachments.disk' => 'mail-attachments']);

        $attachment = app(AttachmentService::class)->store(
            $this->message,
            new AttachmentData('invoice.pdf', 'application/pdf', 'PDF'),
        );

        $this->assertSame('mail-attachments', $attachment->disk);
        $this->assertSame(3, $attachment->size);
        Storage::disk('mail-attachments')->assertExists($attachment->storage_path);
    }

    protected function attachment(string $filename, string $contents): MailboxAttachment
    {
        $path = 'mailbox/'.uniqid();
        Storage::disk('local')->put($path, $contents);

        return MailboxAttachment::factory()->for($this->message, 'message')->create([
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'size' => strlen($contents),
            'disk' => 'local',
            'storage_path' => $path,
        ]);
    }
}
