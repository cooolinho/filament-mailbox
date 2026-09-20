<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Search\SearchDocumentMapper;
use Laravel\Scout\Searchable;

/**
 * The message model for Laravel Scout. A separate model, so the application
 * keeps full control over its own models and Scout observers: the package
 * writes the index itself (SearchIndexObserver), never through Scout events.
 */
class SearchableMailboxMessage extends MailboxMessage
{
    use Searchable;

    public function searchableAs(): string
    {
        return (string) config('filament-mailbox.search.scout.index', 'filament_mailbox_messages');
    }

    public function getScoutKey(): mixed
    {
        return $this->getKey();
    }

    public function getScoutKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $document = app(SearchDocumentMapper::class)->map($this);
        $fields = (array) config('filament-mailbox.search.scout.fields', []);

        // Data minimisation: only the configured fields leave the application.
        return $fields === [] ? $document : array_intersect_key($document, array_flip([...$fields, 'message_id', 'mailbox_id', 'folder_id']));
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }
}
