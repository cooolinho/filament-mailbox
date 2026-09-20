<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\ReadReceiptReceived;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Mail\MimeMessageBuilder;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Models\MailboxReceipt;
use Cooolinho\FilamentMailbox\Models\MailboxReceiptRequest;
use Cooolinho\FilamentMailbox\Providers\MimeMessageMapper;
use Cooolinho\FilamentMailbox\Services\OutboxService;
use Cooolinho\FilamentMailbox\Services\Receipts\ReadReceiptService;
use Cooolinho\FilamentMailbox\Services\Receipts\ReceiptParser;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

class ReadReceiptsTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox));

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support', 'email' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
    }

    public function test_compose_requests_a_read_receipt_and_records_the_request(): void
    {
        Mail::fake();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: ['to' => ['jane@example.com'], 'subject' => 'Offer', 'format' => 'text', 'body' => 'Body', 'request_read_receipt' => true])
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->data->headers === ['Disposition-Notification-To' => '<support@example.com>']);

        $request = MailboxReceiptRequest::query()->where('type', MailboxReceiptRequest::TYPE_READ)->sole();
        $this->assertSame(MailboxReceiptRequest::TYPE_READ, $request->type);
        $this->assertSame(MailboxOutgoingMessage::query()->sole()->message_id, $request->message_id);
        $this->assertSame(['jane@example.com'], $request->recipients);
    }

    public function test_no_request_without_the_checkbox_and_mailbox_default(): void
    {
        Mail::fake();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: ['to' => ['jane@example.com'], 'subject' => 'Offer', 'format' => 'text', 'body' => 'Body']);

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->data->headers === []);
        $this->assertSame(0, MailboxReceiptRequest::query()->where('type', MailboxReceiptRequest::TYPE_READ)->count());

        $this->mailbox->update(['request_read_receipts' => true]);
        $this->assertTrue(ReadReceiptService::requestByDefault($this->mailbox));
    }

    public function test_requests_are_evaluated_on_import(): void
    {
        $pending = $this->import(1, $this->requestMail('<jane@example.com>', 'jane@example.com'));
        $unsafe = $this->import(2, $this->requestMail('<collector@tracker.example>', 'jane@example.com'));
        $several = $this->import(3, $this->requestMail('a@example.com, b@example.com', 'a@example.com'));
        $answered = $this->import(4, $this->requestMail('<jane@example.com>', 'jane@example.com'), ['$MDNSent']);
        $plain = $this->import(5, "From: jane@example.com\r\nSubject: No request\r\n\r\nBody");

        $this->assertSame('pending', $pending->mdn_status);
        $this->assertSame('<jane@example.com>', $pending->mdn_requested_to);
        $this->assertSame('unsafe', $unsafe->mdn_status);
        $this->assertSame('not_applicable', $several->mdn_status);
        $this->assertSame('sent', $answered->mdn_status);
        $this->assertNull($plain->mdn_status);
    }

    public function test_keyword_set_by_another_client_ends_the_request(): void
    {
        $message = $this->import(1, $this->requestMail('<jane@example.com>', 'jane@example.com'));
        $this->assertSame('pending', $message->mdn_status);

        $this->provider->messages['INBOX'][1] = $this->provider->messages['INBOX'][1]->with(['flags' => new MessageFlags(keywords: ['$MDNSent'])]);
        app(SyncService::class)->syncFolder($this->inbox->refresh(), $this->provider);

        $this->assertSame('sent', $message->refresh()->mdn_status);
    }

    public function test_message_view_asks_and_sends_a_receipt_once(): void
    {
        config(['mail.default' => 'array']);
        $message = $this->import(1, $this->requestMail('<jane@example.com>', 'jane@example.com'));

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertSee(__('filament-mailbox::mailbox.read_receipts.callout.heading'))
            ->callAction(TestAction::make('sendReadReceipt')->schemaComponent('readReceiptRequest', 'infolist'))
            ->assertNotified(__('filament-mailbox::mailbox.read_receipts.sent'));

        $message->refresh();
        $this->assertSame('sent', $message->mdn_status);
        $this->assertContains('$MDNSent', $message->keywords);
        $this->assertContains('$MDNSent', $this->provider->messages['INBOX'][1]->flags->keywords);

        $sent = app('mailer')->getSymfonyTransport()->messages()->sole();
        $raw = $sent->toString();
        $this->assertStringContainsString('report-type=disposition-notification', $raw);
        $this->assertStringContainsString('Content-Type: message/disposition-notification', $raw);
        $this->assertStringContainsString('Original-Message-ID: <'.$message->message_id.'>', $raw);
        $this->assertStringContainsString('Disposition: manual-action/MDN-sent-manually; displayed', $raw);
        $this->assertStringContainsString('Auto-Submitted: auto-replied', $raw);
        $this->assertSame('jane@example.com', $sent->getEnvelope()->getRecipients()[0]->getAddress());

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertDontSee(__('filament-mailbox::mailbox.read_receipts.callout.heading'));
    }

    public function test_unsafe_requests_can_only_be_ignored(): void
    {
        Mail::fake();
        $message = $this->import(1, $this->requestMail('<collector@tracker.example>', 'jane@example.com'));

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertSee(__('filament-mailbox::mailbox.read_receipts.callout.unsafe', ['address' => '<collector@tracker.example>']))
            ->assertActionHidden(TestAction::make('sendReadReceipt')->schemaComponent('readReceiptRequest', 'infolist'))
            ->callAction(TestAction::make('ignoreReadReceipt')->schemaComponent('readReceiptRequest', 'infolist'));

        $this->assertSame('ignored', $message->refresh()->mdn_status);
        $this->assertContains('$MDNSent', $message->keywords);
        Mail::assertNothingSent();
    }

    public function test_no_receipt_from_spam_or_when_sending_is_not_allowed(): void
    {
        $message = $this->import(1, $this->requestMail('<jane@example.com>', 'jane@example.com'));
        $receipts = app(ReadReceiptService::class);

        config(['filament-mailbox.read_receipts.allow_sending' => false]);
        $this->assertFalse($receipts->canRespond($message));
        $this->assertTrue($receipts->isOpenRequest($message));

        config(['filament-mailbox.read_receipts.allow_sending' => true]);
        $this->mailbox->update(['spam_folder_id' => $this->inbox->id]);
        $message = $message->fresh();
        $this->assertFalse($receipts->canRespond($message));
        $this->assertFalse($receipts->isOpenRequest($message));
    }

    public function test_incoming_receipts_are_assigned_to_the_sent_message(): void
    {
        Mail::fake();
        Event::fake([ReadReceiptReceived::class]);

        $outgoing = app(OutboxService::class)->send($this->mailbox, new OutgoingMessageData(['jane@example.com'], 'Offer', 'Body', headers: ReadReceiptService::requestHeaders($this->mailbox)), $this->user);

        $receipt = $this->import(1, $this->mdnMail($outgoing->message_id));
        $this->import(2, $this->mdnMail('unknown@example.com'));

        $this->assertTrue($receipt->is_receipt);
        $stored = MailboxReceipt::query()->sole();
        $this->assertSame('jane@example.com', $stored->recipient);
        $this->assertSame('displayed', $stored->disposition);
        $this->assertSame($receipt->id, $stored->receipt_message_id);
        Event::assertDispatched(ReadReceiptReceived::class);
        // Besides the new mail notification of the import.
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->user->id)->where('data', 'like', '%opened your message%')->count());

        // Imported again (e.g. after a folder reset): not duplicated.
        app(ReadReceiptService::class)->processReport($receipt, MimeMessageMapper::fromRaw($this->mdnMail($outgoing->message_id), '1'));
        $this->assertSame(1, MailboxReceipt::query()->count());

        $this->assertSame(['jane@example.com – opened on '.$stored->reported_at->translatedFormat('D, j. M Y, H:i')], \Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\MessageInfolist::receiptLines(app(ReadReceiptService::class)->receiptsFor($outgoing)));
    }

    public function test_parser_is_robust_against_malformed_reports(): void
    {
        $parser = new ReceiptParser;

        $this->assertNull($parser->dispositionNotification("Disposition: automatic-action/MDN-sent-automatically; displayed\r\n"));
        $this->assertNull($parser->dispositionNotification(str_repeat('x', 10000)));

        $report = $parser->dispositionNotification("Reporting-UA: Mozilla\r\nFinal-Recipient: rfc822;Jane@Example.com\r\nOriginal-Message-ID: <abc@example.com>\r\nDisposition: manual-action/MDN-sent-manually;\r\n displayed\r\n");
        $this->assertSame(['original_message_id' => 'abc@example.com', 'recipient' => 'jane@example.com', 'disposition' => 'displayed', 'mode' => 'manual-action/mdn-sent-manually'], $report);
    }

    public function test_mime_builder_renders_a_report_for_api_providers(): void
    {
        $raw = app(MimeMessageBuilder::class)->build($this->mailbox, new OutgoingMessageData(
            ['jane@example.com'],
            'Read: Offer',
            'Displayed',
            report: new \Cooolinho\FilamentMailbox\Data\ReportData('disposition-notification', "Disposition: manual-action/MDN-sent-manually; displayed\r\n"),
        ));

        $data = MimeMessageMapper::fromRaw($raw, '1');
        $this->assertStringStartsWith('multipart/report', $data->header('Content-Type'));
        $this->assertStringContainsString('displayed', $data->reportParts['message/disposition-notification']);
        $this->assertSame('Displayed', trim((string) $data->textBody));
    }

    /**
     * @param  array<int, string>  $keywords
     */
    protected function import(int $uid, string $raw, array $keywords = []): MailboxMessage
    {
        $this->provider->addMessage('INBOX', MimeMessageMapper::fromRaw($raw, (string) $uid, new MessageFlags(keywords: $keywords)));
        app(SyncService::class)->syncFolder($this->inbox->refresh(), $this->provider);

        return MailboxMessage::query()->where('remote_id', '1:'.$uid)->sole();
    }

    protected function requestMail(string $notificationTo, string $returnPath): string
    {
        static $count = 0;
        $count++;

        return "Return-Path: <{$returnPath}>\r\nFrom: Jane <jane@example.com>\r\nTo: support@example.com\r\nSubject: Question\r\nMessage-ID: <request-{$count}@example.com>\r\nDate: Tue, 22 Sep 2026 10:00:00 +0000\r\nDisposition-Notification-To: {$notificationTo}\r\n\r\nPlease confirm.";
    }

    protected function mdnMail(string $originalMessageId): string
    {
        return implode("\r\n", [
            'From: Jane <jane@example.com>',
            'To: support@example.com',
            'Subject: Read: Offer',
            'Message-ID: <mdn-'.md5($originalMessageId).'@example.com>',
            'Date: Tue, 22 Sep 2026 11:00:00 +0000',
            'MIME-Version: 1.0',
            'Content-Type: multipart/report; report-type=disposition-notification; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'The message was displayed.',
            '--b1',
            'Content-Type: message/disposition-notification',
            '',
            'Reporting-UA: Thunderbird',
            'Final-Recipient: rfc822;jane@example.com',
            'Original-Message-ID: <'.$originalMessageId.'>',
            'Disposition: manual-action/MDN-sent-manually; displayed',
            '',
            '--b1--',
            '',
        ]);
    }
}
