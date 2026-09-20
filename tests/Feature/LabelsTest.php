<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\LabelsChanged;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers\LabelsRelationManager;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\LabelService;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Support\ImapKeyword;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;

class LabelsTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);

        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox), uidValidity: 1)
            ->addFolder(new FolderData('Archive', 'Archive', '/'), uidValidity: 1);

        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->archive = MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'Archive', 'full_name' => 'Archive']);
    }

    public function test_imap_keywords_are_converted(): void
    {
        $this->assertSame('Kunde_Acme', ImapKeyword::fromName('Kunde Acme'));
        $this->assertSame('Grusse', ImapKeyword::fromName('Grüße'));
        $this->assertSame('Rechnung_2026', ImapKeyword::fromName('Rechnung (2026)*'));
        $this->assertSame('$label1', ImapKeyword::fromName('Important'));
        $this->assertSame('Important', ImapKeyword::toName('$Label1'));
        $this->assertSame('Kunde Acme', ImapKeyword::toName('Kunde_Acme'));

        $this->assertTrue(ImapKeyword::isHidden('$Forwarded'));
        $this->assertTrue(ImapKeyword::isHidden('\Seen'));
        $this->assertFalse(ImapKeyword::isHidden('Kunde_Acme'));

        $this->assertTrue(ImapKeyword::isValid('Kunde_Acme'));
        $this->assertFalse(ImapKeyword::isValid('a b'));
        $this->assertFalse(ImapKeyword::isValid('evil)'));

        $this->expectException(InvalidArgumentException::class);
        ImapKeyword::fromName('()*');
    }

    public function test_labels_are_synchronised_from_keywords(): void
    {
        Event::fake([LabelsChanged::class]);

        $this->provider->keywords = ['Unused'];
        $this->provider->addMessage('INBOX', new MessageData('1', flags: new MessageFlags(keywords: ['$label1', 'Kunde_Acme', '$Forwarded'])));
        $this->provider->addMessage('Archive', new MessageData('1', flags: new MessageFlags(keywords: ['Kunde_Acme'])));

        $this->sync();

        $labels = $this->mailbox->labels()->get()->keyBy('remote_key');
        $this->assertEqualsCanonicalizing(['$label1', 'Kunde_Acme', 'Unused'], $labels->keys()->all());
        $this->assertSame('Important', $labels['$label1']->name);
        $this->assertSame('Kunde Acme', $labels['Kunde_Acme']->name);
        $this->assertSame(LabelSource::ImapKeyword, $labels['Kunde_Acme']->source);
        $this->assertSame(2, $labels['Kunde_Acme']->messages()->count());
        Event::assertDispatched(LabelsChanged::class);

        // Keyword removed on the server, catalogue entry gone.
        $this->provider->keywords = [];
        $this->provider->messages['INBOX'][1] = $this->provider->messages['INBOX'][1]->with(['flags' => new MessageFlags(keywords: ['Kunde_Acme'])]);

        $this->sync();

        $message = MailboxMessage::where('folder_id', $this->inbox->id)->sole();
        $this->assertSame(['Kunde_Acme'], $message->labels()->pluck('remote_key')->all());
        $this->assertNull(MailboxLabel::where('remote_key', 'Unused')->first());
        // No longer used anywhere.
        $this->assertNull(MailboxLabel::where('remote_key', '$label1')->first());
    }

    public function test_label_changes_are_applied_on_the_server_first(): void
    {
        [$message, $label] = $this->messageWithLabel();

        $this->service()->attach($message, $label);

        $this->assertSame(['Kunde_Acme'], $this->provider->messages['INBOX'][1]->flags->keywords);
        $this->assertTrue($message->labels()->whereKey($label->id)->exists());
        $this->assertSame(['Kunde_Acme'], $message->refresh()->keywords);
        $this->assertSame('changeLabels', $this->provider->calls[0][0]);

        $this->service()->detach($message, $label);

        $this->assertSame([], $this->provider->messages['INBOX'][1]->flags->keywords);
        $this->assertFalse($message->labels()->exists());
        $this->assertNull($message->refresh()->keywords);
    }

    public function test_remote_failure_keeps_local_labels(): void
    {
        $failing = new class extends FakeMailboxProvider
        {
            public function changeLabels(MessageIdentifier $message, array $add, array $remove): void
            {
                throw new RuntimeException('server gone');
            }
        };
        $this->app->instance(MailboxProviderFactory::class, new FakeMailboxProviderFactory($failing));
        [$message, $label] = $this->messageWithLabel();

        try {
            $this->service()->attach($message, $label);
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException) {
        }

        $this->assertFalse($message->labels()->exists());
        $this->assertTrue($failing->disconnected);
    }

    public function test_labels_can_be_created_renamed_and_deleted(): void
    {
        $label = $this->service()->create($this->mailbox, 'Kunde Acme', LabelColor::Green);

        $this->assertSame('Kunde_Acme', $label->remote_key);
        $this->assertSame(LabelColor::Green, $label->color);

        $this->service()->update($label, 'Acme GmbH', LabelColor::Red, hidden: true);
        $label->refresh();
        $this->assertSame('Kunde_Acme', $label->remote_key);
        $this->assertSame('Acme GmbH', $label->name);
        $this->assertTrue($label->is_hidden);

        $this->provider->addMessage('INBOX', new MessageData('1'));
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['remote_id' => '1:1']);
        $this->service()->attach($message, $label);

        $this->service()->delete($label);

        $this->assertModelMissing($label);
        $this->assertSame([], $this->provider->messages['INBOX'][1]->flags->keywords);
    }

    public function test_creating_labels_requires_server_support(): void
    {
        $this->provider->allowNewKeywords = false;

        $this->expectException(UnsupportedOperation::class);
        $this->service()->create($this->mailbox, 'New');
    }

    public function test_foreign_labels_are_rejected(): void
    {
        [$message] = $this->messageWithLabel();
        $foreign = MailboxLabel::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->service()->attach($message, $foreign);
    }

    public function test_label_navigation_shows_messages_across_folders(): void
    {
        $label = MailboxLabel::factory()->for($this->mailbox)->create(['name' => 'Acme']);
        $inInbox = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        $inArchive = MailboxMessage::factory()->for($this->archive, 'folder')->create();
        $unlabelled = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        $inInbox->labels()->attach($label);
        $inArchive->labels()->attach($label);

        Livewire::withQueryParams(['label' => $label->id])
            ->test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertOk()
            ->assertSee('Acme')
            ->assertCanSeeTableRecords([$inInbox, $inArchive])
            ->assertCanNotSeeTableRecords([$unlabelled])
            ->assertTableColumnVisible('folder.name');

        // A label of another mailbox is ignored.
        $foreign = MailboxLabel::factory()->create();

        Livewire::withQueryParams(['label' => $foreign->id])
            ->test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertSet('label', null)
            ->assertCanSeeTableRecords([$inInbox, $unlabelled])
            ->assertCanNotSeeTableRecords([$inArchive]);
    }

    public function test_messages_can_be_filtered_by_label(): void
    {
        $label = MailboxLabel::factory()->for($this->mailbox)->create();
        $labelled = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        $other = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        $labelled->labels()->attach($label);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->filterTable('labels', [$label->id])
            ->assertCanSeeTableRecords([$labelled])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_label_actions(): void
    {
        $label = MailboxLabel::factory()->for($this->mailbox)->create(['remote_key' => 'Acme', 'name' => 'Acme']);
        $messages = collect([1, 2])->map(function (int $uid): MailboxMessage {
            $this->provider->addMessage('INBOX', new MessageData((string) $uid));

            return MailboxMessage::factory()->for($this->inbox, 'folder')->create(['remote_id' => "1:{$uid}"]);
        });

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->selectTableRecords($messages->pluck('id')->all())
            ->callAction(TestAction::make('attachLabel')->table()->bulk(), data: ['label' => $label->id])
            ->assertNotified(__('filament-mailbox::mailbox.labels.actions.saved'));

        $this->assertSame(2, $label->messages()->count());

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction(TestAction::make('editLabels')->table($messages[0]), data: ['labels' => [], 'new_label' => 'Rechnung'])
            ->assertNotified(__('filament-mailbox::mailbox.labels.actions.saved'));

        $this->assertSame(['Rechnung'], $messages[0]->labels()->pluck('name')->all());
        $this->assertSame(['Rechnung'], $this->provider->messages['INBOX'][1]->flags->keywords);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $messages[1]->id])
            ->callAction('editLabels', data: ['labels' => []]);

        $this->assertFalse($messages[1]->labels()->exists());
    }

    public function test_label_ui_is_hidden_without_capability(): void
    {
        $this->provider->capabilities = [ProviderCapability::Folders, ProviderCapability::Flags];
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        MailboxLabel::factory()->for($this->mailbox)->create(['name' => 'Invisible']);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertActionHidden(TestAction::make('editLabels')->table($message))
            ->assertTableColumnHidden('labels')
            ->assertDontSee('Invisible');

        $this->assertFalse(LabelsRelationManager::canViewForRecord($this->mailbox, EditMailbox::class));
    }

    public function test_label_catalogue_can_be_managed(): void
    {
        Livewire::test(LabelsRelationManager::class, ['ownerRecord' => $this->mailbox, 'pageClass' => EditMailbox::class])
            ->callAction(TestAction::make('create')->table(), data: ['name' => 'Kunde Acme', 'color' => LabelColor::Blue->value])
            ->assertHasNoFormErrors()
            ->assertNotNotified(__('filament-mailbox::mailbox.actions.failed'));

        $label = MailboxLabel::sole();
        $this->assertSame('Kunde_Acme', $label->remote_key);
        $this->assertSame(LabelColor::Blue, $label->color);

        Livewire::test(LabelsRelationManager::class, ['ownerRecord' => $this->mailbox, 'pageClass' => EditMailbox::class])
            ->callAction(TestAction::make('delete')->table($label));

        $this->assertModelMissing($label);
    }

    /**
     * @return array{MailboxMessage, MailboxLabel}
     */
    protected function messageWithLabel(): array
    {
        $this->provider->addMessage('INBOX', new MessageData('1'));

        return [
            MailboxMessage::factory()->for($this->inbox, 'folder')->create(['remote_id' => '1:1']),
            MailboxLabel::factory()->for($this->mailbox)->create(['remote_key' => 'Kunde_Acme', 'name' => 'Kunde Acme']),
        ];
    }

    protected function sync(): void
    {
        $this->app->make(SyncService::class)->syncMailbox($this->mailbox);
    }

    protected function service(): LabelService
    {
        return $this->app->make(LabelService::class);
    }
}
