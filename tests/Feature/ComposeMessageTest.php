<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Services\MailSender;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;

class ComposeMessageTest extends TestCase
{
    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support', 'email' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);
    }

    public function test_new_email_is_sent_via_laravel_mail(): void
    {
        Mail::fake();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: [
                'to' => ['jane@example.com'],
                'cc' => ['team@example.com'],
                'bcc' => ['audit@example.com'],
                'subject' => 'Hello',
                'format' => 'text',
                'body' => "Hi Jane,\n<b>not bold</b>",
                'attachments' => [UploadedFile::fake()->createWithContent('notes.txt', 'some notes')],
            ])
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.actions.compose.success'));

        Mail::assertSent(OutgoingMessage::class, function (OutgoingMessage $mail): bool {
            $this->assertTrue($mail->hasFrom('support@example.com', 'Support'));
            $this->assertTrue($mail->hasTo('jane@example.com'));
            $this->assertTrue($mail->hasCc('team@example.com'));
            $this->assertTrue($mail->hasBcc('audit@example.com'));
            $this->assertTrue($mail->hasSubject('Hello'));
            $this->assertCount(1, $mail->data->attachments);
            $this->assertSame('notes.txt', $mail->data->attachments[0]->filename);
            $this->assertSame('some notes', $mail->data->attachments[0]->contents);
            $this->assertCount(1, $mail->attachments());

            $mail->assertSeeInHtml('Hi Jane,<br />', false);
            $mail->assertSeeInHtml('&lt;b&gt;not bold&lt;/b&gt;', false);

            return true;
        });
    }

    public function test_recipients_are_validated(): void
    {
        Mail::fake();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: [
                'to' => ['not-an-address'],
                'subject' => '',
                'body' => '',
            ])
            ->assertHasFormErrors(['to.0', 'subject' => 'required', 'body_html']);

        Mail::assertNothingSent();
    }

    public function test_at_least_one_recipient_is_required(): void
    {
        Mail::fake();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: ['to' => [], 'subject' => 'Hi', 'body' => 'Body'])
            ->assertHasFormErrors(['to' => 'required']);

        Mail::assertNothingSent();
    }

    public function test_transport_failure_notifies_user(): void
    {
        $this->app->instance(MailSender::class, new class($this->app) extends MailSender
        {
            public function send(Mailbox $mailbox, OutgoingMessageData $data): void
            {
                throw new RuntimeException('SMTP down');
            }
        });

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: ['to' => ['jane@example.com'], 'subject' => 'Hi', 'format' => 'text', 'body' => 'Body'])
            ->assertNotified(__('filament-mailbox::mailbox.actions.compose.failure'));
    }

    public function test_rendered_message_contains_threading_headers(): void
    {
        config(['mail.default' => 'array']);

        $sent = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent = $event->message;
        });

        app(MailSender::class)->send($this->mailbox, new OutgoingMessageData(
            to: ['jane@example.com'],
            subject: 'Re: Hello',
            body: 'Thanks',
            attachments: [new AttachmentData('../report.pdf', 'application/pdf', '%PDF')],
            inReplyTo: 'parent@example.com',
            references: ['root@example.com', 'parent@example.com'],
        ));

        $this->assertNotNull($sent);
        $headers = $sent->getHeaders();
        $this->assertSame('<parent@example.com>', $headers->get('In-Reply-To')->getBodyAsString());
        $this->assertSame('<root@example.com> <parent@example.com>', $headers->get('References')->getBodyAsString());
        $this->assertSame('support@example.com', $sent->getFrom()[0]->getAddress());
        $this->assertSame('report.pdf', $sent->getAttachments()[0]->getFilename());
    }
}
