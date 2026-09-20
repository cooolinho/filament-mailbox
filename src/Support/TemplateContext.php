<?php

namespace Cooolinho\FilamentMailbox\Support;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Placeholder values for templates.
 *
 * The recipient is the sender of the original message (replies), otherwise
 * the first "To" address of the form (without a name). Names from messages are untrusted and
 * escaped by the renderer for HTML.
 */
class TemplateContext
{
    public const PLACEHOLDERS = [
        'recipient.name', 'recipient.first_name', 'recipient.email',
        'original.subject', 'original.date',
        'user.name', 'user.email', 'mailbox.name', 'mailbox.email', 'today',
    ];

    /**
     * @param  array<int, string>  $to  Recipients of the form
     * @param  bool  $recipientIsSender  The recipient is the sender of the original (replies, not forwards)
     * @return array<string, string>
     */
    public static function for(Mailbox $mailbox, ?Authenticatable $user, ?MailboxMessage $original = null, array $to = [], bool $recipientIsSender = true): array
    {
        [$name, $email] = $original && $recipientIsSender
            ? [(string) $original->from_name, (string) $original->from_address]
            : ['', (string) ($to[0] ?? '')];

        $date = $original?->sent_at ?? $original?->received_at;

        return [
            'recipient.name' => $name,
            'recipient.first_name' => static::firstName($name),
            'recipient.email' => $email,
            'original.subject' => (string) $original?->subject,
            'original.date' => $date ? $date->toFormattedDayDateString() : '',
            'user.name' => (string) data_get($user, 'name'),
            'user.email' => (string) data_get($user, 'email'),
            'mailbox.name' => (string) $mailbox->name,
            'mailbox.email' => (string) $mailbox->email,
            'today' => now()->toFormattedDayDateString(),
        ];
    }

    /**
     * Example values for the preview of the template catalogue.
     *
     * @return array<string, string>
     */
    public static function example(?Mailbox $mailbox, ?Authenticatable $user): array
    {
        return [
            'recipient.name' => 'Jane Doe',
            'recipient.first_name' => 'Jane',
            'recipient.email' => 'jane.doe@example.com',
            'original.subject' => __('filament-mailbox::mailbox.templates.example_subject'),
            'original.date' => now()->subDay()->toFormattedDayDateString(),
            'user.name' => (string) data_get($user, 'name'),
            'user.email' => (string) data_get($user, 'email'),
            'mailbox.name' => (string) ($mailbox?->name ?? 'Support'),
            'mailbox.email' => (string) ($mailbox?->email ?? 'support@example.com'),
            'today' => now()->toFormattedDayDateString(),
        ];
    }

    /**
     * First word of a display name ("Doe, Jane" → "Jane"); empty when unknown.
     */
    public static function firstName(?string $name): string
    {
        $name = trim((string) $name, " \t\"'");

        if ($name === '' || str_contains($name, '@')) {
            return '';
        }

        if (str_contains($name, ',')) {
            $name = trim(substr($name, strpos($name, ',') + 1));
        }

        return (string) (preg_split('/\s+/u', $name)[0] ?? '');
    }
}
