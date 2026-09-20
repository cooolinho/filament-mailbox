# Search

The search field of the mailbox understands a Gmail-like syntax and runs through a configurable search engine
(`search.engine`).

## Syntax

| Input | Finds |
|---|---|
| `invoice march` | Messages containing all words (sender, recipients, subject, text, attachment names and texts) |
| `"exact phrase"` | The words in this order |
| `-newsletter` | Messages without the word |
| `invoice OR receipt` | One of the words |
| `from:jane@example.com`, `to:support`, `cc:team` | Sender, recipients (name or address, substring) |
| `subject:offer` | Subject |
| `has:attachment`, `filename:pdf` | With attachment, attachment file name |
| `is:read`, `is:unread`, `is:starred`, `is:unstarred` | Flags |
| `in:inbox`, `in:sent`, `in:spam`, `in:Projects` | Folder: special-use role or exact folder name, within the mailbox |
| `after:2026-09-01`, `before:01.10.2026` | Received on/after or before the day, in the panel time zone |
| `older_than:30d`, `newer_than:2w` | Relative periods in days, weeks, months or years |

Operators can be negated (`-in:sent`, `-from:noreply`). Unknown operators and invalid values are searched as text.
Inputs are limited to 500 characters and 20 parts. *Search operators* above the table lists the syntax.

- **Folder or mailbox** — the search runs in the current folder; *Search all folders* extends it to the whole
  mailbox (URL parameter `all=1`, with a *Folder* column).
- **Match** — while searching, a column shows an excerpt of the text around the first hit with `<mark>` highlights
  (escaped; `search.snippets`).

## Engines

| Engine | How it works |
|---|---|
| `like` (default) | Substring search on `mailbox_messages` (sender, recipients, subject; body with `search.include_body`). No index, slow on large mailboxes (leading `%`) |
| `meilisearch` | Typo-tolerant search in a Meilisearch instance, see below |
| `scout` | Any Laravel Scout engine of the application, see below |
| `database` | Full-text index in `mailbox_search_documents`: MySQL/MariaDB `FULLTEXT` (boolean mode, prefix `word*`, relevance), PostgreSQL generated `tsvector` with GIN index (`search.postgres_language`, prefix `word:*`, `ts_rank_cd`), SQLite FTS5 (`unicode61 remove_diacritics 2`, `bm25`); LIKE on other databases |
| Class name | Own engines implement `Search\Contracts\MessageSearchEngine` (register names in `search.engines`) |

The message tables use `Search\Contracts\ConstrainsQueries` when an engine offers it (sub-query, all hits paginated,
sorted by date); other engines return at most `search.max_results` ids. `MessageSearchEngine::search()` returns
ids ordered by relevance with the total.

### Database engine

- One document per message: normalised lower-case sender, *to*, *cc*, subject, body (text or HTML converted to
  text, up to `search.max_body_length` characters), attachment names and extracted attachment texts, plus mailbox,
  folder, flags and date for the operators.
- The index follows every change: import, flags, moves, edits, soft deletion and restore (model events; errors are
  reported, never break mail operations). Flag and folder changes only update those columns.
- After switching to `database` (or for existing data) run `php artisan mailbox:search-reindex` (queued in batches;
  `--now`, `{mailbox*}`, `--chunk`).
- MySQL: tokens shorter than `innodb_ft_min_token_size` (`search.mysql_min_token_size`, default 3) and stop words
  are not indexed; such terms fall back to LIKE. There is no stemming; PostgreSQL gives better results for German.
- The documents contain mail contents — treat them like `mailbox_messages` (backups, deletion).
- Only letters and digits of the input reach `MATCH … AGAINST`, `to_tsquery` or FTS5 `MATCH`, always as bindings.

### Meilisearch

