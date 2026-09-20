<?php

namespace Cooolinho\FilamentMailbox\Search\Contracts;

use Cooolinho\FilamentMailbox\Search\SearchCapability;

/**
 * Engines say what they support themselves; the rest is filtered in the
 * database afterwards. Engines without this interface are treated as
 * free-text search.
 */
interface DeclaresSearchCapabilities
{
    /**
     * @return array<int, SearchCapability>
     */
    public function capabilities(): array;
}
