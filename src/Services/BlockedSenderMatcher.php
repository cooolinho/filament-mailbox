<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;

/**
 * Matches sender addresses against the blocked senders of a mailbox:
 * exact addresses or whole domains ("*@example.com"), case-insensitive.
 * Subdomains are not matched implicitly and patterns are never regular expressions.
 */
class BlockedSenderMatcher
{
    public const DOMAIN_PREFIX = '*@';

    public static function normalize(string $pattern): string
    {
        return mb_strtolower(trim($pattern));
    }

    /**
     * An e-mail address or "*@" followed by a domain.
     */
    public static function isValid(string $pattern): bool
    {
        $pattern = static::normalize($pattern);

        if (str_starts_with($pattern, self::DOMAIN_PREFIX)) {
            return filter_var('user@'.substr($pattern, 2), FILTER_VALIDATE_EMAIL) !== false;
        }

        return filter_var($pattern, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function domainPattern(string $address): string
    {
        return self::DOMAIN_PREFIX.static::domain($address);
    }

    /**
     * @param  iterable<string>  $patterns
     */
    public function matches(?string $address, iterable $patterns): bool
    {
        if (blank($address)) {
            return false;
        }

        $address = static::normalize($address);
        $domain = self::DOMAIN_PREFIX.static::domain($address);

        foreach ($patterns as $pattern) {
            $pattern = static::normalize($pattern);

            if ($pattern === $address || $pattern === $domain) {
                return true;
            }
        }

        return false;
    }

    public function isBlocked(MailboxMessage $message, ?Mailbox $mailbox = null): bool
    {
        $mailbox ??= $message->mailbox;

        return $this->matches($message->from_address, $mailbox->blockedSenders()->pluck('pattern'));
    }

    protected static function domain(string $address): string
    {
        return mb_strtolower((string) substr(strrchr($address, '@') ?: '', 1));
    }
}
