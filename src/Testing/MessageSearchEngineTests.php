<?php

namespace Cooolinho\FilamentMailbox\Testing;

use Carbon\CarbonImmutable;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\Contracts\ConstrainsQueries;
use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\QueryParser;
use Cooolinho\FilamentMailbox\Search\SearchScope;

/**
 * The behaviour every message search engine must fulfil, shipped for engines
 * of other packages: use this trait in a test case of your package (Laravel
 * application or Orchestra Testbench) and return the engine from engine().
 * The engine has to be configured as "filament-mailbox.search.engine", so its
 * index is written while the fixtures are created.
 */
trait MessageSearchEngineTests
{
    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $sent;

    protected MailboxMessage $invoice;

    protected MailboxMessage $application;

    protected MailboxMessage $newsletter;

    protected MailboxMessage $reply;

    protected MailboxMessage $foreign;

    abstract protected function engine(): MessageSearchEngine;

    /** Whether the engine searches the message body. */
    protected bool $searchesBody = true;

    /** Whether the engine searches extracted attachment texts. */
    protected bool $searchesAttachmentText = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'));

        $this->mailbox = Mailbox::factory()->create();
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->sent = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Sent)->create(['name' => 'Sent', 'full_name' => 'Sent']);

        $this->invoice = MailboxMessage::factory()->for($this->inbox, 'folder')->create([
            'from_name' => 'Jane Doe', 'from_address' => 'billing@acme.test', 'subject' => 'Rechnung September',
            'to' => [['address' => 'office@example.com', 'name' => 'Office']], 'cc' => [],
            'text_body' => 'Bitte bis Freitag überweisen.', 'has_attachments' => true, 'is_read' => false,
            'received_at' => now()->subDays(40),
        ]);
        MailboxAttachment::factory()->for($this->invoice, 'message')->create(['filename' => 'rechnung-4711.pdf', 'extracted_text' => 'Gesamtbetrag 1.234,56 Euro']);
        $this->invoice->refresh()->touch();

        $this->application = MailboxMessage::factory()->for($this->inbox, 'folder')->create([
            'from_name' => 'Max Mustermann', 'from_address' => 'max@mustermann.test', 'subject' => 'Bewerbung als Entwickler',
            'to' => [['address' => 'jobs@example.com', 'name' => null]], 'cc' => [['address' => 'hr@example.com', 'name' => 'HR']],
            'text_body' => 'Anbei meine Unterlagen und das Anschreiben.', 'is_read' => true, 'is_flagged' => true,
            'received_at' => now()->subDays(2),
        ]);
        $this->newsletter = MailboxMessage::factory()->for($this->inbox, 'folder')->create([
            'from_name' => 'News', 'from_address' => 'news@letter.test', 'subject' => 'Weekly update',
            'to' => [['address' => 'office@example.com', 'name' => null]], 'cc' => [],
            'text_body' => null, 'html_body' => '<p>The <b>weekly</b> product update for developers</p>', 'is_read' => true,
            'received_at' => now()->subDay(),
        ]);
        $this->reply = MailboxMessage::factory()->for($this->sent, 'folder')->create([
            'from_name' => 'Office', 'from_address' => 'office@example.com', 'subject' => 'Re: Bewerbung als Entwickler',
            'to' => [['address' => 'max@mustermann.test', 'name' => 'Max Mustermann']], 'cc' => [],
            'text_body' => 'Vielen Dank für Ihre Bewerbung.', 'is_read' => true,
            'received_at' => now()->subHour(),
        ]);
        $this->foreign = MailboxMessage::factory()->create(['subject' => 'Bewerbung Rechnung Weekly', 'from_name' => 'Jane Doe', 'text_body' => 'Bewerbung']);
    }

    public function test_words_match_sender_recipient_and_subject(): void
    {
        $this->assertHits('jane', [$this->invoice]);
        $this->assertHits('mustermann.test', [$this->application, $this->reply]);
        $this->assertHits('rechnung', [$this->invoice]);
        $this->assertHits('hr@example.com', [$this->application]);
    }

    public function test_all_words_must_match_and_or_combines(): void
    {
        $this->assertHits('bewerbung entwickler', [$this->application, $this->reply]);
        $this->assertHits('rechnung weekly', []);
        $this->assertHits('rechnung OR weekly', [$this->invoice, $this->newsletter]);
    }

    public function test_prefixes_phrases_and_exclusion(): void
    {
        $this->assertHits('bewerb', [$this->application, $this->reply]);
        $this->assertHits('"als entwickler"', [$this->application, $this->reply]);
        $this->assertHits('"entwickler als"', []);
        $this->assertHits('bewerbung -"re:"', [$this->application]);
    }

    public function test_body_and_attachments(): void
    {
        if (! $this->searchesBody) {
            $this->markTestSkipped('The engine does not search the body.');
        }

        $this->assertHits('unterlagen', [$this->application]);
        $this->assertHits('developers', [$this->newsletter]);
        $this->assertHits('überweisen', [$this->invoice]);

        if ($this->searchesAttachmentText) {
            $this->assertHits('gesamtbetrag', [$this->invoice]);
        }
    }

    public function test_operators(): void
    {
        $this->assertHits('from:jane', [$this->invoice]);
        $this->assertHits('to:jobs@example.com', [$this->application]);
        $this->assertHits('cc:hr', [$this->application]);
        $this->assertHits('subject:bewerbung', [$this->application, $this->reply]);
        $this->assertHits('has:attachment', [$this->invoice]);
        $this->assertHits('filename:4711', [$this->invoice]);
        $this->assertHits('is:unread', [$this->invoice]);
        $this->assertHits('is:starred', [$this->application]);
        $this->assertHits('in:sent', [$this->reply]);
        $this->assertHits('-in:sent bewerbung', [$this->application]);
        $this->assertHits('in:unknown', []);
        $this->assertHits('after:2026-09-21', [$this->newsletter, $this->reply]);
        $this->assertHits('before:2026-09-01', [$this->invoice]);
        $this->assertHits('older_than:30d', [$this->invoice]);
        $this->assertHits('newer_than:3d is:read -in:sent', [$this->application, $this->newsletter]);
    }

    public function test_scope_is_always_enforced(): void
    {
        $this->assertHits('bewerbung', [$this->application], new SearchScope($this->mailbox->id, $this->inbox->id));
        $this->assertHits('rechnung', [], new SearchScope($this->mailbox->id, $this->sent->id));
        $this->assertHits('bewerbung', [$this->foreign], new SearchScope($this->foreign->mailbox_id));
    }

    public function test_results_follow_changes_and_deletions(): void
    {
        $this->application->forceFill(['is_read' => false])->save();
        $this->assertHits('is:unread', [$this->invoice, $this->application]);

        $this->application->forceFill(['folder_id' => $this->sent->id])->save();
        $this->assertHits('in:sent', [$this->reply, $this->application]);

        $this->application->forceFill(['subject' => 'Umzug'])->save();
        $this->assertHits('subject:bewerbung', [$this->reply]);

        $this->reply->delete();
        $this->assertHits('bewerbung', []);

        $this->reply->restore();
        $this->assertHits('bewerbung', [$this->reply]);
    }

    public function test_search_returns_ids_and_totals(): void
    {
        $result = $this->engine()->search(app(QueryParser::class)->parse('office'), new SearchScope($this->mailbox->id), 2);

        $this->assertCount(2, $result->ids);
        $this->assertSame(3, $result->total);
    }

    /**
     * @param  array<int, MailboxMessage>  $expected
     */
    protected function assertHits(string $search, array $expected, ?SearchScope $scope = null): void
    {
        $scope ??= new SearchScope($this->mailbox->id);
        $query = app(QueryParser::class)->parse($search, 'UTC');
        $engine = $this->engine();
        $expectedIds = array_map(fn (MailboxMessage $message): int => $message->id, $expected);

        $ids = $engine->search($query, $scope, 100)->ids;
        sort($ids);
        sort($expectedIds);
        $this->assertSame($expectedIds, $ids, "search() for [{$search}]");

        if ($engine instanceof ConstrainsQueries) {
            $ids = $engine->constrain(MailboxMessage::query(), $query, $scope)->pluck('mailbox_messages.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $this->assertSame($expectedIds, $ids, "constrain() for [{$search}]");
        }
    }
}
