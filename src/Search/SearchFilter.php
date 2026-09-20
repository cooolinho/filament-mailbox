<?php

namespace Cooolinho\FilamentMailbox\Search;

/**
 * An operator like "from:jane" or "is:unread".
 */
final readonly class SearchFilter
{
    public const FROM = 'from';

    public const TO = 'to';

    public const CC = 'cc';

    public const SUBJECT = 'subject';

    public const HAS_ATTACHMENT = 'has_attachment';

    public const FILENAME = 'filename';

    public const IS_READ = 'is_read';

    public const IS_FLAGGED = 'is_flagged';

    public const IN_FOLDER = 'in';

    public const BEFORE = 'before';

    public const AFTER = 'after';

    /**
     * @param  string|bool|\Carbon\CarbonImmutable  $value
     */
    public function __construct(
        public string $name,
        public mixed $value,
        public bool $negated = false,
    ) {}
}
