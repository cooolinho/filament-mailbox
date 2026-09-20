<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\ReplyBuilder;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

class ReplyTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider();

        $this->mailbox = Mailbox::factory()->create(['email' => 'support@example.com', 'username' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);

        $this->message = MailboxMessage::factory()
            ->for(MailboxFolder::factory()->for($this->mailbox)->inbox(), 'folder')
            ->create([
                'is_read' => true,
                'message_id' => 'original@example.com',
                'references' => 'root@example.com',
                'from_name' => 'John Doe',
                'from_address' => 'john@example.com',
                'to' => [
                    ['address' => 'Support@Example.com', 'name' => 'Support'],
                    ['address' => 'jane@example.com', 'name' => 'Jane'],
                ],
                'cc' => [
                    ['address' => 'team@example.com', 'name' => null],
                    ['address' => 'JOHN@example.com', 'name' => null],
                ],
                'subject' => 'Projekt Update',
                'text_body' => "Hallo,\nanbei das Update.",
                'sent_at' => Carbon::parse('2026-09-17 08:42:00'),
            ]);
    }

    public function test_reply_recipients_prefer_reply_to(): void
    {
        $builder = new ReplyBuilder;

        $this->assertSame(['john@example.com'], $builder->replyRecipients($this->message));

        $this->message->reply_to = [['address' => 'noreply-replies@example.com', 'name' => null]];

        $this->assertSame(['noreply-replies@example.com'], $builder->replyRecipients($this->message));
    }

    public function test_reply_all_excludes_own_address_and_duplicates(): void
    {
        $data = (new ReplyBuilder)->formData($this->message, all: true);

        $this->assertSame(['john@example.com'], $data['to']);
        $this->assertSame(['jane@example.com', 'team@example.com'], $data['cc']);
    }

    public function test_subject_prefix_is_not_stacked(): void
    {
        $builder = new ReplyBuilder;

        $this->assertSame('Re: Projekt Update', $builder->subject('Projekt Update'));
        $this->assertSame('Re: Projekt Update', $builder->subject('Re: Projekt Update'));
        $this->assertSame('AW: Projekt Update', $builder->subject('AW: Projekt Update'));
        $this->assertSame('Re:', $builder->subject(null));
    }

    public function test_original_message_is_quoted(): void
    {
        $body = (new ReplyBuilder)->quote($this->message);

        $this->assertStringContainsString('John Doe wrote:', $body);
        $this->assertStringContainsString("> Hallo,\n> anbei das Update.", $body);
    }

    public function test_html_only_messages_are_quoted_as_text(): void
    {
        $this->message->text_body = null;
        $this->message->html_body = '<p>Erste Zeile</p><p>Zweite &amp; letzte</p><script>x()</script>';

        $body = (new ReplyBuilder)->quote($this->message);

        $this->assertStringContainsString('> Erste Zeile', $body);
        $this->assertStringContainsString('> Zweite & letzte', $body);
        $this->assertStringNotContainsString('<p>', $body);
    }

    public function test_references_chain_is_extended(): void
    {
        $this->assertSame(['root@example.com', 'original@example.com'], (new ReplyBuilder)->references($this->message));
    }

    public function test_reply_action_prefills_and_sends_threaded_mail(): void
    {
        Mail::fake();

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $this->message->id])
            ->mountAction('reply')
            ->assertSchemaStateSet([
                'to' => ['john@example.com'],
                'cc' => [],
                'subject' => 'Re: Projekt Update',
                'format' => 'html',
            ])
            ->fillForm(['body_html' => '<p>Danke!</p>'])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.actions.compose.success'));

        Mail::assertSent(OutgoingMessage::class, function (OutgoingMessage $mail): bool {
            return $mail->hasTo('john@example.com')
                && $mail->hasFrom('support@example.com')
                && $mail->hasSubject('Re: Projekt Update')
                && $mail->data->inReplyTo === 'original@example.com'
                && $mail->data->references === ['root@example.com', 'original@example.com']
                && str_starts_with($mail->data->body, 'Danke!')
                && str_contains($mail->data->body, '> anbei das Update.')
                && str_contains((string) $mail->data->bodyHtml, '<blockquote>');
        });
    }

    public function test_reply_all_action_prefills_cc(): void
    {
        Mail::fake();

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $this->message->id])
            ->mountAction('replyAll')
            ->assertSchemaStateSet([
                'to' => ['john@example.com'],
                'cc' => ['jane@example.com', 'team@example.com'],
            ])
            ->fillForm(['format' => 'text', 'body' => 'Danke!'])
            ->callMountedAction();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->hasCc('jane@example.com') && $mail->hasCc('team@example.com'));
    }
}
