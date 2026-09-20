<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use RuntimeException;

class ConnectionTest extends TestCase
{
    public function test_connection_failure_message_does_not_contain_password(): void
    {
        $mailbox = Mailbox::factory()->make(['password' => 'hunter2']);

        $exception = ConnectionFailed::for($mailbox, new RuntimeException('LOGIN user hunter2 rejected'));

        $this->assertStringNotContainsString('hunter2', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    public function test_successful_connection_test_notifies_user(): void
    {
        $provider = $this->fakeProvider();
        $mailbox = Mailbox::factory()->create();

        Livewire::test(EditMailbox::class, ['record' => $mailbox->getRouteKey()])
            ->callAction('testConnection')
            ->assertNotified(__('filament-mailbox::mailbox.actions.test_connection.success'));

        $this->assertTrue($provider->disconnected);
    }

    public function test_failed_connection_test_notifies_user_without_credentials(): void
    {
        $mailbox = Mailbox::factory()->create(['password' => 'hunter2']);
        $provider = $this->fakeProvider();
        $provider->connectionException = ConnectionFailed::for($mailbox, new RuntimeException('bad login hunter2'));

        Livewire::test(EditMailbox::class, ['record' => $mailbox->getRouteKey()])
            ->callAction('testConnection')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title(__('filament-mailbox::mailbox.actions.test_connection.failure'))
                    ->body('Connection to imap.example.com:993 failed: bad login ********'),
            );
    }

    public function test_connection_test_uses_unsaved_form_values(): void
    {
        $this->fakeProvider();
        $mailbox = Mailbox::factory()->create(['host' => 'old.example.com']);
        $factory = $this->app->make(MailboxProviderFactory::class);

        Livewire::test(EditMailbox::class, ['record' => $mailbox->getRouteKey()])
            ->fillForm(['host' => 'new.example.com'])
            ->callAction('testConnection');

        $this->assertSame('new.example.com', end($factory->made)->host);
        $this->assertSame('old.example.com', $mailbox->refresh()->host);
        $this->assertSame(1, Mailbox::count());
    }
}
