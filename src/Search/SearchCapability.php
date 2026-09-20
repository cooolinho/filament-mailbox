<?php

namespace Cooolinho\FilamentMailbox\Search;

/**
 * What a search engine can do itself. Everything else is applied afterwards
 * in the database on the hits of the engine (see SearchManager).
 */
enum SearchCapability: string
{
    /** from:, to:, cc:, subject:, filename: */
    case FieldOperators = 'field_operators';

    /** has:attachment, is:read, is:starred */
    case FlagFilters = 'flag_filters';

    case FolderFilter = 'folder_filter';

    case DateRange = 'date_range';

    case Phrases = 'phrases';

    case Negation = 'negation';

    case RelevanceSort = 'relevance_sort';

    /**
     * Engines in the application database can do everything.
     *
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Conservative default for engines that do not declare capabilities: free text only.
     *
     * @return array<int, self>
     */
    public static function freeTextOnly(): array
    {
        return [];
    }
}
