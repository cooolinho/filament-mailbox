<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\MessageForwarded;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\ForwardBuilder;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class ForwardTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxMessage $message;

    protected MailboxAttachment $invoice;

    protected MailboxAttachment $logo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->provider = $this->fakeProvider();
        $this->provider->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox));

        $this->mailbox = Mailbox::factory()->create(['email' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);

        $this->message = MailboxMessage::factory()
            ->for(MailboxFolder::factory()->for($this->mailbox)->inbox(), 'folder')
            ->create([
                'remote_id' => '1:5',
                'is_read' => true,
                'message_id' => '<original@example.com>',
                'references' => 'root@example.com',
                'from_name' => 'John Doe',
                'from_address' => 'john@example.com',
                'to' => [['address' => 'support@example.com', 'name' => 'Support']],
                'cc' => [['address' => 'team@example.com', 'name' => null]],
                'subject' => 'Rechnung 4711',
                'text_body' => "Hallo,\nanbei die Rechnung.",
                'has_attachments' => true,
                'sent_at' => Carbon::parse('2026-09-17 08:42:00'),
            ]);

        $this->provider->addMessage('INBOX', new MessageData('5', subject: 'Rechnung 4711', textBody: "Subject: Rechnung 4711\r\n\r\nraw source"));

        $this->invoice = $this->attachment('rechnung.pdf', 'PDF-CONTENT');
        $this->logo = $this->attachment('logo.png', 'PNG-CONTENT');
    }

    public function test_subject_prefix_is_not_stacked(): void
    {
        $builder = app(ForwardBuilder::class);

        $this->assertSame('Fwd: Rechnung', $builder->subject('Rechnung'));
        $this->assertSame('Fwd: Rechnung', $builder->subject('Fwd: Rechnung'));
        $this->assertSame('FW: Rechnung', $builder->subject('FW: Rechnung'));
        $this->assertSame('WG: Rechnung', $builder->subject('WG: Rechnung'));
        $this->assertSame('Fwd: Re: Rechnung', $builder->subject('Re: Rechnung'));
        $this->assertSame('Fwd:', $builder->subject(null));
    }

    public function test_inline_body_contains_the_original_headers_and_text(): void
    {
        $body = app(ForwardBuilder::class)->inlineBody($this->message);

        $this->assertStringContainsString('---------- Forwarded message ----------', $body);
        $this->assertStringContainsString('From: John Doe <john@example.com>', $body);
        $this->assertStringContainsString('Subject: Rechnung 4711', $body);
        $this->assertStringContainsString('To: Support <support@example.com>', $body);
        $this->assertStringContainsString('CC: team@example.com', $body);
        $this->assertStringContainsString("Hallo,\nanbei die Rechnung.", $body);
        $this->assertStringNotContainsString('> Hallo', $body);
    }

    public function test_html_only_messages_are_forwarded_as_text(): void
    {
        $this->message->text_body = null;
        $this->message->html_body = '<p>Erste Zeile</p><p>Zweite &amp; letzte</p><script>x()</script>';

        $body = app(ForwardBuilder::class)->inlineBody($this->message);

        $this->assertStringContainsString("Erste Zeile\nZweite & letzte", $body);
        $this->assertStringNotContainsString('<p>', $body);
        $this->assertStringNotContainsString('x()', $body);
    }

    public function test_forward_action_prefills_the_form(): void
    {
        $this->viewMessage()
            ->mountAction('forward')
            ->assertSchemaStateSet([
                'as_attachment' => false,
                'to' => [],
                'subject' => 'Fwd: Rechnung 4711',
                'original_attachments' => [$this->invoice->id, $this->logo->id],
            ]);
    }

    public function test_forward_inline_with_selected_original_attachments(): void
    {
        Mail::fake();
        Event::fake([MessageForwarded::class]);

        $this->viewMessage()
            ->mountAction('forward')
            ->fillForm([
                'to' => ['accounting@example.com'],
                'original_attachments' => [$this->invoice->id],
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.actions.compose.success'));

        Mail::assertSent(OutgoingMessage::class, function (OutgoingMessage $mail): bool {
            $headers = $mail->headers();

            return $mail->hasTo('accounting@example.com')
                && $mail->hasSubject('Fwd: Rechnung 4711')
                && str_contains($mail->data->body, 'anbei die Rechnung.')
                && array_map(fn ($attachment) => $attachment->filename, $mail->data->attachments) === ['rechnung.pdf']
                && $mail->data->attachments[0]->contents === 'PDF-CONTENT'
                && $mail->data->inReplyTo === null
                && $headers->references === ['original@example.com']
                && ! isset($headers->text['In-Reply-To']);
        });

        $this->assertNotNull($this->message->refresh()->forwarded_at);
        $this->assertSame(['$Forwarded'], $this->message->keywords);
        $this->assertSame('setFlags', $this->provider->calls[0][0]);

        Event::assertDispatched(MessageForwarded::class, fn (MessageForwarded $event): bool => $event->recipients === ['accounting@example.com'] && ! $event->asAttachment);
    }

    public function test_foreign_attachment_ids_are_ignored(): void
    {
        Mail::fake();

        $foreign = MailboxAttachment::factory()->create(['filename' => 'foreign.pdf']);

        $this->viewMessage()
            ->mountAction('forward')
            ->fillForm(['to' => ['accounting@example.com'], 'original_attachments' => [$foreign->id]])
            ->callMountedAction()
            ->assertHasFormErrors(['original_attachments.0']);

        Mail::assertNothingSent();

        $attachments = app(ForwardBuilder::class)->originalAttachments($this->message, [$this->logo->id, $foreign->id]);

        $this->assertSame(['logo.png'], array_map(fn ($attachment) => $attachment->filename, $attachments));
    }

    public function test_deselected_attachments_are_not_forwarded(): void
    {
        Mail::fake();

        $this->viewMessage()
            ->mountAction('forward')
            ->fillForm(['to' => ['accounting@example.com'], 'original_attachments' => []])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->data->attachments === []);
    }

    public function test_forward_as_attachment_uses_the_original_source(): void
    {
        Mail::fake();

        $this->viewMessage()
            ->mountAction('forward')
            ->fillForm(['as_attachment' => true])
            ->assertSchemaStateSet(['quoted_html' => '<p></p>'])
            ->fillForm(['to' => ['accounting@example.com']])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, function (OutgoingMessage $mail): bool {
            $attachment = $mail->data->attachments[0] ?? null;

            return count($mail->data->attachments) === 1
                && $attachment->filename === 'rechnung-4711.eml'
                && $attachment->mimeType === 'message/rfc822'
                && str_contains($attachment->contents, 'raw source');
        });
    }

    public function test_forward_as_attachment_rebuilds_the_message_without_source(): void
    {
        unset($this->provider->messages['INBOX'][5]);

        $attachment = app(ForwardBuilder::class)->messageAttachment($this->message);

        $this->assertSame('message/rfc822', $attachment->mimeType);
        $this->assertStringContainsString('Subject: Rechnung 4711', $attachment->contents);
        $this->assertStringContainsString('Message-ID: <original@example.com>', $attachment->contents);
        $this->assertStringContainsString('anbei die Rechnung.', $attachment->contents);
        $this->assertStringContainsString('filename=rechnung.pdf', $attachment->contents);
    }

    public function test_attachments_over_the_size_limit_are_rejected(): void
    {
        Mail::fake();
        config(['filament-mailbox.mail.max_attachment_size' => 0]);

        $this->viewMessage()
            ->mountAction('forward')
            ->fillForm(['to' => ['accounting@example.com']])
            ->callMountedAction()
            ->assertNotified(__('filament-mailbox::mailbox.forward.too_large', ['size' => '0 B']));

        Mail::assertNothingSent();
        $this->assertNull($this->message->refresh()->forwarded_at);
    }

    public function test_marking_as_forwarded_without_keywords_only_changes_the_local_copy(): void
    {
        $this->provider->capabilities = [];

        app(MessageService::class)->markForwarded($this->message);

        $this->assertNotNull($this->message->refresh()->forwarded_at);
        $this->assertNull($this->message->keywords);
        $this->assertSame([], $this->provider->calls);
    }

    protected function viewMessage(): Testable
    {
        return Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $this->message->id]);
    }

    protected function attachment(string $filename, string $contents): MailboxAttachment
    {
        $path = 'mailbox/'.fake()->uuid();
        Storage::disk('local')->put($path, $contents);

        return MailboxAttachment::factory()->for($this->message, 'message')->create([
            'filename' => $filename,
            'size' => strlen($contents),
            'disk' => 'local',
            'storage_path' => $path,
        ]);
    }
}
