<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use RuntimeException;

class ViewMessageTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create(['sync_cursor' => ['uid_validity' => 7, 'last_uid' => 100]]);
    }

    public function test_it_displays_the_message_and_marks_it_as_read(): void
    {
        $message = $this->message([
            'subject' => 'Projekt Update',
            'from_name' => 'John Doe',
            'from_address' => 'john@example.com',
            'to' => [['address' => 'jane@example.com', 'name' => 'Jane']],
            'cc' => [['address' => 'team@example.com', 'name' => null]],
            'text_body' => "Line 1\nLine <2>",
        ]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->getRouteKey(), 'message' => $message->id])
            ->assertOk()
            ->assertSee('Projekt Update')
            ->assertSee('John Doe &lt;john@example.com&gt;', false)
            ->assertSee('Jane &lt;jane@example.com&gt;', false)
            ->assertSee('team@example.com')
            ->assertSee('Line 1<br />', false)
            ->assertSee('Line &lt;2&gt;', false);

        $this->assertTrue($message->refresh()->is_read);
        $this->assertSame('markRead', $this->provider->calls[0][0]);
        $this->assertSame('INBOX', $this->provider->calls[0][1]->folder->remoteId);
        $this->assertSame($message->remote_id, $this->provider->calls[0][1]->remoteId);
    }

    public function test_html_body_is_rendered_sanitised_in_a_sandboxed_iframe(): void
    {
        $message = $this->message(['html_body' => '<p>Hello</p><script>alert("xss")</script><img src=x onerror=alert(1)>']);

        $html = Livewire::test(ViewMessage::class, ['record' => $this->mailbox->getRouteKey(), 'message' => $message->id])
            ->assertOk()
            ->html();

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('sandbox="allow-popups allow-popups-to-escape-sandbox"', $html);
        $this->assertStringContainsString('&lt;p&gt;Hello&lt;/p&gt;', $html);
        $this->assertStringNotContainsString('alert', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function test_already_read_messages_are_not_marked_again(): void
    {
        $message = $this->message(['is_read' => true]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->getRouteKey(), 'message' => $message->id])
            ->assertOk();

        $this->assertSame([], $this->provider->calls);
    }

    public function test_remote_failure_does_not_prevent_reading(): void
    {
        $failing = new class extends FakeMailboxProvider
        {
            public function markRead(MessageIdentifier $message): void
            {
                throw new RuntimeException('server gone');
            }
        };
        $this->app->instance(MailboxProviderFactory::class, new FakeMailboxProviderFactory($failing));

        $message = $this->message(['subject' => 'Still readable']);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->getRouteKey(), 'message' => $message->id])
            ->assertOk()
            ->assertSee('Still readable');

        $this->assertFalse($message->refresh()->is_read);
    }

    public function test_outdated_uid_validity_only_updates_locally(): void
    {
        $message = $this->message(['remote_id' => '6:1']);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->getRouteKey(), 'message' => $message->id])
            ->assertOk();

        $this->assertSame([], $this->provider->calls);
        $this->assertTrue($message->refresh()->is_read);
    }

    public function test_message_of_another_mailbox_is_not_found(): void
    {
        $foreign = MailboxMessage::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->getRouteKey(), 'message' => $foreign->id]);
    }

    public function test_message_url_is_generated(): void
    {
        $message = $this->message();

        $this->assertStringEndsWith(
            "/admin/mailboxes/{$this->mailbox->id}/messages/{$message->id}",
            MailboxResource::getUrl('message', ['record' => $this->mailbox, 'message' => $message]),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function message(array $attributes = []): MailboxMessage
    {
        return MailboxMessage::factory()->for($this->inbox, 'folder')->create(['remote_id' => '7:'.fake()->unique()->numberBetween(1, 100), ...$attributes]);
    }
}
