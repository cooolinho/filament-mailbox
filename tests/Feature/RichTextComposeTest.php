<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Mail\MimeMessageBuilder;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\ForwardBuilder;
use Cooolinho\FilamentMailbox\Services\HtmlBodySanitizer;
use Cooolinho\FilamentMailbox\Services\InlineImageProcessor;
use Cooolinho\FilamentMailbox\Services\ReplyBuilder;
use Cooolinho\FilamentMailbox\Support\HtmlToText;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class RichTextComposeTest extends TestCase
{
    protected const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\xff\xff?\0\x05\xfe\x02\xfe\xa7\x35\x81\x84\0\0\0\0IEND\xaeB`\x82";

    protected Mailbox $mailbox;

    protected ?MailboxFolder $inbox = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support', 'email' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);
    }

    public function test_outgoing_sanitizer_keeps_formatting_and_removes_active_content(): void
    {
        $html = app(HtmlBodySanitizer::class)->outgoing(
            '<h2 style="color:red" class="x">Title</h2><p onclick="alert(1)"><strong>bold</strong> <em>it</em> <u>u</u> <s>s</s></p>'
            .'<script>alert(1)</script><style>p{}</style>'
            .'<ul><li>one</li></ul><blockquote>quote</blockquote>'
            .'<a href="javascript:alert(1)">bad</a><a href="https://example.com" target="_blank">good</a><a href="mailto:a@example.com">mail</a>'
            .'<table><tbody><tr><th colspan="2">h</th></tr><tr><td>c</td></tr></tbody></table>'
            .'<img src="https://tracker.example.com/pixel.gif"><img src="cid:abc@filament-mailbox" alt="logo">'
            .'<iframe src="https://example.com"></iframe><span>kept text</span>'
        );

        $this->assertStringContainsString('<h2>Title</h2>', $html);
        $this->assertStringContainsString('<strong>bold</strong> <em>it</em> <u>u</u> <s>s</s>', $html);
        $this->assertStringContainsString('<ul><li>one</li></ul><blockquote>quote</blockquote>', $html);
        $this->assertStringContainsString('<a href="https://example.com" rel="noopener noreferrer">good</a>', $html);
        $this->assertStringContainsString('<a href="mailto:a&#64;example.com" rel="noopener noreferrer">mail</a>', $html);
        $this->assertStringContainsString('<th colspan="2">h</th>', $html);
        $this->assertStringContainsString('<img src="cid:abc@filament-mailbox" alt="logo" />', $html);
        $this->assertStringContainsString('kept text', $html);

        foreach (['script', 'alert', 'style', 'class=', 'onclick', 'javascript:', 'tracker.example.com', 'iframe', 'target='] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_readable_text_alternative(): void
    {
        $text = HtmlToText::readable(
            '<h2>Status</h2><p>Hello <strong>Jane</strong>,<br>see <a href="https://example.com/report">the report</a>.</p>'
            .'<ul><li><p>first</p></li><li><p>second</p></li></ul><ol><li>one</li><li>two</li></ol>'
            .'<blockquote><p>quoted</p><p>lines</p></blockquote>'
            .'<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'
            .'<p><a href="mailto:jane@example.com">jane@example.com</a> &amp; more</p>'
        );

        $this->assertSame(
            "Status\n\nHello Jane,\nsee the report <https://example.com/report>.\n\n- first\n- second\n\n1. one\n2. two\n\n> quoted\n>\n> lines\n\nA | B\n1 | 2\n\njane@example.com & more",
            $text,
        );
    }

    public function test_images_become_inline_parts_and_foreign_sources_are_removed(): void
    {
        $directory = InlineImageProcessor::composeDirectory($this->user->getKey());
        Storage::disk('local')->put($directory.'/logo.png', self::PNG);
        Storage::disk('local')->put('mailbox/compose/999/secret.png', self::PNG);
        Storage::disk('local')->put($directory.'/notes.txt', 'text');

        $result = app(InlineImageProcessor::class)->process(
            '<p>Logo <img src="https://app.test/tmp" data-id="'.$directory.'/logo.png" alt="Logo" style="width: 10px"></p>'
            .'<p><img data-id="'.$directory.'/logo.png"></p>'
            .'<img data-id="mailbox/compose/999/secret.png">'
            .'<img data-id="'.$directory.'/../999/secret.png">'
            .'<img data-id="'.$directory.'/notes.txt">'
            .'<img src="https://tracker.example.com/pixel.gif">'
            .'<img src="data:image/png;base64,AAAA">',
            [$directory],
        );

        $this->assertCount(1, $result->attachments);
        $image = $result->attachments[0];
        $this->assertTrue($image->inline);
        $this->assertSame('image/png', $image->mimeType);
        $this->assertSame('logo.png', $image->filename);
        $this->assertStringEndsWith('@filament-mailbox', (string) $image->contentId);
        $this->assertSame(2, substr_count($result->html, 'src="cid:'.$image->contentId.'"'));
        $this->assertSame(2, substr_count($result->html, '<img'));
        $this->assertStringNotContainsString('data-id', $result->html);
        $this->assertStringNotContainsString('https://', $result->html);
        $this->assertSame([$directory.'/logo.png'], $result->paths);

        $this->assertFalse(InlineImageProcessor::isAllowedPath($directory.'/../999/secret.png', [$directory]));
        $this->assertFalse(InlineImageProcessor::isAllowedPath('/etc/passwd', ['/etc']));
        $this->assertFalse(InlineImageProcessor::isAllowedPath($directory.'-other/x.png', [$directory]));
    }

    public function test_html_message_is_sent_as_multipart_alternative_with_inline_image(): void
    {
        $directory = InlineImageProcessor::composeDirectory($this->user->getKey());
        Storage::disk('local')->put($directory.'/chart.png', self::PNG);

        Mail::fake();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->assertSchemaStateSet(['format' => 'html'])
            ->fillForm([
                'to' => ['jane@example.com'],
                'subject' => 'Report',
                'body_html' => '<p>Hi <strong>Jane</strong>,</p><p><img data-id="'.$directory.'/chart.png" alt="Chart"></p><p onclick="x()">Regards</p>',
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.actions.compose.success'));

        /** @var OutgoingMessage $sent */
        $sent = Mail::sent(OutgoingMessage::class)->sole();

        $this->assertStringContainsString('<strong>Jane</strong>', (string) $sent->data->bodyHtml);
        $this->assertStringNotContainsString('onclick', (string) $sent->data->bodyHtml);
        $this->assertSame("Hi Jane,\n\nChart\n\nRegards", $sent->data->body);
        $this->assertCount(1, $sent->data->attachments);
        $this->assertCount(0, $sent->attachments());

        $raw = app(MimeMessageBuilder::class)->build($this->mailbox, $sent->data);
        $contentId = $sent->data->attachments[0]->contentId;

        $this->assertMatchesRegularExpression('#Content-Type: multipart/related#', $raw);
        $this->assertMatchesRegularExpression('#Content-Type: multipart/alternative#', $raw);
        $this->assertStringContainsString('Content-ID: <'.$contentId.'>', $raw);
        $this->assertStringContainsString('Content-Disposition: inline; name=chart.png', $raw);
        $this->assertStringContainsString('cid:'.$contentId, quoted_printable_decode($raw));
        // CSS is inlined for mail clients without <style> support.
        $this->assertStringContainsString('<p style="margin: 0 0 12px 0;">', quoted_printable_decode($raw));
    }

    public function test_plain_text_mail_without_html_part(): void
    {
        $raw = app(MimeMessageBuilder::class)->build($this->mailbox, new OutgoingMessageData(
            to: ['jane@example.com'],
            subject: 'Hi',
            body: 'Plain',
            attachments: [new AttachmentData('logo.png', 'image/png', self::PNG, 'x@filament-mailbox', inline: true)],
        ));

        // Without HTML an inline flag is meaningless: the image is a normal attachment.
        $this->assertStringContainsString('Content-Disposition: attachment; name=logo.png', $raw);
    }

    public function test_switching_the_format_converts_the_body(): void
    {
        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->fillForm(['body_html' => '<p>Hello <strong>Jane</strong></p><ul><li><p>one</p></li></ul>'])
            ->fillForm(['format' => 'text'])
            ->assertSchemaStateSet(['body' => "Hello Jane\n\n- one"])
            ->fillForm(['body' => "Line 1\nLine 2\n\n<b>Para</b>"])
            ->fillForm(['format' => 'html'])
            ->assertSchemaStateSet(['body_html' => '<p>Line 1<br>Line 2</p><p>&lt;b&gt;Para&lt;/b&gt;</p>']);
    }

    public function test_mailbox_compose_format_is_preselected(): void
    {
        $this->mailbox->update(['compose_format' => 'text']);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->assertSchemaStateSet(['format' => 'text']);

        config(['filament-mailbox.compose.default_format' => 'text']);
        $this->assertSame('text', ComposeMessageForm::defaultFormat());
        $this->assertSame('html', ComposeMessageForm::defaultFormat(new Mailbox(['compose_format' => 'html'])));
    }

    public function test_reply_quotes_the_sanitised_original_without_images(): void
    {
        $message = $this->message([
            'html_body' => '<div style="color:red"><p>Hallo <b>Team</b></p><img src="cid:logo@example.com"><img src="https://tracker.example.com/p.gif"><script>alert(1)</script></div>',
        ]);

        $quote = (new ReplyBuilder)->quoteHtml($message);

        $this->assertStringStartsWith('<p>On ', $quote);
        $this->assertStringContainsString('John Doe wrote:</p><blockquote type="cite">', $quote);
        $this->assertStringContainsString('<p>Hallo <b>Team</b></p>', $quote);
        $this->assertStringNotContainsString('<img', $quote);
        $this->assertStringNotContainsString('script', $quote);
        $this->assertStringNotContainsString('style', $quote);

        $text = (new ReplyBuilder)->quoteHtml($this->message(['html_body' => null, 'text_body' => "Zeile 1\n<b>Zeile 2</b>"]));

        $this->assertStringContainsString('<blockquote type="cite"><p>Zeile 1<br>&lt;b&gt;Zeile 2&lt;/b&gt;</p></blockquote>', $text);
    }

    public function test_reply_in_html_sends_the_quote_below_the_answer(): void
    {
        Mail::fake();
        $message = $this->message(['html_body' => '<p>Original <i>text</i></p>']);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->mountAction('reply')
            ->fillForm(['body_html' => '<p>My answer</p>'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, function (OutgoingMessage $mail): bool {
            $html = (string) $mail->data->bodyHtml;

            return str_starts_with($html, '<p>My answer</p><p>On ')
                && str_contains($html, '<blockquote><p>Original <em>text</em></p></blockquote>')
                && str_contains($mail->data->body, "My answer\n\nOn ")
                && str_contains($mail->data->body, '> Original text');
        });
    }

    public function test_forward_in_html_contains_the_header_block_and_original(): void
    {
        $message = $this->message(['html_body' => '<p>Rechnung <b>anbei</b></p>']);

        $html = app(ForwardBuilder::class)->inlineBodyHtml($message);

        $this->assertStringStartsWith('<p>---------- Forwarded message ----------<br>From: John Doe &lt;john@example.com&gt;<br>', $html);
        $this->assertStringEndsWith('<p>Rechnung <b>anbei</b></p>', $html);
    }

    public function test_preview_embeds_images_as_data_uris(): void
    {
        $directory = InlineImageProcessor::composeDirectory($this->user->getKey());
        Storage::disk('local')->put($directory.'/chart.png', self::PNG);

        $document = ComposeMessageForm::previewDocument([
            'body_html' => '<p>Hi</p><img data-id="'.$directory.'/chart.png"><img src="https://tracker.example.com/p.gif">',
        ]);

        $this->assertStringContainsString('Content-Security-Policy', $document);
        $this->assertStringContainsString('src="data:image/png;base64,', $document);
        $this->assertStringNotContainsString('tracker.example.com', $document);
        $this->assertStringContainsString('<p style="margin: 0 0 12px 0;">Hi</p>', $document);
    }

    public function test_preview_action_renders(): void
    {
        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->fillForm(['body_html' => '<p>Preview me</p>'])
            ->mountAction(TestAction::make('preview')->schemaComponent('preview', 'mountedActionSchema0'))
            ->assertActionMounted([TestAction::make('compose'), TestAction::make('preview')->schemaComponent('preview', 'mountedActionSchema0')]);
    }

    public function test_prune_command_removes_old_compose_uploads(): void
    {
        $directory = InlineImageProcessor::composeDirectory($this->user->getKey());
        Storage::disk('local')->put($directory.'/old.png', self::PNG);
        Storage::disk('local')->put($directory.'/new.png', self::PNG);
        Storage::disk('local')->put('mailbox/other/old.png', self::PNG);
        touch(Storage::disk('local')->path($directory.'/old.png'), now()->subHours(30)->getTimestamp());
        touch(Storage::disk('local')->path('mailbox/other/old.png'), now()->subHours(30)->getTimestamp());

        $this->artisan('mailbox:prune-compose-uploads')->assertSuccessful();

        Storage::disk('local')->assertMissing($directory.'/old.png');
        Storage::disk('local')->assertExists($directory.'/new.png');
        Storage::disk('local')->assertExists('mailbox/other/old.png');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function message(array $attributes): MailboxMessage
    {
        return MailboxMessage::factory()
            ->for($this->inbox ??= MailboxFolder::factory()->for($this->mailbox)->inbox()->create(), 'folder')
            ->create([
                'is_read' => true,
                'from_name' => 'John Doe',
                'from_address' => 'john@example.com',
                'subject' => 'Rechnung',
                'text_body' => null,
                'sent_at' => Carbon::parse('2026-09-17 08:42:00'),
                ...$attributes,
            ]);
    }
}
