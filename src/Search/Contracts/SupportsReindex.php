<?php

namespace Cooolinho\FilamentMailbox\Search\Contracts;

/**
 * Engines that build a new index for a reindex and switch to it at the end.
 */
interface SupportsReindex
{
    /**
     * Start writing to a fresh index; every index() until finishReindex() goes there.
     */
    public function beginReindex(): void;

    public function finishReindex(): void;
}
