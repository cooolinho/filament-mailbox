<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditDraft;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ListDrafts;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Mail\MimeMessageBuilder;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\DraftService;
use Cooolinho\FilamentMailbox\Services\InlineImageProcessor;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Support\ProviderCapabilities;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\User;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use DirectoryTree\ImapEngine\FileMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class DraftsTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $drafts;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->provider = $this->fakeProvider();
        $this->provider->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox));
        $this->provider->addFolder(new FolderData('Drafts', 'Drafts', '/', SpecialUse::Drafts));

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support', 'email' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);

        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->drafts = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Drafts)->create(['name' => 'Drafts', 'full_name' => 'Drafts']);
    }

    public function test_an_incomplete_message_is_saved_as_draft_from_the_compose_modal(): void
    {
        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->fillForm(['to' => [], 'subject' => '', 'format' => 'text', 'body' => 'Not finished', 'bcc' => ['audit@example.com']])
            ->callMountedAction(['draft' => true])
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.drafts.saved'))
            ->assertRedirect(MailboxResource::getUrl('draft', ['record' => $this->mailbox, 'draft' => MailboxDraft::sole()]));

        $draft = MailboxDraft::sole();
        $this->assertSame($this->user->id, $draft->user_id);
        $this->assertSame('new', $draft->mode);
        $this->assertSame('Not finished', $draft->body);
        $this->assertSame([], $draft->to);

        // The version on the server is a \Draft in the drafts folder, with BCC and the draft header.
        $this->assertCount(1, $this->provider->messages['Drafts']);
        $stored = array_values($this->provider->messages['Drafts'])[0];
        $this->assertTrue($stored->flags->draft);
        $this->assertTrue($stored->flags->seen);

        $raw = array_values($this->provider->raw['Drafts'])[0];
        $this->assertStringContainsString('X-Filament-Mailbox-Draft: '.$draft->uuid, $raw);
        $this->assertStringContainsString('Bcc: audit@example.com', $raw);
        $this->assertSame($draft->server_message_id, trim((string) (new FileMessage($raw))->messageId(), '<>'));
        $this->assertNotNull($draft->remote_id);
        $this->assertTrue($draft->isSynced());
    }

    public function test_saving_replaces_the_server_version_and_the_sync_links_instead_of_duplicating(): void
    {
        $draft = $this->saveDraft(['to' => ['jane@example.com'], 'subject' => 'Version 1', 'format' => 'text', 'body' => 'One']);
        $sync = app(SyncService::class);

        $sync->syncFolder($this->drafts->refresh(), $this->provider);

        $linked = MailboxMessage::sole();
        $this->assertTrue($linked->is_draft);
        $this->assertSame($draft->id, $linked->draft_id);

        $this->editDraft($draft)
            ->fillForm(['subject' => 'Version 2', 'body' => 'Two'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified(__('filament-mailbox::mailbox.drafts.saved'));

        $draft->refresh();
        $this->assertSame('Version 2', $draft->subject);
        // The first version is gone on the server and locally, only the new one exists.
        $this->assertCount(1, $this->provider->messages['Drafts']);
        $this->assertSame('Version 2', array_values($this->provider->messages['Drafts'])[0]->subject);
        $this->assertSoftDeleted($linked);

        $sync->syncFolder($this->drafts->refresh(), $this->provider);

        $this->assertSame(1, MailboxMessage::count());
        $this->assertSame($draft->id, MailboxMessage::sole()->draft_id);
        $this->assertSame('Version 2', MailboxMessage::sole()->subject);
    }

    public function test_the_version_is_linked_by_message_id_when_the_server_reports_no_uid(): void
    {
        $draft = $this->saveDraft(['to' => ['jane@example.com'], 'subject' => 'No UIDPLUS', 'format' => 'text', 'body' => 'One']);
        $draft->forceFill(['remote_id' => null, 'remote_folder_id' => null])->saveQuietly();

        app(SyncService::class)->syncFolder($this->drafts->refresh(), $this->provider);

        $message = MailboxMessage::sole();
        $this->assertSame($draft->id, $message->draft_id);
        $this->assertSame($message->remote_id, $draft->refresh()->remote_id);
        $this->assertSame([[$this->drafts->id, $message->remote_id]], app(DraftService::class)->serverVersions($draft));
    }

    public function test_autosave_only_saves_changes(): void
    {
        $draft = $this->saveDraft(['to' => ['jane@example.com'], 'subject' => 'Autosave', 'format' => 'text', 'body' => 'Start']);
        $updatedAt = $draft->updated_at;

        Carbon::setTestNow(now()->addMinute());

        $component = $this->editDraft($draft)->call('autosave');
        $this->assertTrue($draft->refresh()->updated_at->equalTo($updatedAt));

        // Invalid input is not reported while typing.
        $component->fillForm(['body' => 'Changed', 'to' => ['not-an-address']])
            ->call('autosave')
            ->assertHasNoErrors();
        $this->assertSame('Start', $draft->refresh()->body);

        $component->fillForm(['to' => ['jane@example.com']])->call('autosave');
        $this->assertSame('Changed', $draft->refresh()->body);
        $this->assertTrue($draft->updated_at->greaterThan($updatedAt));
    }

    public function test_sending_a_draft_removes_it_with_attachments_and_server_version(): void
    {
        Mail::fake();

        $draft = $this->saveDraft([
            'to' => [],
            'subject' => '',
            'format' => 'text',
            'body' => 'Report attached',
            'attachments' => [UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1')],
        ]);
        $attachment = $draft->attachments()->sole();
        Storage::disk('local')->assertExists($attachment->storage_path);

        // Sending requires the fields that saving does not.
        $this->editDraft($draft)
            ->call('send')
            ->assertHasFormErrors(['to' => 'required', 'subject' => 'required']);

        Mail::assertNothingSent();

        $this->editDraft($draft)
            ->fillForm(['to' => ['jane@example.com'], 'subject' => 'Report'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.actions.compose.success'))
            ->assertRedirect(MailboxResource::getUrl('browse', ['record' => $this->mailbox]));

        Mail::assertSent(OutgoingMessage::class, function (OutgoingMessage $mail): bool {
            return $mail->hasTo('jane@example.com')
                && $mail->data->body === 'Report attached'
                && $mail->data->headers === []
                // Fixed by the outbox, so a retry keeps the Message-ID.
                && $mail->data->messageId === MailboxOutgoingMessage::query()->sole()->message_id
                && array_map(fn ($attachment) => $attachment->contents, $mail->data->attachments) === ['%PDF-1'];
        });

        $this->assertModelMissing($draft);
        $this->assertSame(0, $draft->attachments()->count());
        Storage::disk('local')->assertMissing($attachment->storage_path);
        $this->assertSame([], $this->provider->messages['Drafts'] ?? []);
    }

    public function test_the_draft_header_is_not_part_of_sent_messages(): void
    {
        $draft = $this->saveDraft(['to' => ['jane@example.com'], 'subject' => 'Hi', 'format' => 'text', 'body' => 'Hi']);
        $drafts = app(DraftService::class);

        $raw = app(MimeMessageBuilder::class)->build($this->mailbox, $drafts->outgoing($draft, $drafts->formState($draft), $this->user));

        $this->assertStringNotContainsString(DraftService::HEADER, $raw);
    }

    public function test_discarding_a_draft(): void
    {
        $draft = $this->saveDraft(['to' => ['jane@example.com'], 'subject' => 'Discard me', 'format' => 'text', 'body' => 'x']);

        $this->editDraft($draft)
            ->callAction('discard')
            ->assertNotified(__('filament-mailbox::mailbox.drafts.discarded'))
            ->assertRedirect(MailboxResource::getUrl('drafts', ['record' => $this->mailbox]));

        $this->assertModelMissing($draft);
        $this->assertSame([], $this->provider->messages['Drafts'] ?? []);
    }

    public function test_reply_and_forward_drafts_keep_threading_and_original_attachments(): void
    {
        Mail::fake();

        $original = MailboxMessage::factory()->for($this->inbox, 'folder')->create([
            'message_id' => '<original@example.com>',
            'references' => '<root@example.com>',
            'thread_id' => 'thread-1',
            'from_name' => 'John Doe',
            'from_address' => 'john@example.com',
            'subject' => 'Invoice',
            'text_body' => 'Please check.',
            'html_body' => null,
            'has_attachments' => true,
            'sent_at' => Carbon::parse('2026-09-17 08:42:00'),
        ]);
        Storage::disk('local')->put('mailbox/invoice.pdf', 'INVOICE');
        $invoice = MailboxAttachment::factory()->for($original, 'message')->create(['filename' => 'invoice.pdf', 'size' => 7, 'disk' => 'local', 'storage_path' => 'mailbox/invoice.pdf']);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $original->id])
            ->mountAction('reply')
            ->fillForm(['format' => 'text', 'body' => 'Checking'])
            ->callMountedAction(['draft' => true])
            ->assertHasNoFormErrors();

        $reply = MailboxDraft::where('mode', 'reply')->sole();
        $this->assertSame($original->id, $reply->reply_to_message_id);
        $this->assertSame('original@example.com', $reply->in_reply_to);
        $this->assertStringContainsString('> Please check.', (string) $reply->quoted);

        $this->editDraft($reply)
            ->assertSchemaStateSet(['subject' => 'Re: Invoice', 'body' => 'Checking'])
            ->call('send')
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->data->inReplyTo === 'original@example.com'
            && $mail->data->references === ['root@example.com', 'original@example.com']
            && $mail->data->providerThreadId === 'thread-1'
            && str_contains($mail->data->body, "Checking\n\nOn "));

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $original->id])
            ->mountAction('forward')
            ->fillForm(['to' => ['accounting@example.com'], 'format' => 'text', 'original_attachments' => [$invoice->id]])
            ->callMountedAction(['draft' => true])
            ->assertHasNoFormErrors();

        $forward = MailboxDraft::where('mode', 'forward')->sole();
        $this->assertSame([$invoice->id], $forward->original_attachment_ids);

        $this->editDraft($forward)
            ->assertSchemaStateSet(['original_attachments' => [$invoice->id], 'as_attachment' => false])
            ->call('send')
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->hasTo('accounting@example.com')
            && $mail->data->forwardedMessageId === 'original@example.com'
            && array_map(fn ($attachment) => $attachment->filename, $mail->data->attachments) === ['invoice.pdf']);

        $this->assertNotNull($original->refresh()->forwarded_at);
    }

    public function test_inline_images_are_moved_into_the_draft_directory(): void
    {
        Mail::fake();
        $compose = InlineImageProcessor::composeDirectory($this->user->id);
        Storage::disk('local')->put($compose.'/chart.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4//8/AAX+Av6nNYGEAAAAAElFTkSuQmCC'));

        $draft = $this->saveDraft(['to' => ['jane@example.com'], 'subject' => 'Chart', 'format' => 'html', 'body_html' => '<p>See <img data-id="'.$compose.'/chart.png" alt="Chart"></p>']);

        $this->assertStringContainsString('data-id="'.$draft->directory().'/chart.png"', (string) $draft->body_html);
        Storage::disk('local')->assertExists($draft->directory().'/chart.png');

        // Compose uploads may be pruned; the draft keeps its copy.
        Storage::disk('local')->delete($compose.'/chart.png');

        $this->editDraft($draft)->call('send')->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => ($mail->data->attachments[0] ?? null)?->inline === true);
        Storage::disk('local')->assertMissing($draft->directory().'/chart.png');
    }

    public function test_drafts_of_other_clients_are_imported_for_editing(): void
    {
        $raw = "From: support@example.com\r\nTo: Jane <jane@example.com>\r\nCc: team@example.com\r\nBcc: secret@example.com\r\nSubject: Written in Thunderbird\r\nMessage-ID: <tb-draft@example.com>\r\n\r\nHello from Thunderbird\r\n";
        $this->provider->addMessage('Drafts', new MessageData('7', subject: 'Written in Thunderbird', textBody: $raw));

        $message = MailboxMessage::factory()->for($this->drafts, 'folder')->create([
            'remote_id' => '1:7',
            'is_draft' => true,
            'message_id' => '<tb-draft@example.com>',
            'to' => [['address' => 'jane@example.com', 'name' => 'Jane']],
            'cc' => [['address' => 'team@example.com', 'name' => null]],
            'subject' => 'Written in Thunderbird',
            'text_body' => 'Hello from Thunderbird',
            'html_body' => null,
            'has_attachments' => true,
        ]);
        Storage::disk('local')->put('mailbox/notes.txt', 'NOTES');
        MailboxAttachment::factory()->for($message, 'message')->create(['filename' => 'notes.txt', 'size' => 5, 'disk' => 'local', 'storage_path' => 'mailbox/notes.txt']);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->callAction('editDraft')
            ->assertRedirect(MailboxResource::getUrl('draft', ['record' => $this->mailbox, 'draft' => MailboxDraft::sole()]));

        $draft = MailboxDraft::sole();
        $this->assertSame(['jane@example.com'], $draft->to);
        $this->assertSame(['team@example.com'], $draft->cc);
        $this->assertSame(['secret@example.com'], $draft->bcc);
        $this->assertSame('Hello from Thunderbird', $draft->body);
        $this->assertSame('1:7', $draft->remote_id);
        $this->assertSame($draft->id, $message->refresh()->draft_id);
        $this->assertSame('NOTES', Storage::disk('local')->get($draft->attachments()->sole()->storage_path));

        // Saving replaces the version of the other client.
        $this->editDraft($draft)->fillForm(['body' => 'Edited in the app'])->call('save');

        $this->assertArrayNotHasKey(7, $this->provider->messages['Drafts']);
        $this->assertSoftDeleted($message);
        $this->assertCount(1, $this->provider->messages['Drafts']);
    }

    public function test_drafts_are_private_to_their_author(): void
    {
        $other = User::make('Other');
        $this->mailbox->users()->attach($other);
        $foreign = MailboxDraft::factory()->for($this->mailbox)->by($other)->create(['subject' => 'Foreign']);
        $own = MailboxDraft::factory()->for($this->mailbox)->by($this->user)->create(['subject' => 'Own']);

        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => false);

        $this->get(MailboxResource::getUrl('draft', ['record' => $this->mailbox, 'draft' => $foreign]))->assertForbidden();
        $this->get(MailboxResource::getUrl('draft', ['record' => $this->mailbox, 'draft' => $own]))->assertSuccessful();
        $this->get(MailboxResource::getUrl('drafts', ['record' => $this->mailbox]))->assertSuccessful();

        Livewire::test(ListDrafts::class, ['record' => $this->mailbox->id])
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);

        // Drafts of another mailbox are not found through this mailbox.
        $elsewhere = MailboxDraft::factory()->by($this->user)->create();
        $this->get(MailboxResource::getUrl('draft', ['record' => $this->mailbox, 'draft' => $elsewhere]))->assertNotFound();

        // Managers see and open every draft.
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => true);

        Livewire::test(ListDrafts::class, ['record' => $this->mailbox->id])
            ->assertCanSeeTableRecords([$own, $foreign]);
        $this->get(MailboxResource::getUrl('draft', ['record' => $this->mailbox, 'draft' => $foreign]))->assertSuccessful();

        // Without the send ability drafts cannot be used.
        app(MailboxAuthorization::class)->using(MailboxAuthorization::SEND, fn () => false);
        $this->get(MailboxResource::getUrl('draft', ['record' => $this->mailbox, 'draft' => $own]))->assertForbidden();
    }

    public function test_drafts_stay_local_without_append_support(): void
    {
        $this->provider->capabilities = [ProviderCapability::Folders, ProviderCapability::Flags];
        app(ProviderCapabilities::class)->flush();

        $draft = $this->saveDraft(['to' => ['jane@example.com'], 'subject' => 'Local only', 'format' => 'text', 'body' => 'x']);

        $this->assertNull($draft->remote_id);
        $this->assertNull($draft->server_synced_at);
        $this->assertArrayNotHasKey('Drafts', $this->provider->raw);
    }

    public function test_drafts_folder_lists_recipients_and_opens_the_editor(): void
    {
        $draft = $this->saveDraft(['to' => ['jane@example.com'], 'subject' => 'Listed', 'format' => 'text', 'body' => 'x']);
        app(SyncService::class)->syncFolder($this->drafts->refresh(), $this->provider);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id, 'folder' => $this->drafts->id])
            ->assertCanSeeTableRecords([MailboxMessage::sole()])
            ->assertTableColumnVisible('to')
            ->assertTableColumnHidden('from_name')
            ->assertSee(MailboxResource::getUrl('draft', ['record' => $this->mailbox, 'draft' => $draft]));
    }

    public function test_prune_command_discards_old_drafts(): void
    {
        $old = MailboxDraft::factory()->for($this->mailbox)->by($this->user)->create();
        $old->forceFill(['updated_at' => now()->subDays(40)])->saveQuietly();
        $recent = MailboxDraft::factory()->for($this->mailbox)->by($this->user)->create();

        $this->artisan('mailbox:prune-drafts')->assertSuccessful();
        $this->assertModelExists($old);

        $this->artisan('mailbox:prune-drafts', ['--days' => 30])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveDraft(array $data): MailboxDraft
    {
        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->fillForm($data)
            ->callMountedAction(['draft' => true])
            ->assertHasNoFormErrors();

        // Notifications of saving must not satisfy assertions of later steps.
        session()->forget('filament');

        return MailboxDraft::query()->latest('id')->firstOrFail();
    }

    protected function editDraft(MailboxDraft $draft): Testable
    {
        return Livewire::test(EditDraft::class, ['record' => $this->mailbox->id, 'draft' => $draft->id]);
    }
}
