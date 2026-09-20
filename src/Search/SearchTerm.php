<?php

namespace Cooolinho\FilamentMailbox\Search;

/**
 * A free-text word or "exact phrase".
 */
final readonly class SearchTerm
{
    public function __construct(
        public string $text,
        public bool $phrase = false,
    ) {}

    /**
     * Letters and digits of the term, lower-case (e.g. an address becomes its parts).
     *
     * @return array<int, string>
     */
    public function tokens(): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($this->text)) ?: [], fn (string $token): bool => $token !== ''));
    }
}
