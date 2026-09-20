<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class MessageSearchTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxMessage $invoice;

    protected MailboxMessage $application;

    protected MailboxMessage $newsletter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();

        $this->invoice = MailboxMessage::factory()->for($inbox, 'folder')->create([
            'from_name' => 'Jane Doe',
            'from_address' => 'billing@acme.test',
            'subject' => 'Rechnung September',
            'to' => [['address' => 'office@example.com', 'name' => 'Office']],
            'text_body' => 'Bitte bis Freitag überweisen.',
        ]);
        $this->application = MailboxMessage::factory()->for($inbox, 'folder')->create([
            'from_name' => 'Max Mustermann',
            'from_address' => 'max@mustermann.test',
            'subject' => 'Bewerbung',
            'to' => [['address' => 'jobs@example.com', 'name' => null]],
            'cc' => [['address' => 'hr@example.com', 'name' => 'HR']],
            'text_body' => 'Anbei meine Unterlagen.',
        ]);
        $this->newsletter = MailboxMessage::factory()->for($inbox, 'folder')->create([
            'from_name' => 'News 100%',
            'from_address' => 'news@letter.test',
            'subject' => 'Weekly_update',
            'to' => [['address' => 'office@example.com', 'name' => null]],
        ]);
    }

    public function test_search_by_sender_name_and_address(): void
    {
        $this->search('jane')->assertCanSeeTableRecords([$this->invoice])->assertCanNotSeeTableRecords([$this->application, $this->newsletter]);
        $this->search('MUSTERMANN.TEST')->assertCanSeeTableRecords([$this->application])->assertCanNotSeeTableRecords([$this->invoice]);
    }

    public function test_search_by_subject(): void
    {
        $this->search('rechnung')->assertCanSeeTableRecords([$this->invoice])->assertCanNotSeeTableRecords([$this->application]);
    }

    public function test_search_by_recipient(): void
    {
        $this->search('jobs@example.com')->assertCanSeeTableRecords([$this->application])->assertCanNotSeeTableRecords([$this->invoice]);
        $this->search('hr@example')->assertCanSeeTableRecords([$this->application])->assertCanNotSeeTableRecords([$this->newsletter]);
        $this->search('office@')->assertCanSeeTableRecords([$this->invoice, $this->newsletter])->assertCanNotSeeTableRecords([$this->application]);
    }

    public function test_all_terms_must_match(): void
    {
        $this->search('office rechnung')->assertCanSeeTableRecords([$this->invoice])->assertCanNotSeeTableRecords([$this->newsletter]);
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $this->search('%')->assertCanSeeTableRecords([$this->newsletter])->assertCanNotSeeTableRecords([$this->invoice, $this->application]);
        $this->search('y_u')->assertCanSeeTableRecords([$this->newsletter])->assertCanNotSeeTableRecords([$this->invoice]);
    }

    public function test_body_search_is_optional(): void
    {
        $this->search('unterlagen')->assertCanNotSeeTableRecords([$this->application]);

        config(['filament-mailbox.search.include_body' => true]);

        $this->search('unterlagen')->assertCanSeeTableRecords([$this->application])->assertCanNotSeeTableRecords([$this->invoice]);
    }

    public function test_search_is_limited_to_the_current_folder(): void
    {
        $other = MailboxMessage::factory()->for(MailboxFolder::factory()->for($this->mailbox)->create(), 'folder')->create(['subject' => 'Rechnung Oktober']);

        $this->search('rechnung')->assertCanSeeTableRecords([$this->invoice])->assertCanNotSeeTableRecords([$other]);
    }

    protected function search(string $term): Testable
    {
        return Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])->searchTable($term);
    }
}
