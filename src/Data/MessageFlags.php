<?php

namespace Cooolinho\FilamentMailbox\Data;

final readonly class MessageFlags
{
    /**
     * @param  array<int, string>  $keywords
     */
    public function __construct(
        public bool $seen = false,
        public bool $flagged = false,
        public bool $answered = false,
        public bool $draft = false,
        public array $keywords = [],
    ) {}

    /**
     * @param  array<int, string>  $flags  Raw IMAP flags, e.g. ["\Seen", "$label1"]
     */
    public static function fromImap(array $flags): self
    {
        $system = array_map('strtolower', array_filter($flags, fn (string $flag): bool => str_starts_with($flag, '\\')));

        return new self(
            seen: in_array('\seen', $system, true),
            flagged: in_array('\flagged', $system, true),
            answered: in_array('\answered', $system, true),
            draft: in_array('\draft', $system, true),
            keywords: array_values(array_filter($flags, fn (string $flag): bool => ! str_starts_with($flag, '\\'))),
        );
    }

    /**
     * @return array<int, string>
     */
    public function toImap(): array
    {
        return [
            ...array_keys(array_filter([
                '\Seen' => $this->seen,
                '\Flagged' => $this->flagged,
                '\Answered' => $this->answered,
                '\Draft' => $this->draft,
            ])),
            ...$this->keywords,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->toImap() === [];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * Apply the given additions and removals.
     */
    public function apply(MessageFlags $add, MessageFlags $remove): self
    {
        return new self(
            seen: ($this->seen || $add->seen) && ! $remove->seen,
            flagged: ($this->flagged || $add->flagged) && ! $remove->flagged,
            answered: ($this->answered || $add->answered) && ! $remove->answered,
            draft: ($this->draft || $add->draft) && ! $remove->draft,
            keywords: array_values(array_diff(array_unique([...$this->keywords, ...$add->keywords]), $remove->keywords)),
        );
    }
}
