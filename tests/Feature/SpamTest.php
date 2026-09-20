<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\AddressData;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\MessagesMarkedAsNotSpam;
use Cooolinho\FilamentMailbox\Events\MessagesMarkedAsSpam;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers\BlockedSendersRelationManager;
use Cooolinho\FilamentMailbox\Jobs\ApplyBlockedSendersJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxBlockedSender;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\BlockedSenderMatcher;
use Cooolinho\FilamentMailbox\Services\HtmlBodySanitizer;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;

class SpamTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $junk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))
            ->addFolder(new FolderData('Junk', 'Junk', '/', SpecialUse::Junk));

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->junk = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Junk)->create(['name' => 'Junk', 'full_name' => 'Junk']);
    }

    public function test_mark_as_spam_sets_keywords_before_moving(): void
    {
        Event::fake([MessagesMarkedAsSpam::class]);

        $message = $this->message($this->inbox, 1, ['keywords' => ['$NotJunk', 'Kunde']]);

        $moves = app(MessageService::class)->markAsSpam($message);

        $this->assertSame([$message->id => $this->inbox->id], $moves);
        $this->assertSame(['setFlags', 'moveMessage'], array_column($this->provider->calls, 0));
        [$add, $remove] = $this->provider->calls[0][2];
        $this->assertSame(['$Junk'], $add->keywords);
        $this->assertSame(['$NotJunk'], $remove->keywords);

        $message->refresh();
        $this->assertSame($this->junk->id, $message->folder_id);
        $this->assertSame(['Kunde', '$Junk'], $message->keywords);
        $this->assertSame(['Kunde', '$Junk'], $this->provider->messages['Junk'][1]->flags->keywords);

        Event::assertDispatched(MessagesMarkedAsSpam::class, fn (MessagesMarkedAsSpam $event): bool => $event->messageIds === [$message->id]);
    }

    public function test_mark_as_not_spam_moves_to_the_inbox(): void
    {
        Event::fake([MessagesMarkedAsNotSpam::class]);

        $message = $this->message($this->junk, 1, ['keywords' => ['$Junk']]);

        app(MessageService::class)->markAsNotSpam($message);

        $message->refresh();
        $this->assertSame($this->inbox->id, $message->folder_id);
        $this->assertSame(['$NotJunk'], $message->keywords);
        Event::assertDispatched(MessagesMarkedAsNotSpam::class);
    }

    public function test_without_keyword_support_only_the_move_happens(): void
    {
        $this->provider->capabilities = [ProviderCapability::Folders, ProviderCapability::MoveMessages];
        $message = $this->message($this->inbox, 1);

        app(MessageService::class)->markAsSpam($message);

        $this->assertSame(['moveMessage'], array_column($this->provider->calls, 0));
        $this->assertSame($this->junk->id, $message->refresh()->folder_id);
        $this->assertNull($message->keywords);
    }

    public function test_keyword_failure_does_not_prevent_the_move(): void
    {
        $failing = new class extends FakeMailboxProvider
        {
            public function setFlags(MessageIdentifier $message, MessageFlags $add, MessageFlags $remove): void
            {
                throw new RuntimeException('keywords not permitted');
            }
        };
        $failing->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))->addFolder(new FolderData('Junk', 'Junk', '/', SpecialUse::Junk))->addMessage('INBOX', 1);
        $this->app->instance(MailboxProviderFactory::class, new FakeMailboxProviderFactory($failing));

        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['remote_id' => '1:1']);

        app(MessageService::class)->markAsSpam($message);

        $this->assertSame($this->junk->id, $message->refresh()->folder_id);
        $this->assertNull($message->keywords);
    }

    public function test_configured_spam_folder_is_used(): void
    {
        $this->junk->forceFill(['special_use' => null])->save();
        $message = $this->message($this->inbox, 1);

        $this->assertFalse(app(MessageService::class)->canMarkAsSpam($message));

        $this->mailbox->forceFill(['spam_folder_id' => $this->junk->id])->save();
        $message->refresh();

        $this->assertTrue(app(MessageService::class)->canMarkAsSpam($message));
        app(MessageService::class)->markAsSpam($message);

        $message->refresh();
        $this->assertSame($this->junk->id, $message->folder_id);
        $this->assertTrue(app(MessageService::class)->isSpam($message));
    }

    public function test_row_and_bulk_actions_depend_on_the_folder(): void
    {
        $inboxMessages = collect([1, 2])->map(fn (int $uid) => $this->message($this->inbox, $uid));
        $spam = $this->message($this->junk, 1);

        $this->browse()
            ->assertActionVisible(TestAction::make('markAsSpam')->table($inboxMessages[0]))
            ->assertActionHidden(TestAction::make('markAsNotSpam')->table($inboxMessages[0]))
            ->assertActionHidden(TestAction::make('markAsNotSpam')->table()->bulk())
            ->selectTableRecords($inboxMessages)
            ->callAction(TestAction::make('markAsSpam')->table()->bulk())
            ->assertNotified(__('filament-mailbox::mailbox.actions.spam.success'));

        $this->assertSame(3, MailboxMessage::where('folder_id', $this->junk->id)->count());

        $this->browse(['folder' => $this->junk->id])
            ->assertActionHidden(TestAction::make('markAsSpam')->table($spam))
            ->assertActionHidden(TestAction::make('markAsSpam')->table()->bulk())
            ->callAction(TestAction::make('markAsNotSpam')->table($spam))
            ->assertNotified(__('filament-mailbox::mailbox.actions.not_spam.success'));

        $this->assertSame($this->inbox->id, $spam->refresh()->folder_id);
    }

    public function test_view_page_spam_actions_redirect_to_the_folder(): void
    {
        $message = $this->message($this->inbox, 1, ['is_read' => true]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertActionHidden('markAsNotSpam')
            ->callAction('markAsSpam')
            ->assertRedirect(BrowseMailbox::getUrl(['record' => $this->mailbox, 'folder' => $this->inbox->id]));

        $this->assertSame($this->junk->id, $message->refresh()->folder_id);
    }

    public function test_spam_is_rendered_strictly(): void
    {
        $html = '<p>Win <a href="https://phish.example/login">now</a></p>';

        $normal = app(HtmlBodySanitizer::class)->document($html);
        $strict = app(HtmlBodySanitizer::class)->document($html, strict: true);

        $this->assertStringContainsString('<a href="https://phish.example/login"', $normal);
        $this->assertStringNotContainsString('<a', $strict);
        $this->assertStringNotContainsString('phish.example', $strict);
        $this->assertStringNotContainsString('<base', $strict);
        $this->assertStringContainsString('Win now', $strict);

        $message = $this->message($this->junk, 1, ['is_read' => true, 'html_body' => $html, 'has_attachments' => true]);
        $attachment = MailboxAttachment::factory()->for($message, 'message')->create(['filename' => 'invoice.pdf.exe']);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertSee(__('filament-mailbox::mailbox.spam.callout.heading'))
            ->assertSee('invoice.pdf.exe')
            ->assertDontSee('phish.example')
            ->assertDontSee('filament-mailbox/attachments/'.$attachment->id);
    }

    public function test_strict_rendering_can_be_disabled(): void
    {
        config(['filament-mailbox.spam.strict_rendering' => false]);

        $message = $this->message($this->junk, 1, ['is_read' => true]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertDontSee(__('filament-mailbox::mailbox.spam.callout.heading'));
    }

    public function test_blocked_sender_matcher(): void
    {
        $matcher = new BlockedSenderMatcher;

        $this->assertTrue($matcher->matches('Spammer@Example.com', ['spammer@example.com']));
        $this->assertTrue($matcher->matches('anyone@example.com', ['*@EXAMPLE.com']));
        $this->assertFalse($matcher->matches('anyone@mail.example.com', ['*@example.com']));
        $this->assertFalse($matcher->matches('other@example.org', ['spammer@example.com', '*@example.com']));
        $this->assertFalse($matcher->matches('a.b@example.com', ['a*b@example.com']));
        $this->assertFalse($matcher->matches(null, ['*@example.com']));

        $this->assertTrue(BlockedSenderMatcher::isValid('user@example.com'));
        $this->assertTrue(BlockedSenderMatcher::isValid('*@example.com'));
        $this->assertFalse(BlockedSenderMatcher::isValid('*.example.com'));
        $this->assertFalse(BlockedSenderMatcher::isValid('.*@example.com'));
    }

    public function test_sync_moves_new_inbox_messages_of_blocked_senders_to_spam(): void
    {
        MailboxBlockedSender::factory()->for($this->mailbox)->create(['pattern' => '*@spam.example']);

        $this->provider
            ->addMessage('INBOX', new MessageData('1', from: new AddressData('offer@SPAM.example'), subject: 'Offer'))
            ->addMessage('INBOX', new MessageData('2', from: new AddressData('friend@example.com'), subject: 'Hello'));

        Queue::fake();
        app(SyncService::class)->syncFolder($this->inbox, $this->provider);

        Queue::assertPushed(ApplyBlockedSendersJob::class, fn (ApplyBlockedSendersJob $job): bool => count($job->messageIds) === 2);

        $job = Queue::pushedJobs()[ApplyBlockedSendersJob::class][0]['job'];
        app()->call([$job, 'handle']);

        $this->assertSame(['Offer'], MailboxMessage::where('folder_id', $this->junk->id)->pluck('subject')->all());
        $this->assertSame(['Hello'], MailboxMessage::where('folder_id', $this->inbox->id)->pluck('subject')->all());
    }

    public function test_sync_without_blocked_senders_dispatches_nothing(): void
    {
        $this->provider->addMessage('INBOX', 1);

        Queue::fake();
        app(SyncService::class)->syncFolder($this->inbox, $this->provider);

        Queue::assertNotPushed(ApplyBlockedSendersJob::class);
    }

    public function test_block_sender_action_blocks_the_domain_and_moves_the_message(): void
    {
        $message = $this->message($this->inbox, 1, ['is_read' => true, 'from_address' => 'Offer@Spam.example']);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->mountAction('blockSender')
            ->assertSchemaStateSet(['pattern' => 'offer@spam.example', 'move_to_spam' => true])
            ->fillForm(['pattern' => '*@spam.example'])
            ->callMountedAction()
            ->assertRedirect();

        $this->assertSame(['*@spam.example'], $this->mailbox->blockedSenders()->pluck('pattern')->all());
        $this->assertSame($this->junk->id, $message->refresh()->folder_id);
    }

    public function test_block_sender_requires_managing_the_mailbox(): void
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => false);
        $message = $this->message($this->inbox, 1, ['is_read' => true, 'from_address' => 'offer@spam.example']);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertActionHidden('blockSender');
    }

    public function test_blocked_senders_relation_manager_validates_patterns(): void
    {
        MailboxBlockedSender::factory()->for($this->mailbox)->create(['pattern' => 'known@example.com']);

        $manager = fn () => Livewire::test(BlockedSendersRelationManager::class, ['ownerRecord' => $this->mailbox, 'pageClass' => EditMailbox::class]);

        $manager()
            ->callAction(TestAction::make('create')->table(), ['pattern' => 'no-address'])
            ->assertHasFormErrors(['pattern']);

        $manager()
            ->callAction(TestAction::make('create')->table(), ['pattern' => 'KNOWN@example.com'])
            ->assertHasFormErrors(['pattern']);

        $manager()
            ->callAction(TestAction::make('create')->table(), ['pattern' => ' *@Spam.Example '])
            ->assertHasNoFormErrors();

        $this->assertTrue($this->mailbox->blockedSenders()->where('pattern', '*@spam.example')->exists());
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function browse(array $query = []): Testable
    {
        return Livewire::withQueryParams($query)->test(BrowseMailbox::class, ['record' => $this->mailbox->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function message(MailboxFolder $folder, int $uid, array $attributes = []): MailboxMessage
    {
        $this->provider->addMessage($folder->remote_id, new MessageData((string) $uid, flags: new MessageFlags(keywords: $attributes['keywords'] ?? [])));

        return MailboxMessage::factory()->for($folder, 'folder')->create(['remote_id' => '1:'.$uid, ...$attributes]);
    }
}
