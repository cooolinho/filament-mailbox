<?php

namespace Cooolinho\FilamentMailbox\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Converts between label names and IMAP keywords (RFC 9051 flag-keyword atoms).
 */
final class ImapKeyword
{
    /**
     * Build a keyword from a display name. Only a whitelist of ASCII
     * characters is kept; user input never reaches IMAP commands unfiltered.
     */
    public static function fromName(string $name): string
    {
        $defaults = array_flip(array_map('mb_strtolower', static::thunderbirdDefaults()));

        if (isset($defaults[mb_strtolower(trim($name))])) {
            return (string) $defaults[mb_strtolower(trim($name))];
        }

        $keyword = Str::ascii(trim($name));
        $keyword = (string) preg_replace('/\s+/', '_', $keyword);
        $keyword = (string) preg_replace('/[^A-Za-z0-9_\-.+:$&#!@~^]/', '', $keyword);
        $keyword = ltrim($keyword, '$') === '' ? '' : $keyword;
        $keyword = substr($keyword, 0, 64);

        if ($keyword === '' || static::isHidden($keyword)) {
            throw new InvalidArgumentException("\"{$name}\" cannot be used as a label name.");
        }

        return $keyword;
    }

    public static function toName(string $keyword): string
    {
        foreach (static::thunderbirdDefaults() as $key => $name) {
            if (strcasecmp($key, $keyword) === 0) {
                return $name;
            }
        }

        return str_replace('_', ' ', $keyword);
    }

    /**
     * System flags and well-known technical keywords are not labels.
     */
    public static function isHidden(string $keyword): bool
    {
        if ($keyword === '' || str_starts_with($keyword, '\\')) {
            return true;
        }

        $hidden = array_map('strtolower', (array) config('filament-mailbox.labels.hidden_keywords', []));

        return in_array(strtolower($keyword), $hidden, true);
    }

    public static function isValid(string $keyword): bool
    {
        return (bool) preg_match('/^[\x21-\x7E]+$/', $keyword) && ! preg_match('/[(){%*"\\\\\]]/', $keyword);
    }

    /**
     * @return array<string, string>
     */
    protected static function thunderbirdDefaults(): array
    {
        return (array) config('filament-mailbox.labels.thunderbird_defaults', []);
    }
}
