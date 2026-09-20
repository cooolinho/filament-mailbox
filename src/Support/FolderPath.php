<?php

namespace Cooolinho\FilamentMailbox\Support;

use DirectoryTree\ImapEngine\Support\Str;
use InvalidArgumentException;

/**
 * Builds and validates hierarchical folder paths. Folder names entered by
 * users only reach the server through this class.
 */
class FolderPath
{
    public const MAX_LENGTH = 255;

    /**
     * Validation error key (filament-mailbox::mailbox.folders.errors.*) or null when valid.
     */
    public static function nameError(string $name, ?string $delimiter): ?string
    {
        $name = trim($name);

        return match (true) {
            $name === '' => 'name_required',
            mb_strlen($name) > self::MAX_LENGTH => 'name_too_long',
            preg_match('/[\x00-\x1F\x7F]/u', $name) === 1 => 'name_invalid',
            // Wildcards of IMAP LIST.
            str_contains($name, '*') || str_contains($name, '%') => 'name_invalid',
            filled($delimiter) && str_contains($name, $delimiter) => 'name_delimiter',
            default => null,
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertValidName(string $name, ?string $delimiter): string
    {
        if ($error = static::nameError($name, $delimiter)) {
            throw new InvalidArgumentException("Invalid folder name ({$error}).");
        }

        return trim($name);
    }

    /**
     * IMAP path of a folder: the parent path (already encoded), or the personal
     * namespace prefix for top-level folders, followed by the UTF-7 encoded name.
     */
    public static function imap(string $name, ?string $parentPath, string $delimiter, string $prefix = ''): string
    {
        $encoded = Str::toImapUtf7(static::assertValidName($name, $delimiter));

        return $parentPath !== null ? $parentPath.$delimiter.$encoded : $prefix.$encoded;
    }

    public static function decode(string $path): string
    {
        return Str::fromImapUtf7($path);
    }

    /**
     * Personal namespace prefix derived from the existing folders, e.g. "INBOX."
     * on servers that keep all folders below the inbox (Courier, some Dovecot setups).
     *
     * @param  array<int, string>  $paths
     */
    public static function namespacePrefix(array $paths, string $delimiter): string
    {
        $prefix = 'INBOX'.$delimiter;
        $others = array_values(array_filter($paths, fn (string $path): bool => strcasecmp($path, 'INBOX') !== 0));

        if ($others === [] || $delimiter === '') {
            return '';
        }

        foreach ($others as $path) {
            if (stripos($path, $prefix) !== 0) {
                return '';
            }
        }

        return substr($others[0], 0, strlen($prefix));
    }

    /**
     * Replace the path prefix of a folder or one of its descendants.
     */
    public static function replacePrefix(string $path, string $old, string $new, string $delimiter): string
    {
        if ($path === $old) {
            return $new;
        }

        if ($delimiter !== '' && str_starts_with($path, $old.$delimiter)) {
            return $new.substr($path, strlen($old));
        }

        return $path;
    }

    /**
     * Whether a path is the given folder or lies below it.
     */
    public static function isWithin(string $path, string $folder, ?string $delimiter): bool
    {
        return $path === $folder || (filled($delimiter) && str_starts_with($path, $folder.$delimiter));
    }
}
