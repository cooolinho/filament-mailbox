<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Search;

use Carbon\CarbonImmutable;
use Cooolinho\FilamentMailbox\Search\QueryParser;
use Cooolinho\FilamentMailbox\Search\SearchFilter;
use Cooolinho\FilamentMailbox\Search\SearchTerm;
use Cooolinho\FilamentMailbox\Tests\TestCase;

class QueryParserTest extends TestCase
{
    public function test_words_phrases_exclusion_and_or(): void
    {
        $query = (new QueryParser)->parse('invoice "due date" -newsletter march OR april');

        $this->assertEquals([[new SearchTerm('invoice')], [new SearchTerm('due date', true)], [new SearchTerm('march'), new SearchTerm('april')]], $query->clauses);
        $this->assertEquals([new SearchTerm('newsletter')], $query->excluded);
        $this->assertSame([], $query->filters);
    }

    public function test_operators(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'));

        $query = (new QueryParser)->parse('from:"Jane Doe" to:office cc:hr subject:offer has:attachment filename:pdf is:unread is:starred -in:spam after:2026-09-01 before:01.10.2026 older_than:2w', 'Europe/Berlin');
        $filters = collect($query->filters)->map(fn (SearchFilter $filter): array => [$filter->name, $filter->value instanceof CarbonImmutable ? $filter->value->utc()->toDateTimeString() : $filter->value, $filter->negated])->all();

        $this->assertSame([
            ['from', 'Jane Doe', false],
            ['to', 'office', false],
            ['cc', 'hr', false],
            ['subject', 'offer', false],
            ['has_attachment', true, false],
            ['filename', 'pdf', false],
            ['is_read', false, false],
            ['is_flagged', true, false],
            ['in', 'spam', true],
            ['after', '2026-08-31 22:00:00', false],
            ['before', '2026-09-30 22:00:00', false],
            ['before', '2026-09-08 12:00:00', false],
        ], $filters);
        $this->assertSame([], $query->clauses);
    }

    public function test_unknown_operators_and_invalid_values_are_text(): void
    {
        $query = (new QueryParser)->parse('label:work is:important after:yesterday');

        $this->assertEquals([[new SearchTerm('label:work')], [new SearchTerm('is:important')], [new SearchTerm('after:yesterday')]], $query->clauses);
        $this->assertSame([], $query->filters);
    }

    public function test_input_is_limited(): void
    {
        $query = (new QueryParser)->parse(str_repeat('word ', 200));

        $this->assertCount(QueryParser::MAX_TOKENS, $query->clauses);
        $this->assertTrue((new QueryParser)->parse('   ')->isEmpty());
        $this->assertTrue((new QueryParser)->parse('OR')->isEmpty());
    }
}
