<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Gmail;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\SyncResult;
use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\Providers\DefaultMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Providers\Gmail\GmailClient;
use Cooolinho\FilamentMailbox\Providers\Gmail\GmailHistoryProcessor;
use Cooolinho\FilamentMailbox\Providers\Gmail\GmailLabelMapper;
use Cooolinho\FilamentMailbox\Providers\Gmail\GmailProvider;
use Cooolinho\FilamentMailbox\Services\LabelService;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeGmailServer;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

class GmailProviderTest extends TestCase
{
    protected FakeGmailServer $server;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();

        $this->app->bind(MailboxProviderFactory::class, DefaultMailboxProviderFactory::class);
        $this->server = (new FakeGmailServer)->fake();
        $this->mailbox = Mailbox::factory()->create([
            'name' => 'Me',
            'email' => 'me@example.com',
            'provider' => ProviderType::Gmail,
            'auth_mode' => AuthMode::OAuth,
            'oauth_connection_id' => OAuthConnection::factory()->for(OAuthApplication::factory()->google(), 'application')->create()->id,
            'host' => null, 'port' => null, 'encryption' => null, 'username' => null, 'password' => null,
            'initial_sync_days' => 30,
        ]);
        $this->mailbox->users()->attach($this->user);
    }

    public function test_initial_sync_imports_recent_messages_into_their_primary_folder(): void
    {
        config(['filament-mailbox.sync.chunk_size' => 2]);

        $customer = $this->server->addLabel('Kunden/Acme');
        $inbox = $this->server->addMessage('Inbox', ['INBOX', 'UNREAD', 'IMPORTANT', 'CATEGORY_PROMOTIONS', $customer], threadId: 'thread-a');
        $sent = $this->server->addMessage('Sent', ['SENT', 'INBOX']);
        $archived = $this->server->addMessage('Archived', ['STARRED', $customer]);
        $trashed = $this->server->addMessage('Trashed', ['TRASH', 'INBOX']);
        $this->server->addMessage('Too old', ['INBOX'], daysAgo: 60);

        $result = $this->sync();

        $this->assertSame([], $result->errors);
        $this->assertSame(4, $result->imported);
        $this->assertSame(4, MailboxMessage::count());

        $folders = $this->mailbox->folders()->get()->keyBy('remote_id');
        $this->assertSame(SpecialUse::Archive, $folders[GmailLabelMapper::ARCHIVE]->special_use);

        $messages = MailboxMessage::with('folder', 'labels')->get()->keyBy('remote_id');
        $this->assertSame('INBOX', $messages[$inbox]->folder->remote_id);
        $this->assertSame('INBOX', $messages[$sent]->folder->remote_id);
        $this->assertSame(GmailLabelMapper::ARCHIVE, $messages[$archived]->folder->remote_id);
        $this->assertSame('TRASH', $messages[$trashed]->folder->remote_id);

        $this->assertFalse($messages[$inbox]->is_read);
        $this->assertTrue($messages[$archived]->is_flagged);
        $this->assertSame('thread-a', $messages[$inbox]->thread_id);
        $this->assertSame(now()->subDay()->toDateString(), $messages[$inbox]->received_at->toDateString());

        // User labels only; system and category labels are no labels.
        $this->assertSame([$customer], $messages[$inbox]->labels->pluck('remote_key')->all());
        $this->assertSame('Kunden/Acme', MailboxLabel::sole()->name);
        $this->assertSame(LabelSource::Gmail, MailboxLabel::sole()->source);

        $this->assertSame(['history_id' => (string) 1005], $this->mailbox->refresh()->sync_cursor);
        $this->assertContains('POST batch', $this->server->requests);
    }

    public function test_history_sync_applies_changes_without_duplicates(): void
    {
        $inbox = $this->server->addMessage('Inbox', ['INBOX', 'UNREAD']);
        $gone = $this->server->addMessage('Gone', ['INBOX']);
        $this->sync();

        $new = $this->server->addMessage('New', ['INBOX', 'UNREAD']);
        $this->server->modify($inbox, ['STARRED'], ['UNREAD', 'INBOX']);
        $this->server->deleteForever($gone);
        $this->server->requests = [];

        $this->assertSame(1, $this->sync()->imported);

        $message = MailboxMessage::where('remote_id', $inbox)->sole();
        $this->assertSame(GmailLabelMapper::ARCHIVE, $message->folder->remote_id);
        $this->assertTrue($message->is_read);
        $this->assertTrue($message->is_flagged);
        $this->assertSoftDeleted(MailboxMessage::withTrashed()->where('remote_id', $gone)->sole());
        $this->assertSame('New', MailboxMessage::where('remote_id', $new)->value('subject'));
        $this->assertSame(3, MailboxMessage::withTrashed()->count());
        $this->assertFalse(collect($this->server->requests)->contains(fn (string $request): bool => str_starts_with($request, 'GET messages?')));
    }

    public function test_expired_history_triggers_a_limited_resync(): void
    {
        $kept = $this->server->addMessage('Kept', ['INBOX']);
        $this->sync();
        MailboxMessage::where('remote_id', $kept)->update(['subject' => 'Local subject']);

        $this->server->oldestHistoryId = PHP_INT_MAX;
        $this->server->addMessage('Newer', ['INBOX']);

        $this->assertSame(1, $this->sync()->imported);
        $this->assertSame('Local subject', MailboxMessage::where('remote_id', $kept)->value('subject'));
        $this->assertSame(2, MailboxMessage::withTrashed()->count());
        $this->assertArrayHasKey('history_id', $this->mailbox->refresh()->sync_cursor);
    }

    public function test_message_actions_modify_labels(): void
    {
        $id = $this->server->addMessage('Action', ['INBOX', 'UNREAD']);
        $this->sync();
        $message = MailboxMessage::where('remote_id', $id)->sole();
        $service = $this->app->make(MessageService::class);

        $service->markRead($message);
        $service->setFlagged($message, true);
        $this->assertSame(['INBOX', 'STARRED'], $this->server->messages[$id]['labelIds']);

        $service->move($message, MailboxFolder::where('remote_id', GmailLabelMapper::ARCHIVE)->sole());
        $this->assertSame(['STARRED'], $this->server->messages[$id]['labelIds']);
        $this->assertSame($id, $message->refresh()->remote_id);

        $service->delete($message->refresh());
        $this->assertContains('TRASH', $this->server->messages[$id]['labelIds']);

        // In the trash: no permanent deletion.
        $trashed = MailboxMessage::factory()->for(MailboxFolder::where('remote_id', 'TRASH')->sole(), 'folder')->create(['remote_id' => $id]);
        $this->expectException(UnsupportedOperation::class);
        $service->delete($trashed);
    }

    public function test_delete_is_offered_as_move_to_trash_only(): void
    {
        $inbox = $this->server->addMessage('In inbox', ['INBOX']);
        $trash = $this->server->addMessage('In trash', ['TRASH']);
        $this->sync();

        $inInbox = MailboxMessage::where('remote_id', $inbox)->sole();
        $inTrash = MailboxMessage::where('remote_id', $trash)->sole();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertActionVisible(TestAction::make('deleteMessage')->table($inInbox))
            ->assertActionHasLabel(TestAction::make('deleteMessage')->table($inInbox), __('filament-mailbox::mailbox.actions.delete.trash_label'));

        Livewire::withQueryParams(['folder' => $inTrash->folder_id])
            ->test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertActionHidden(TestAction::make('deleteMessage')->table($inTrash));
    }

    public function test_labels_are_managed_through_gmail(): void
    {
        $id = $this->server->addMessage('Labelled', ['INBOX']);
        $this->sync();

        $labels = $this->app->make(LabelService::class);
        $label = $labels->create($this->mailbox, 'Rechnungen');

        $this->assertStringStartsWith('Label_', $label->remote_key);
        $this->assertSame('Rechnungen', $this->server->labels[$label->remote_key]['name']);

        $labels->attach(MailboxMessage::where('remote_id', $id)->sole(), $label);
        $this->assertContains($label->remote_key, $this->server->messages[$id]['labelIds']);

        $labels->update($label, 'Rechnungen 2026', null);
        $this->assertSame('Rechnungen 2026', $this->server->labels[$label->remote_key]['name']);

        $labels->delete($label);
        $this->assertArrayNotHasKey($label->remote_key, $this->server->labels);
    }

    public function test_replies_are_sent_in_the_gmail_thread(): void
    {
        $this->server->addMessage('Question', ['INBOX'], raw: "From: John <john@example.com>\r\nTo: me@example.com\r\nSubject: Question\r\nMessage-ID: <question@example.com>\r\n\r\nHi\r\n", threadId: 'thread-42');
        $this->sync();
        $message = MailboxMessage::sole();

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->callAction('reply', data: ['to' => ['john@example.com'], 'subject' => 'Re: Question', 'format' => 'text', 'body' => 'Answer'])
            ->assertHasNoFormErrors();

        $sent = $this->server->sent[0];
        $raw = base64_decode(strtr($sent['raw'], '-_', '+/'));

        $this->assertSame('thread-42', $sent['threadId']);
        $this->assertStringContainsString('In-Reply-To: <question@example.com>', $raw);
        $this->assertStringContainsString('References: <question@example.com>', $raw);
        $this->assertStringContainsString('Subject: Re: Question', $raw);
    }

    public function test_rate_limits_and_throttling_are_retried(): void
    {
        $this->server->throttle = 1;
        $this->server->rateLimited = 1;

        (new GmailProvider($this->mailbox))->testConnection();

        Sleep::assertSleptTimes(2);
    }

    public function test_batch_responses_are_parsed(): void
    {
        $body = "--b\r\nContent-Type: application/http\r\nContent-ID: <response-item-1>\r\n\r\nHTTP/1.1 404 Not Found\r\nContent-Type: application/json\r\n\r\n{\"error\":{\"code\":404}}\r\n"
            ."--b\r\nContent-Type: application/http\r\nContent-ID: <response-item-0>\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{\"id\":\"abc\",\"labelIds\":[\"INBOX\"]}\r\n--b--";

        $parts = GmailClient::parseBatch(new Response(new Psr7Response(200, ['Content-Type' => 'multipart/mixed; boundary=b'], $body)));

        $this->assertSame([200, ['id' => 'abc', 'labelIds' => ['INBOX']]], $parts[0]);
        $this->assertSame(404, $parts[1][0]);
    }

    public function test_label_mapping(): void
    {
        $this->assertSame('TRASH', GmailLabelMapper::folder(['INBOX', 'TRASH']));
        $this->assertSame('SPAM', GmailLabelMapper::folder(['SPAM', 'UNREAD']));
        $this->assertSame('INBOX', GmailLabelMapper::folder(['SENT', 'INBOX']));
        $this->assertSame(GmailLabelMapper::ARCHIVE, GmailLabelMapper::folder(['Label_1', 'IMPORTANT']));

        $flags = GmailLabelMapper::flags(['UNREAD', 'STARRED', 'Label_1', 'CATEGORY_SOCIAL', 'IMPORTANT', 'CHAT']);
        $this->assertFalse($flags->seen);
        $this->assertTrue($flags->flagged);
        $this->assertSame(['Label_1'], $flags->keywords);

        $this->assertNull(GmailLabelMapper::label(['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system']));
        $this->assertSame('Kunden/Acme', GmailLabelMapper::label(['id' => 'Label_9', 'name' => 'Kunden/Acme', 'type' => 'user'])->name);
    }

    public function test_history_processing(): void
    {
        [$changed, $deleted] = GmailHistoryProcessor::process([
            ['id' => '1', 'messagesAdded' => [['message' => ['id' => 'a']], ['message' => ['id' => 'b']]]],
            ['id' => '2', 'labelsAdded' => [['message' => ['id' => 'a']]]],
            ['id' => '3', 'messagesDeleted' => [['message' => ['id' => 'b']]]],
            ['id' => '4', 'labelsRemoved' => [['message' => ['id' => 'b']]], 'messagesAdded' => [['message' => ['id' => '123']]]],
        ]);

        $this->assertSame(['a', '123'], $changed);
        $this->assertSame(['b'], $deleted);
    }

    protected function sync(): SyncResult
    {
        return $this->app->make(SyncService::class)->syncMailbox($this->mailbox->refresh());
    }
}
