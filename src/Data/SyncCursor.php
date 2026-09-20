<?php

namespace Cooolinho\FilamentMailbox\Data;

/**
 * Opaque, provider-specific synchronisation state of a folder.
 *
 * IMAP: ['uid_validity' => 123, 'last_uid' => 456], Graph: ['delta_link' => '…'],
 * Gmail: ['history_id' => '98765'].
 */
final readonly class SyncCursor
{
    /**
     * @param  array<string, scalar|null>  $state
     */
    public function __construct(
        public array $state = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->state[$key] ?? $default;
    }

    /**
     * @param  array<string, scalar|null>  $state
     */
    public function with(array $state): self
    {
        return new self([...$this->state, ...$state]);
    }

    public function isEmpty(): bool
    {
        return $this->state === [];
    }
}
