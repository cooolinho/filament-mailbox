<?php

namespace Cooolinho\FilamentMailbox\Support;

/**
 * What a compose form is used for; signatures and templates can be limited to contexts.
 */
enum ComposeContext: string
{
    case New = 'new';
    case Reply = 'reply';
    case Forward = 'forward';

    public function defaultColumn(): string
    {
        return 'is_default_'.$this->value;
    }

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.compose.contexts.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn (self $context): string => $context->getLabel(), self::cases()),
        );
    }
}
