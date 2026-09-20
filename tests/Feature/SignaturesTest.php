<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Filament\Pages\MySignatures;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers\SignaturesRelationManager;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxSignature;
use Cooolinho\FilamentMailbox\Services\InlineImageProcessor;
use Cooolinho\FilamentMailbox\Services\SignatureRenderer;
use Cooolinho\FilamentMailbox\Services\SignatureResolver;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\Fixtures\User;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class SignaturesTest extends TestCase
{
    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user->update(['name' => 'Jane <Doe>', 'email' => 'jane@example.com']);

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support & Sales', 'email' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);
    }

    public function test_personal_default_wins_over_the_mailbox_default_per_context(): void
    {
        $resolver = app(SignatureResolver::class);

        $shared = MailboxSignature::factory()->for($this->mailbox)->create(['name' => 'Team', 'is_default_new' => true, 'is_default_reply' => true]);
        $personal = MailboxSignature::factory()->for($this->mailbox)->personal($this->user)->create(['name' => 'Jane', 'is_default_reply' => true]);
        MailboxSignature::factory()->for($this->mailbox)->personal(User::make('Other'))->create(['name' => 'Other', 'is_default_new' => true]);
        MailboxSignature::factory()->create(['name' => 'Foreign mailbox', 'is_default_new' => true]);

        $this->assertSame($shared->id, $resolver->defaultFor($this->mailbox, $this->user, ComposeContext::New)?->id);
        $this->assertSame($personal->id, $resolver->defaultFor($this->mailbox, $this->user, ComposeContext::Reply)?->id);
        $this->assertNull($resolver->defaultFor($this->mailbox, $this->user, ComposeContext::Forward));
        $this->assertSame(['Jane', 'Team'], $resolver->available($this->mailbox, $this->user)->pluck('name')->all());

        config(['filament-mailbox.signatures.personal' => false]);
        $this->assertSame($shared->id, $resolver->defaultFor($this->mailbox, $this->user, ComposeContext::Reply)?->id);

        config(['filament-mailbox.signatures.enabled' => false]);
        $this->assertCount(0, $resolver->available($this->mailbox, $this->user));

        // Users not assigned to the mailbox get no signatures.
        config(['filament-mailbox.signatures.enabled' => true]);
        $this->assertCount(0, $resolver->available($this->mailbox, User::make('Unassigned')));
    }

    public function test_only_one_default_per_scope_and_context(): void
    {
        $first = MailboxSignature::factory()->for($this->mailbox)->create(['is_default_new' => true, 'is_default_reply' => true]);
        $personal = MailboxSignature::factory()->for($this->mailbox)->personal($this->user)->create(['is_default_new' => true]);
        $second = MailboxSignature::factory()->for($this->mailbox)->create(['is_default_new' => true]);

        $this->assertFalse($first->refresh()->is_default_new);
        $this->assertTrue($first->is_default_reply);
        $this->assertTrue($second->refresh()->is_default_new);
        // Personal signatures are a separate scope.
        $this->assertTrue($personal->refresh()->is_default_new);
    }

    public function test_renderer_replaces_placeholders_without_executing_template_code(): void
    {
        $renderer = app(SignatureRenderer::class);
        $signature = MailboxSignature::factory()->for($this->mailbox)->make([
            'body_text' => "{user.name}\n{mailbox.name} <{mailbox.email}>\n{{ 7*7 }} @php echo 1; @endphp {unknown.key}",
            'body_html' => null,
        ]);

        $this->assertSame(
            "-- \nJane <Doe>\nSupport & Sales <support@example.com>\n{{ 7*7 }} @php echo 1; @endphp {unknown.key}",
            $renderer->textBlock($signature, $this->mailbox, $this->user),
        );

        // Without an HTML variant the text becomes escaped paragraphs.
        $this->assertSame(
            '<p>Jane &lt;Doe&gt;<br>Support &amp; Sales &lt;support@example.com&gt;<br>{{ 7*7 }} @php echo 1; @endphp {unknown.key}</p>',
            $renderer->html($signature, $this->mailbox, $this->user),
        );

        $signature->body_html = '<p><strong>{user.name}</strong> · <a href="mailto:{user.email}">{user.email}</a></p>';

        $this->assertSame(
            '<p><strong>Jane &lt;Doe&gt;</strong> · <a href="mailto:jane@example.com">jane@example.com</a></p>',
            $renderer->html($signature, $this->mailbox, $this->user),
        );
    }

    public function test_html_is_sanitised_when_saved_and_keeps_image_paths(): void
    {
        $signature = MailboxSignature::factory()->for($this->mailbox)->create([
            'body_html' => '<p style="color:red" onclick="x()">Hi</p><script>alert(1)</script><img data-id="mailbox/signatures/logo.png" src="https://tracker.example.com/p.gif"><img src="https://tracker.example.com/p.gif">',
        ]);

        $this->assertSame('<p>Hi</p><img data-id="mailbox/signatures/logo.png" />', $signature->refresh()->body_html);

        $signature->update(['body_html' => '<p></p>']);
        $this->assertNull($signature->refresh()->body_html);
    }

    public function test_compose_preselects_the_default_and_appends_the_text_signature(): void
    {
        Mail::fake();
        MailboxSignature::factory()->for($this->mailbox)->create(['name' => 'Team', 'body_text' => 'Team {mailbox.name}', 'is_default_new' => true]);
        $personal = MailboxSignature::factory()->for($this->mailbox)->personal($this->user)->create(['name' => 'Jane', 'body_text' => '{user.name}']);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->mountAction('compose')
            ->assertSchemaStateSet(fn (array $state): bool => $state['signature_id'] !== null)
            ->fillForm([
                'to' => ['john@example.com'],
                'subject' => 'Hello',
                'format' => 'text',
                'body' => 'Hi John',
                'signature_id' => $personal->id,
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->data->body === "Hi John\n\n-- \nJane <Doe>");
    }

    public function test_reply_signature_is_placed_above_the_quote(): void
    {
        Mail::fake();
        MailboxSignature::factory()->for($this->mailbox)->create([
            'body_text' => 'Support team',
            'body_html' => '<p><strong>Support</strong> team</p>',
            'is_default_reply' => true,
        ]);

        $message = MailboxMessage::factory()
            ->for(MailboxFolder::factory()->for($this->mailbox)->inbox(), 'folder')
            ->create([
                'from_name' => 'John Doe',
                'from_address' => 'john@example.com',
                'subject' => 'Question',
                'text_body' => 'Original question',
                'html_body' => null,
                'sent_at' => Carbon::parse('2026-09-17 08:42:00'),
            ]);

        $component = fn () => Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->mountAction('reply');

        $component()
            ->fillForm(['body_html' => '<p>Answer</p>'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $component()
            ->fillForm(['format' => 'text', 'body' => 'Answer'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $sent = Mail::sent(OutgoingMessage::class)->values();

        $this->assertStringStartsWith('<p>Answer</p><div data-signature="1"><p><strong>Support</strong> team</p></div><p>On ', (string) $sent[0]->data->bodyHtml);
        $this->assertStringStartsWith("Answer\n\n-- \nSupport team\n\nOn ", $sent[1]->data->body);
        $this->assertStringEndsWith('> Original question', $sent[1]->data->body);
    }

    public function test_foreign_signatures_cannot_be_selected(): void
    {
        Mail::fake();
        MailboxSignature::factory()->for($this->mailbox)->create();
        $foreign = MailboxSignature::factory()->for($this->mailbox)->personal(User::make('Other'))->create(['body_text' => 'Secret']);
        $otherMailbox = MailboxSignature::factory()->create(['body_text' => 'Other mailbox']);

        foreach ([$foreign, $otherMailbox] as $signature) {
            Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
                ->callAction('compose', data: [
                    'to' => ['john@example.com'],
                    'subject' => 'Hello',
                    'format' => 'text',
                    'body' => 'Hi',
                    'signature_id' => $signature->id,
                ])
                ->assertHasFormErrors(['signature_id']);
        }

        Mail::assertNothingSent();
    }

    public function test_signature_images_are_sent_as_inline_parts(): void
    {
        Storage::fake('local');
        Mail::fake();
        Storage::disk('local')->put(InlineImageProcessor::directory('signatures').'/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4//8/AAX+Av6nNYGEAAAAAElFTkSuQmCC'));

        MailboxSignature::factory()->for($this->mailbox)->create([
            'body_html' => '<p><img data-id="mailbox/signatures/logo.png" alt="Logo"></p>',
            'is_default_new' => true,
        ]);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: ['to' => ['john@example.com'], 'subject' => 'Hello', 'body_html' => '<p>Hi</p>'])
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.actions.compose.success'));

        Mail::assertSent(OutgoingMessage::class, function (OutgoingMessage $mail): bool {
            $image = $mail->data->attachments[0] ?? null;

            return $image?->inline === true
                && str_contains((string) $mail->data->bodyHtml, '<div data-signature="1"><p><img alt="Logo" src="cid:'.$image->contentId.'" /></p></div>');
        });
    }

    public function test_managers_maintain_mailbox_signatures(): void
    {
        MailboxSignature::factory()->for($this->mailbox)->personal($this->user)->create(['name' => 'Personal']);
        $shared = MailboxSignature::factory()->for($this->mailbox)->create(['name' => 'Shared']);

        $manager = fn () => Livewire::test(SignaturesRelationManager::class, ['ownerRecord' => $this->mailbox, 'pageClass' => EditMailbox::class]);

        $manager()
            ->assertCanSeeTableRecords([$shared])
            ->assertCountTableRecords(1)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Legal', 'body_html' => null, 'body_text' => '', 'is_default_new' => true])
            ->assertHasFormErrors(['body_text']);

        $manager()
            ->callAction(TestAction::make('create')->table(), ['name' => 'Legal', 'body_text' => "ACME GmbH\nHRB 1234", 'is_default_new' => true])
            ->assertHasNoFormErrors();

        $legal = MailboxSignature::where('name', 'Legal')->sole();
        $this->assertNull($legal->user_id);
        $this->assertTrue($legal->is_default_new);

        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => false);

        $this->assertFalse(SignaturesRelationManager::canViewForRecord($this->mailbox, EditMailbox::class));
        $this->assertFalse(Gate::allows('update', $shared));
    }

    public function test_users_maintain_their_personal_signatures(): void
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => false);

        $unassigned = Mailbox::factory()->create();
        $foreign = MailboxSignature::factory()->for($this->mailbox)->personal(User::make('Other'))->create(['name' => 'Foreign']);
        $shared = MailboxSignature::factory()->for($this->mailbox)->create(['name' => 'Shared']);

        $this->get(MySignatures::getUrl())->assertSuccessful();

        Livewire::test(MySignatures::class)
            ->assertCountTableRecords(0)
            ->callAction(TestAction::make('create')->table(), ['mailbox_id' => $unassigned->id, 'name' => 'Mine', 'body_text' => 'Jane'])
            ->assertHasFormErrors(['mailbox_id']);

        Livewire::test(MySignatures::class)
            ->callAction(TestAction::make('create')->table(), ['mailbox_id' => $this->mailbox->id, 'name' => 'Mine', 'body_text' => 'Jane', 'is_default_reply' => true])
            ->assertHasNoFormErrors()
            ->assertCountTableRecords(1);

        $mine = MailboxSignature::where('name', 'Mine')->sole();
        $this->assertSame($this->user->id, $mine->user_id);
        $this->assertTrue(Gate::allows('update', $mine));
        $this->assertFalse(Gate::allows('update', $foreign));
        $this->assertFalse(Gate::allows('delete', $shared));

        config(['filament-mailbox.signatures.personal' => false]);
        $this->assertFalse(MySignatures::canAccess());
        $this->assertFalse(Gate::allows('update', $mine));
    }
}