`MAILBOX_SEARCH_ENGINE=meilisearch` searches in a [Meilisearch](https://www.meilisearch.com) instance: typo
tolerance, prefix matching ("as you type") and relevance ranking. The package talks to the HTTP API directly, so it
needs no extra dependency.

```bash
# Sandbox: docker compose --profile search up -d sandbox-meilisearch
MAILBOX_SEARCH_ENGINE=meilisearch
MAILBOX_MEILISEARCH_HOST=http://sandbox-meilisearch:7700
MAILBOX_MEILISEARCH_KEY=...        # API key limited to the index, never the master key
php artisan mailbox:search-reindex --now --swap
```

- **Index** — `filament_mailbox_messages` (`search.meilisearch.index`) with searchable attributes in weight order
  (subject, sender, recipients, attachment names, body, attachment texts), filterable attributes for every operator,
  `received_at_ts` for sorting and `@`/`.` as separator tokens, so address parts are searchable. Typo tolerance is
  **disabled** for sender, recipients and attachment names, so addresses match exactly.
- **Operators** — Meilisearch searches all attributes at once, so field operators (`from:`, `to:`, `cc:`,
  `subject:`, `filename:`) are filters on token arrays of the document: every **whole word** of the value must
  occur (`from:jane` matches `Jane Doe`, `from:jan` does not). The other operators are ordinary filters;
  `mailbox_id` is always required, and folders of `in:` are resolved within the mailbox.
- **Indexing** runs through the queue (`SyncMeilisearchDocuments`) and is asynchronous in Meilisearch itself: task
  ids are stored in `mailbox_search_tasks`; schedule `mailbox:search-tasks-check` every minute — it repeats failed
  batches up to three times.
- **Reindex without downtime** — `mailbox:search-reindex --now --swap` fills a temporary index and swaps it
  (`/swap-indexes`).
- **Fallback** — while the instance cannot be reached, searching falls back to `search.meilisearch.fallback_engine`
  (`database` by default, `""` to fail instead). The fallback index is written along with Meilisearch, so it stays
  usable; the health check reports the instance as failing even when the fallback answers.
- **Match column** — snippets are built in the app from the stored text (escaped, `<mark>`), not from Meilisearch
  highlights.
- **Security** — use a key limited to the index, never the master key, do not expose the instance publicly (TLS via a
  reverse proxy) and remember that the index contains mail contents. Filter values are typed or escaped
  (`MeilisearchFilterBuilder::escape()`).
- Not included: facet UI, federated multi-search, front-end search with tenant tokens, vector search.

### Other engines (Laravel Scout, own engines)

Engines that the package does not ship itself are registered by name — in the configuration or from a plugin:

```php
// config/filament-mailbox.php
'search' => ['engine' => 'typesense', 'engines' => ['typesense' => \Vendor\Package\TypesenseSearchEngine::class]],

// or in the panel provider
FilamentMailboxPlugin::make()->searchEngine('typesense', fn ($app) => $app->make(TypesenseSearchEngine::class));
```

An engine implements `Search\Contracts\MessageSearchEngine` (`search()`, `index()`, `remove()`) and may implement
`Search\Contracts\DeclaresSearchCapabilities` (`SearchCapability`: field operators, flag filters, folder filter,
date range, phrases, negation, relevance sort) and `Search\Contracts\ConstrainsQueries` / `SupportsReindex`.

**Capabilities and post-filter:** what an engine does not declare is applied afterwards in the database on its hits
(at most `search.candidate_limit`, 2000 by default). The scope is always enforced again, so ids of a foreign
mailbox are dropped. If the limit is reached, a hint below the list asks to refine the search.

**Testing your engine:** the trait `Cooolinho\FilamentMailbox\Testing\MessageSearchEngineTests` ships the contract
suite (words, OR, prefixes, phrases, exclusion, body and attachments, all operators, scope, changes and deletions):

```php
class MyEngineTest extends TestCase   // your package's test case
{
    use MessageSearchEngineTests;

    protected function engine(): MessageSearchEngine
    {
        return app(MyEngine::class);   // configured as filament-mailbox.search.engine
    }
}
```

#### Laravel Scout

`MAILBOX_SEARCH_ENGINE=scout` searches through the Scout engine of the application (`config/scout.php`:
Typesense, Algolia, Meilisearch, database, collection). Install `laravel/scout` and reindex with
`php artisan mailbox:search-reindex`.

- The package uses its own model `SearchableMailboxMessage` (index `search.scout.index`), so the application keeps
  control over its models; the index is written by the package, never through Scout model events.
- `search.scout.adapter` chooses engine-specific filters: `typesense` (mailbox, folders, flags and dates as
  `filter_by`), a class implementing `Search\Scout\ScoutAdapter`, or the default (mailbox and folder via Scout
  `where`). Everything else — field operators, phrases, exclusion — is applied by the database post-filter.
- `search.scout.fields` limits the indexed fields (data minimisation), e.g. `['subject', 'from']` without the body.
- **Cloud engines** (`algolia`, `typesense`, `meilisearch` through Scout) receive the message contents. The engine
  refuses to start until `search.scout.external_processing_acknowledged` (`MAILBOX_SEARCH_EXTERNAL_ACK=true`) is
  set — clarify data processing (GDPR Art. 28), transfers, deletion periods and encryption first.

### Attachment texts

With `search.attachments.extract_text` every new attachment is processed by `ExtractAttachmentTextJob` (queue
`search.queue`): plain text/CSV/HTML, PDF with `pdftotext` (poppler-utils, `search.attachments.pdftotext_binary`,
started without a shell and with `search.attachments.timeout`), DOCX and ODT (main XML part of the ZIP container,
size-limited). Files above `search.attachments.max_size` KB are skipped; the text is limited to
`search.attachments.max_text_length`. Results and errors are stored in `mailbox_attachments.extracted_text`,
`extracted_at` and `extraction_error`. `mailbox:search-extract-attachments` processes existing attachments
(`--mailbox=*`, `--force`, `--now`). No OCR.

## Tests

`tests/Contracts/MessageSearchEngineContractTest` is the behaviour every engine must pass (words, OR, prefixes,
phrases, exclusion, body and attachments, all operators, scope, changes and deletions, totals).
`tests/Feature/Search` runs it for the `like` and the `database` engine (SQLite FTS5 in CI). On MySQL the database
engine tests run without transactions, because InnoDB updates `FULLTEXT` indexes on commit only:

```bash
DB_CONNECTION=mysql DB_HOST=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... vendor/bin/phpunit tests/Feature/Search
```

The PostgreSQL grammar is not covered by CI.

`tests/Feature/Search/ExternalEnginesTest` covers the registry, capabilities, the post-filter and the guaranteed
scope, `tests/Feature/Search/ScoutBridgeTest` the Scout bridge with the `collection` driver.
`tests/Feature/Search/MeilisearchEngineTest` covers the Meilisearch engine against a faked HTTP API (query
translation, filter escaping, documents, task handling, fallback, swap reindex).
`tests/Integration/MeilisearchIntegrationTest` runs against a real instance and is skipped when it is not
reachable (`MAILBOX_TEST_MEILISEARCH_HOST`, `MAILBOX_TEST_MEILISEARCH_KEY`):

```bash
docker compose --profile search up -d sandbox-meilisearch
vendor/bin/phpunit --testsuite Integration
```
