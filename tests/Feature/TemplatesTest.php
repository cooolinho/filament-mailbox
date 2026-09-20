<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Events\TemplateUsed;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxTemplates\MailboxTemplateResource;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxTemplates\Pages\ListMailboxTemplates;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxTemplate;
use Cooolinho\FilamentMailbox\Models\MailboxTemplateAttachment;
use Cooolinho\FilamentMailbox\Services\TemplateRenderer;
use Cooolinho\FilamentMailbox\Services\TemplateRepository;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Support\TemplateContext;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class TemplatesTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-09-20 10:00:00');

        $this->user->update(['name' => 'Agent Smith']);

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support', 'email' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);

        $this->message = MailboxMessage::factory()
            ->for(MailboxFolder::factory()->for($this->mailbox)->inbox(), 'folder')
            ->create([
                'from_name' => 'Doe, <b>Jane</b>',
                'from_address' => 'jane@example.com',
                'subject' => 'Invoice 4711',
                'text_body' => 'Where is my invoice?',
                'html_body' => null,
                'sent_at' => Carbon::parse('2026-09-18 08:42:00'),
            ]);
    }

    public function test_renderer_replaces_known_placeholders_and_keeps_unknown_ones(): void
    {
        $variables = ['recipient.first_name' => '<b>Jane</b>', 'user.name' => 'Agent'];
        $renderer = app(TemplateRenderer::class);

        $text = $renderer->render("Hello {recipient.first_name},\n{unknown} {recipient.last_name} {{ 7*7 }} {!! \$x !!} @php echo 1; @endphp\n{user.name}", $variables);

        $this->assertSame("Hello <b>Jane</b>,\n{unknown} {recipient.last_name} {{ 7*7 }} {!! \$x !!} @php echo 1; @endphp\nAgent", $text->content);
        $this->assertSame(['unknown', 'recipient.last_name'], $text->unknown);

        $html = $renderer->render('<p>Hello {recipient.first_name}</p>', $variables, 'html');
        $this->assertSame('<p>Hello &lt;b&gt;Jane&lt;/b&gt;</p>', $html->content);
    }

    public function test_context_values(): void
    {
        $variables = TemplateContext::for($this->mailbox, $this->user, $this->message);

        $this->assertSame('Doe, <b>Jane</b>', $variables['recipient.name']);
        $this->assertSame('<b>Jane</b>', $variables['recipient.first_name']);
        $this->assertSame('jane@example.com', $variables['recipient.email']);
        $this->assertSame('Invoice 4711', $variables['original.subject']);
        $this->assertSame('Fri, Sep 18, 2026', $variables['original.date']);
        $this->assertSame('Agent Smith', $variables['user.name']);
        $this->assertSame('Support', $variables['mailbox.name']);
        $this->assertSame('Sun, Sep 20, 2026', $variables['today']);

        $new = TemplateContext::for($this->mailbox, $this->user, null, ['max@example.com']);
        $this->assertSame(['', '', 'max@example.com', ''], [$new['recipient.name'], $new['recipient.first_name'], $new['recipient.email'], $new['original.subject']]);

        $forward = TemplateContext::for($this->mailbox, $this->user, $this->message, ['team@example.com'], recipientIsSender: false);
        $this->assertSame(['team@example.com', 'Invoice 4711'], [$forward['recipient.email'], $forward['original.subject']]);

        $this->assertSame('Jane', TemplateContext::firstName('Jane Doe'));
        $this->assertSame('', TemplateContext::firstName('jane@example.com'));
        $this->assertSame('', TemplateContext::firstName(null));
    }

    public function test_available_templates_are_scoped_by_mailbox_and_context(): void
    {
        $global = MailboxTemplate::factory()->create(['name' => 'Global', 'category' => 'Billing']);
        $own = MailboxTemplate::factory()->for($this->mailbox)->contexts(['reply'])->create(['name' => 'Own reply']);
        MailboxTemplate::factory()->for(Mailbox::factory())->create(['name' => 'Foreign']);
        MailboxTemplate::factory()->contexts(['new'])->create(['name' => 'Only new']);

        $repository = app(TemplateRepository::class);

        $this->assertEqualsCanonicalizing(['Global', 'Own reply'], $repository->availableFor($this->mailbox, ComposeContext::Reply)->pluck('name')->all());
        $this->assertSame(['Billing' => [$global->id => 'Global'], 'Other' => [$own->id => 'Own reply']], $repository->options($this->mailbox, ComposeContext::Reply));
        $this->assertNull($repository->find($this->mailbox, ComposeContext::New, $own->id));
    }

    public function test_inserting_into_an_empty_body_replaces_it_and_sets_the_subject_of_new_messages(): void
    {
        $template = MailboxTemplate::factory()->create([
            'subject' => 'Offer for {recipient.email}',
            'body_text' => "Hello,\nthanks for contacting {mailbox.name}.",
            'body_html' => '<p>Hello,</p><p>thanks for contacting <strong>{mailbox.name}</strong>.</p>',
        ]);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->fillForm(['to' => ['max@example.com'], 'format' => 'text'])
            ->fillForm(['template_id' => $template->id])
            ->assertSchemaStateSet([
                'template_id' => null,
                'subject' => 'Offer for max@example.com',
                'body' => "Hello,\nthanks for contacting Support.",
                'template_ids' => [$template->id],
            ])
            ->fillForm(['format' => 'html', 'body_html' => null, 'subject' => 'Kept'])
            ->fillForm(['template_id' => $template->id])
            ->assertSchemaStateSet([
                'subject' => 'Kept',
                'body_html' => '<p>Hello,</p><p>thanks for contacting <strong>Support</strong>.</p>',
            ]);
    }

    public function test_inserting_appends_to_existing_text_and_never_changes_reply_subjects(): void
    {
        Mail::fake();
        Event::fake([TemplateUsed::class]);

        $template = MailboxTemplate::factory()->create([
            'subject' => 'Template subject',
            'body_text' => 'Dear {recipient.first_name}, your invoice for "{original.subject}" follows.',
            'body_html' => null,
        ]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $this->message->id])
            ->mountAction('reply')
            ->fillForm(['format' => 'html', 'body_html' => '<p>Hi!</p>'])
            ->fillForm(['template_id' => $template->id])
            ->assertSchemaStateSet([
                'subject' => 'Re: Invoice 4711',
                'body_html' => '<p>Hi!</p><p>Dear &lt;b&gt;Jane&lt;/b&gt;, your invoice for &quot;Invoice 4711&quot; follows.</p>',
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => str_contains((string) $mail->data->bodyHtml, 'Dear &lt;b&gt;Jane&lt;/b&gt;'));

        $this->assertSame(1, $template->refresh()->usage_count);
        Event::assertDispatched(TemplateUsed::class, fn (TemplateUsed $event): bool => $event->template->is($template) && $event->context === ComposeContext::Reply && $event->userId === $this->user->id);
    }

    public function test_unknown_placeholders_are_reported(): void
    {
        $template = MailboxTemplate::factory()->create(['body_text' => 'Hello {customer.number}']);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->fillForm(['format' => 'text', 'template_id' => $template->id])
            ->assertSchemaStateSet(['body' => 'Hello {customer.number}'])
            ->assertNotified(__('filament-mailbox::mailbox.templates.unknown_placeholders', ['placeholders' => '{customer.number}']));
    }

    public function test_template_attachments_are_preselected_and_can_be_deselected(): void
    {
        Mail::fake();

        $template = MailboxTemplate::factory()->create(['body_text' => 'See attachments']);
        $terms = $this->templateAttachment($template, 'terms.pdf', 'TERMS');
        $prices = $this->templateAttachment($template, 'prices.pdf', 'PRICES');

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->fillForm(['to' => ['max@example.com'], 'subject' => 'Offer', 'format' => 'text', 'template_id' => $template->id])
            ->assertSchemaStateSet(['attachments_template_id' => $template->id, 'template_attachments' => [$terms->id, $prices->id]])
            ->fillForm(['template_attachments' => [$prices->id]])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => array_map(fn ($attachment) => [$attachment->filename, $attachment->contents], $mail->data->attachments) === [['prices.pdf', 'PRICES']]);
    }

    public function test_foreign_templates_and_attachments_are_rejected(): void
    {
        Mail::fake();

        $foreign = MailboxTemplate::factory()->for(Mailbox::factory())->create(['body_text' => 'Secret']);
        $foreignAttachment = $this->templateAttachment($foreign, 'secret.pdf', 'SECRET');
        $own = MailboxTemplate::factory()->create(['body_text' => 'Own']);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->fillForm(['format' => 'text', 'template_id' => $foreign->id])
            ->assertSchemaStateSet(['body' => '', 'template_ids' => null]);

        // A manipulated state with a foreign template id: its attachments are neither offered nor sent.
        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: [
                'to' => ['max@example.com'],
                'subject' => 'Hi',
                'format' => 'text',
                'body' => 'Hi',
                'attachments_template_id' => $foreign->id,
                'template_attachments' => [$foreignAttachment->id],
            ])
            ->assertHasFormErrors(['template_attachments.0']);

        Mail::assertNothingSent();
        $this->assertSame([], app(TemplateRepository::class)->attachments($this->mailbox, ComposeContext::New, $foreign->id, [$foreignAttachment->id]));

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: [
                'to' => ['max@example.com'],
                'subject' => 'Hi',
                'format' => 'text',
                'body' => 'Hi',
                'template_ids' => [$foreign->id, $own->id],
            ])
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->data->attachments === []);
        $this->assertSame(0, $foreign->refresh()->usage_count);
        $this->assertSame(1, $own->refresh()->usage_count);
    }

    public function test_managers_maintain_templates_with_attachments(): void
    {
        Livewire::test(ListMailboxTemplates::class)
            ->callAction('create', data: [
                'name' => 'Offer',
                'category' => 'Sales',
                'contexts' => ['new', 'reply'],
                'subject' => 'Our offer',
                'body_text' => 'Hello {recipient.first_name}',
                'body_html' => '<p onclick="x()">Hello <b>{recipient.first_name}</b></p><script>alert(1)</script>',
                'attachment_paths' => [UploadedFile::fake()->createWithContent('price list.pdf', 'PDF')],
            ])
            ->assertHasNoFormErrors();

        $template = MailboxTemplate::sole();
        $this->assertSame(['new', 'reply'], $template->contexts);
        $this->assertSame('<p>Hello <strong>{recipient.first_name}</strong></p>', $template->body_html);
        $this->assertSame($this->user->id, $template->created_by);

        $attachment = $template->attachments()->sole();
        $this->assertSame('local', $attachment->disk);
        $this->assertStringStartsWith('mailbox/templates/attachments/', $attachment->storage_path);
        Storage::disk('local')->assertExists($attachment->storage_path);

        $preview = MailboxTemplateResource::preview($template);
        $this->assertSame('Our offer', $preview['subject']);
        $this->assertSame('<p>Hello <strong>Jane</strong></p>', $preview['html']->toHtml());

        Livewire::test(ListMailboxTemplates::class)
            ->callAction(TestAction::make('preview')->table($template))
            ->assertHasNoErrors();

        // Removing the file in the form deletes the attachment and the stored file.
        Livewire::test(ListMailboxTemplates::class)
            ->callAction(TestAction::make('edit')->table($template), data: ['attachment_paths' => []])
            ->assertHasNoFormErrors();

        $this->assertSame(0, $template->attachments()->count());
        Storage::disk('local')->assertMissing($attachment->storage_path);

        // Paths of other files cannot be injected.
        Storage::disk('local')->put('mailbox/attachments/other.pdf', 'OTHER');

        Livewire::test(ListMailboxTemplates::class)
            ->callAction(TestAction::make('edit')->table($template), data: ['attachment_paths' => ['mailbox/attachments/other.pdf']])
            ->assertHasFormErrors(['attachment_paths']);
    }

    public function test_preview_marks_unknown_placeholders(): void
    {
        $template = MailboxTemplate::factory()->create(['body_text' => 'Order {order.number} for {recipient.name}', 'body_html' => null]);

        $preview = MailboxTemplateResource::preview($template);

        $this->assertSame('<p>Order <mark>{order.number}</mark> for Jane Doe</p>', $preview['html']->toHtml());
        $this->assertSame(['order.number'], $preview['unknown']);
    }

    public function test_managing_templates_requires_the_ability(): void
    {
        $template = MailboxTemplate::factory()->create();

        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => false);

        $this->assertFalse(MailboxTemplateResource::canViewAny());
        $this->assertFalse(MailboxTemplateResource::canEdit($template));

        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE_TEMPLATES, fn () => true);

        $this->assertTrue(MailboxTemplateResource::canViewAny());
        $this->assertTrue(MailboxTemplateResource::canDelete($template));

        config(['filament-mailbox.templates.enabled' => false]);
        $this->assertFalse(MailboxTemplateResource::canViewAny());
    }

    public function test_deleting_a_template_removes_its_files(): void
    {
        $template = MailboxTemplate::factory()->create();
        $attachment = $this->templateAttachment($template, 'terms.pdf', 'TERMS');

        $template->delete();

        Storage::disk('local')->assertMissing($attachment->storage_path);
        $this->assertSame(0, MailboxTemplateAttachment::count());
    }

    protected function templateAttachment(MailboxTemplate $template, string $filename, string $contents): MailboxTemplateAttachment
    {
        $path = 'mailbox/templates/attachments/'.fake()->uuid().'.pdf';
        Storage::disk('local')->put($path, $contents);

        return $template->attachments()->create([
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'size' => strlen($contents),
            'disk' => 'local',
            'storage_path' => $path,
        ]);
    }
}
