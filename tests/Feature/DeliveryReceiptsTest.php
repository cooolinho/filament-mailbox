<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\DsnOptions;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\DeliveryFailed;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ListOutgoingMessages;
use Cooolinho\FilamentMailbox\Mail\Transports\DsnEsmtpTransport;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Models\MailboxReceipt;
use Cooolinho\FilamentMailbox\Models\MailboxReceiptRequest;
use Cooolinho\FilamentMailbox\Providers\MimeMessageMapper;
use Cooolinho\FilamentMailbox\Services\MailSender;
use Cooolinho\FilamentMailbox\Services\OutboxService;
use Cooolinho\FilamentMailbox\Services\Receipts\DeliveryReportService;
use Cooolinho\FilamentMailbox\Services\Receipts\ReceiptParser;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Support\Xtext;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeSmtpStream;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;

class DeliveryReceiptsTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    /** @var array<int, OutgoingMessageData> */
    protected array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox));

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support', 'email' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
    }

    public function test_xtext_encodes_everything_that_could_inject_commands(): void
    {
        $this->assertSame('a+2Bb+3Dc+20d+0D+0ARCPT', Xtext::encode("a+b=c d\r\nRCPT"));
        $this->assertSame("a+b=c d\r\nRCPT", ReceiptParser::decodeXtext(Xtext::encode("a+b=c d\r\nRCPT")));
    }

    public function test_transport_adds_dsn_parameters_when_the_server_supports_them(): void
    {
        $stream = new FakeSmtpStream(['DSN', 'SIZE 1000']);
        $transport = new DsnEsmtpTransport(stream: $stream);
        $transport->setDsn(new DsnOptions("uuid-1\r\nQUIT", DsnOptions::NOTIFY_ALL));

        $transport->send($this->email());

        $this->assertContains('MAIL FROM:<support@example.com> RET=HDRS ENVID=uuid-1+0D+0AQUIT', $stream->commands);
        $this->assertContains('RCPT TO:<jane@example.com> NOTIFY=SUCCESS,FAILURE,DELAY ORCPT=rfc822;jane@example.com', $stream->commands);
        $this->assertTrue($transport->dsnSupported());
    }

    public function test_transport_sends_without_dsn_parameters_otherwise(): void
    {
        $stream = new FakeSmtpStream(['SIZE 1000']);
        $transport = new DsnEsmtpTransport(stream: $stream);
        $transport->setDsn(new DsnOptions('uuid-1'));

        $transport->send($this->email());

        $this->assertContains('MAIL FROM:<support@example.com>', $stream->commands);
        $this->assertContains('RCPT TO:<jane@example.com>', $stream->commands);
        $this->assertFalse($transport->dsnSupported());

        $stream = new FakeSmtpStream(['DSN']);
        $transport = new DsnEsmtpTransport(stream: $stream);
        $transport->send($this->email());

        $this->assertContains('RCPT TO:<jane@example.com>', $stream->commands);
        $this->assertNull($transport->dsnSupported());
    }

    public function test_laravel_mailers_can_use_the_dsn_transport(): void
    {
        config(['mail.mailers.dsn' => ['transport' => 'mailbox-dsn', 'host' => 'smtp.example.com', 'port' => 587, 'username' => 'u', 'password' => 'p']]);

        $this->assertInstanceOf(DsnEsmtpTransport::class, Mail::mailer('dsn')->getSymfonyTransport());
    }

    public function test_outbox_requests_failure_reports_always_and_success_on_request(): void
    {
        $this->recordingSender(supported: true);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: ['to' => ['jane@example.com'], 'subject' => 'A', 'format' => 'text', 'body' => 'Body'])
            ->callAction('compose', data: ['to' => ['jane@example.com'], 'subject' => 'B', 'format' => 'text', 'body' => 'Body', 'request_delivery_receipt' => true]);

        [$first, $second] = MailboxOutgoingMessage::query()->orderBy('id')->get()->all();

        $this->assertSame('FAILURE,DELAY', $first->dsn_notify);
        $this->assertSame('SUCCESS,FAILURE,DELAY', $second->dsn_notify);
        $this->assertTrue($first->dsn_supported);
        $this->assertSame($first->uuid, $this->sent[0]->dsn->envelopeId);
        $this->assertSame('SUCCESS,FAILURE,DELAY', $this->sent[1]->dsn->notify);
        $this->assertSame(2, MailboxReceiptRequest::query()->where('type', 'delivery')->count());
    }

    public function test_delivery_status_notifications_are_assigned_per_recipient(): void
    {
        Event::fake([DeliveryFailed::class]);
        $outgoing = $this->outgoing(['jane@example.com', 'max@example.com']);

        $report = $this->import(1, $this->dsnMail($outgoing->uuid, [
            ['jane@example.com', 'failed', '5.1.1', 'smtp; 550 5.1.1 <jane@example.com>: Recipient address rejected'],
            ['max@example.com', 'delayed', '4.4.1', 'smtp; connection timed out'],
            ['intruder@example.com', 'failed', '5.1.1', null],
        ]));

        $this->assertTrue($report->is_receipt);
        $receipts = MailboxReceipt::query()->orderBy('recipient')->get();
        $this->assertSame(['jane@example.com', 'max@example.com'], $receipts->pluck('recipient')->all());
        $this->assertSame(['failed', 'delayed'], $receipts->pluck('disposition')->all());
        $this->assertSame('5.1.1', $receipts[0]->status_code);
        $this->assertSame('dsn', $receipts[0]->source);
        $this->assertSame('mail.example.com', $receipts[0]->reporting_mta);
        Event::assertDispatchedTimes(DeliveryFailed::class, 1);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->user->id)->where('data', 'like', '%could not be delivered%')->count());
        $this->assertSame('failed', app(DeliveryReportService::class)->statusOf($outgoing));

        // Imported again: not duplicated.
        $this->import(2, $this->dsnMail($outgoing->uuid, [['jane@example.com', 'failed', '5.1.1', null]]));
        $this->assertSame(2, MailboxReceipt::query()->count());
    }

    public function test_success_reports_mark_the_message_as_delivered(): void
    {
        $outgoing = $this->outgoing(['jane@example.com']);

        $this->import(1, $this->dsnMail($outgoing->uuid, [['jane@example.com', 'delayed', '4.4.1', null]]));
        $this->assertSame('delayed', app(DeliveryReportService::class)->statusOf($outgoing));

        $this->travel(1)->hours();
        $this->import(2, $this->dsnMail($outgoing->uuid, [['jane@example.com', 'delivered', '2.0.0', null]], date: 'Tue, 22 Sep 2026 12:00:00 +0000'));
        $this->assertSame('delivered', app(DeliveryReportService::class)->statusOf($outgoing));
    }

    public function test_reports_without_envelope_id_are_assigned_by_the_returned_headers(): void
    {
        $outgoing = $this->outgoing(['jane@example.com']);

        $this->import(1, $this->dsnMail(null, [['jane@example.com', 'failed', '5.2.2', 'mailbox full']], returnedMessageId: $outgoing->message_id));

        $this->assertSame('failed', MailboxReceipt::query()->sole()->disposition);
    }

    public function test_bounces_without_report_are_recognised_heuristically(): void
    {
        $outgoing = $this->outgoing(['jane@example.com']);

        $this->import(1, implode("\r\n", [
            'From: Mail Delivery System <MAILER-DAEMON@mail.example.com>',
            'To: support@example.com',
            'Subject: Undelivered Mail Returned to Sender',
            'Date: Tue, 22 Sep 2026 11:00:00 +0000',
            '',
            'I\'m sorry to have to inform you that your message could not be delivered to jane@example.com.',
            '',
            '--- Original message ---',
            'Message-ID: <'.$outgoing->message_id.'>',
            'Subject: Offer',
        ]));

        $receipt = MailboxReceipt::query()->sole();
        $this->assertSame('failed', $receipt->disposition);
        $this->assertSame('bounce_heuristic', $receipt->source);
        $this->assertTrue(MailboxMessage::query()->where('remote_id', '1:1')->sole()->is_receipt);
    }

    public function test_reports_for_unknown_messages_are_ignored(): void
    {
        $this->outgoing(['jane@example.com']);

        $this->import(1, $this->dsnMail('someone-else', [['jane@example.com', 'failed', '5.1.1', null]]));

        $this->assertSame(0, MailboxReceipt::query()->count());
    }

    public function test_outbox_shows_the_delivery_status_and_filters_failures(): void
    {
        $failed = $this->outgoing(['jane@example.com']);
        $unknown = $this->outgoing(['max@example.com']);
        $this->import(1, $this->dsnMail($failed->uuid, [['jane@example.com', 'failed', '5.1.1', null]]));

        Livewire::test(ListOutgoingMessages::class, ['record' => $this->mailbox->id])
            ->assertTableColumnStateSet('delivery', 'failed', $failed)
            ->assertTableColumnStateSet('delivery', 'unknown', $unknown)
            ->filterTable('delivery_failed')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$unknown]);

        $this->assertStringContainsString('jane@example.com – Failed', ListOutgoingMessages::deliveryLines($failed)[0]);
    }

    /**
     * @param  array<int, string>  $to
     */
    protected function outgoing(array $to): MailboxOutgoingMessage
    {
        $this->recordingSender(supported: true);

        return app(OutboxService::class)->send($this->mailbox, new OutgoingMessageData($to, 'Offer', 'Body'), $this->user);
    }

    protected function recordingSender(?bool $supported): void
    {
        $test = $this;

        $this->app->instance(MailSender::class, new class($this->app, $test, $supported) extends MailSender
        {
            public function __construct($container, protected $test, protected ?bool $supported)
            {
                parent::__construct($container);
            }

            public function send(Mailbox $mailbox, OutgoingMessageData $data): void
            {
                $this->test->recordSent($data);
                $this->dsnSupported = $data->dsn ? $this->supported : null;
            }
        });
    }

    public function recordSent(OutgoingMessageData $data): void
    {
        $this->sent[] = $data;
    }

    protected function import(int $uid, string $raw): MailboxMessage
    {
        $this->provider->addMessage('INBOX', MimeMessageMapper::fromRaw($raw, (string) $uid, new MessageFlags));
        app(SyncService::class)->syncFolder($this->inbox->refresh(), $this->provider);

        return MailboxMessage::query()->where('remote_id', '1:'.$uid)->sole();
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: ?string, 3: ?string}>  $recipients
     */
    protected function dsnMail(?string $envelopeId, array $recipients, ?string $returnedMessageId = null, string $date = 'Tue, 22 Sep 2026 11:00:00 +0000'): string
    {
        $status = array_filter(['Reporting-MTA: dns; mail.example.com', $envelopeId ? 'Original-Envelope-Id: '.Xtext::encode($envelopeId) : null]);

        foreach ($recipients as [$recipient, $action, $code, $diagnostic]) {
            $status[] = '';
            $status[] = 'Final-Recipient: rfc822; '.$recipient;
            $status[] = 'Action: '.$action;
            $status[] = 'Status: '.$code;

            if ($diagnostic) {
                $status[] = 'Diagnostic-Code: '.$diagnostic;
            }
        }

        return implode("\r\n", [
            'From: MAILER-DAEMON@mail.example.com',
            'To: support@example.com',
            'Subject: Delivery Status Notification',
            'Message-ID: <dsn-'.md5(serialize($recipients).$envelopeId.$date).'@mail.example.com>',
            'Date: '.$date,
            'MIME-Version: 1.0',
            'Content-Type: multipart/report; report-type=delivery-status; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/plain',
            '',
            'Delivery report.',
            '--b1',
            'Content-Type: message/delivery-status',
            '',
            ...$status,
            '',
            '--b1',
            'Content-Type: text/rfc822-headers',
            '',
            'From: support@example.com',
            'Message-ID: <'.($returnedMessageId ?? 'unrelated@example.com').'>',
            'Subject: Offer',
            '',
            '--b1--',
            '',
        ]);
    }

    protected function email(): Email
    {
        return (new Email)->from('support@example.com')->to('jane@example.com')->subject('Hi')->text('Body');
    }
}
