<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ListMailboxes;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class MailboxResourceTest extends TestCase
{
    public function test_list_page_renders(): void
    {
        $mailbox = Mailbox::factory()->create();
        $mailbox->users()->attach($this->user);

        Livewire::test(ListMailboxes::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$mailbox]);
    }

    public function test_mailbox_can_be_created(): void
    {
        Livewire::test(CreateMailbox::class)
            ->fillForm([
                'name' => 'Support',
                'email' => 'support@example.com',
                'host' => 'imap.example.com',
                'port' => 993,
                'encryption' => Encryption::Ssl,
                'username' => 'support@example.com',
                'password' => 'top-secret',
                'users' => [$this->user->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $mailbox = Mailbox::sole();

        $this->assertSame('Support', $mailbox->name);
        $this->assertSame('top-secret', $mailbox->password);
        $this->assertTrue($mailbox->isAssignedTo($this->user));
    }

    public function test_create_validates_required_fields(): void
    {
        Livewire::test(CreateMailbox::class)
            ->fillForm([
                'name' => null,
                'email' => 'not-an-email',
                'host' => null,
                'password' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'required',
                'email' => 'email',
                'host' => 'required',
                'password' => 'required',
            ]);
    }

    public function test_mailbox_can_be_updated_without_changing_password(): void
    {
        $mailbox = Mailbox::factory()->create(['password' => 'original']);
        $mailbox->users()->attach($this->user);

        Livewire::test(EditMailbox::class, ['record' => $mailbox->getRouteKey()])
            ->assertSchemaStateSet(['name' => $mailbox->name, 'password' => null])
            ->fillForm(['name' => 'Renamed', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $mailbox->refresh();

        $this->assertSame('Renamed', $mailbox->name);
        $this->assertSame('original', $mailbox->password);
    }

    public function test_password_can_be_changed(): void
    {
        $mailbox = Mailbox::factory()->create(['password' => 'original']);
        $mailbox->users()->attach($this->user);

        Livewire::test(EditMailbox::class, ['record' => $mailbox->getRouteKey()])
            ->fillForm(['password' => 'changed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('changed', $mailbox->refresh()->password);
    }

    public function test_credentials_are_stored_encrypted_and_hidden(): void
    {
        $mailbox = Mailbox::factory()->create(['password' => 'plain-secret']);

        $raw = DB::table('mailboxes')->where('id', $mailbox->id)->value('password');

        $this->assertNotSame('plain-secret', $raw);
        $this->assertStringNotContainsString('plain-secret', $raw);
        $this->assertArrayNotHasKey('password', $mailbox->toArray());
        $this->assertStringNotContainsString('plain-secret', $mailbox->toJson());
    }
}
