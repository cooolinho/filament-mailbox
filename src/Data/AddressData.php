<?php

namespace Cooolinho\FilamentMailbox\Data;

final readonly class AddressData
{
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {}

    /**
     * @return array{address: string, name: ?string}
     */
    public function toArray(): array
    {
        return ['address' => $this->address, 'name' => $this->name];
    }
}
