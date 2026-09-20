<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ListMailboxes;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\AttachmentService;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\Fixtures\User;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class AuthorizationTest extends TestCase
{
    protected Mailbox $own;

    protected Mailbox $foreign;

    protected MailboxMessage $ownMessage;

    protected MailboxMessage $foreignMessage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->fakeProvider();

        $this->own = Mailbox::factory()->create(['name' => 'Own mailbox']);
        $this->own->users()->attach($this->user);
        $this->ownMessage = MailboxMessage::factory()->for(MailboxFolder::factory()->for($this->own)->inbox(), 'folder')->create(['is_read' => true]);

        $this->foreign = Mailbox::factory()->create(['name' => 'Foreign mailbox']);
        $this->foreign->users()->attach(User::make('Someone else'));
        $this->foreignMessage = MailboxMessage::factory()->for(MailboxFolder::factory()->for($this->foreign)->inbox(), 'folder')->create(['is_read' => true]);

        // The acting user is a regular user, not a mailbox manager.
        Gate::define(MailboxAuthorization::MANAGE, fn () => false);
    }

    public function test_user_only_sees_assigned_mailboxes(): void
    {
        Livewire::test(ListMailboxes::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->own])
            ->assertCanNotSeeTableRecords([$this->foreign]);
    }

    public function test_user_cannot_open_a_foreign_mailbox(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(BrowseMailbox::class, ['record' => $this->foreign->id]);
    }

    public function test_user_cannot_read_a_foreign_message(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ViewMessage::class, ['record' => $this->foreign->id, 'message' => $this->foreignMessage->id]);
    }

    public function test_user_cannot_read_a_foreign_message_through_own_mailbox_url(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ViewMessage::class, ['record' => $this->own->id, 'message' => $this->foreignMessage->id]);
    }

    public function test_user_cannot_download_foreign_attachments(): void
    {
        $own = $this->attachment($this->ownMessage);
        $foreign = $this->attachment($this->foreignMessage);
        $service = app(AttachmentService::class);

        $this->get($service->downloadUrl($own))->assertOk();
        $this->get($service->downloadUrl($foreign))->assertNotFound();
    }

    public function test_manager_can_configure_but_not_read_unassigned_mailboxes(): void
    {
        Gate::define(MailboxAuthorization::MANAGE, fn () => true);

        Livewire::test(ListMailboxes::class)->assertCanSeeTableRecords([$this->own, $this->foreign]);
        Livewire::test(EditMailbox::class, ['record' => $this->foreign->id])->assertOk();

        Livewire::test(BrowseMailbox::class, ['record' => $this->foreign->id])->assertForbidden();
        Livewire::test(ViewMessage::class, ['record' => $this->foreign->id, 'message' => $this->foreignMessage->id])->assertForbidden();
    }

    public function test_regular_user_cannot_create_or_edit_mailboxes(): void
    {
        $this->assertFalse(MailboxResource::canCreate());
        $this->assertFalse(MailboxResource::canEdit($this->own));

        Livewire::test(CreateMailbox::class)->assertForbidden();
        Livewire::test(EditMailbox::class, ['record' => $this->own->id])->assertForbidden();
    }

    public function test_sending_can_be_restricted(): void
    {
        Mail::fake();
        app(MailboxAuthorization::class)->using(MailboxAuthorization::SEND, fn () => false);

        Livewire::test(BrowseMailbox::class, ['record' => $this->own->id])
            ->assertActionHidden('compose');

        Livewire::test(ViewMessage::class, ['record' => $this->own->id, 'message' => $this->ownMessage->id])
            ->assertActionHidden('reply')
            ->assertActionHidden('replyAll')
            ->assertActionHidden('forward');

        Mail::assertNothingSent();
    }

    public function test_deleting_can_be_restricted(): void
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::DELETE, fn () => false);

        Livewire::test(BrowseMailbox::class, ['record' => $this->own->id])
            ->assertActionHidden(TestAction::make('deleteMessage')->table($this->ownMessage))
            ->assertActionHidden(TestAction::make('deleteMessages')->table()->bulk());

        Livewire::test(ViewMessage::class, ['record' => $this->own->id, 'message' => $this->ownMessage->id])
            ->assertActionHidden('deleteMessage');

        $this->assertNotSoftDeleted($this->ownMessage);
    }

    public function test_foreign_messages_cannot_be_injected_into_bulk_actions(): void
    {
        Livewire::test(BrowseMailbox::class, ['record' => $this->own->id])
            ->selectTableRecords([$this->ownMessage->id, $this->foreignMessage->id])
            ->callAction(TestAction::make('deleteMessages')->table()->bulk());

        $this->assertSoftDeleted($this->ownMessage);
        $this->assertNotSoftDeleted($this->foreignMessage);
    }

    public function test_connection_test_requires_manage_ability(): void
    {
        Gate::define(MailboxAuthorization::MANAGE, fn () => true);

        Livewire::test(EditMailbox::class, ['record' => $this->foreign->id])
            ->assertActionVisible('testConnection');

    }

    public function test_policies_deny_unassigned_users(): void
    {
        $stranger = User::make('Stranger');

        $this->assertFalse(Gate::forUser($stranger)->allows('view', $this->own));
        $this->assertFalse(Gate::forUser($stranger)->allows('send', $this->own));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $this->ownMessage));
        $this->assertFalse(Gate::forUser($stranger)->allows('update', $this->ownMessage));
        $this->assertFalse(Gate::forUser($stranger)->allows('delete', $this->ownMessage));
        $this->assertFalse(Gate::forUser($stranger)->allows('reply', $this->ownMessage));

        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->ownMessage));
        $this->assertTrue(Gate::forUser($this->user)->allows('delete', $this->ownMessage));
    }

    protected function attachment(MailboxMessage $message): MailboxAttachment
    {
        $path = 'mailbox/'.uniqid();
        Storage::disk('local')->put($path, 'content');

        return MailboxAttachment::factory()->for($message, 'message')->create(['disk' => 'local', 'storage_path' => $path]);
    }
}
