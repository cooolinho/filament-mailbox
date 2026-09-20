<?php

namespace Cooolinho\FilamentMailbox\Services\Receipts;

/**
 * Parses the machine-readable parts of reports (RFC 8098 MDNs, RFC 3464 DSNs).
 * Reports are untrusted: only known fields are read and every value is limited.
 */
class ReceiptParser
{
    public const MAX_VALUE_LENGTH = 1000;

    /**
     * Header-like field blocks separated by blank lines (per-message block
     * first, then one block per recipient for DSNs). Folded lines are joined,
     * field names are lower-case.
     *
     * @return array<int, array<string, string>>
     */
    public function blocks(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $blocks = [];

        foreach (preg_split('/\n\s*\n/', trim($content)) ?: [] as $raw) {
            $fields = [];
            $current = null;

            foreach (explode("\n", $raw) as $line) {
                if ($current !== null && preg_match('/^[ \t]+/', $line)) {
                    $fields[$current] = mb_substr($fields[$current].' '.trim($line), 0, self::MAX_VALUE_LENGTH);

                    continue;
                }

                if (preg_match('/^([A-Za-z0-9-]{1,64}):\s*(.*)$/', $line, $matches)) {
                    $current = strtolower($matches[1]);
                    // The first occurrence counts.
                    $fields[$current] ??= mb_substr(trim($matches[2]), 0, self::MAX_VALUE_LENGTH);
                }
            }

            if ($fields !== []) {
                $blocks[] = $fields;
            }
        }

        return $blocks;
    }

    /**
     * @return ?array{original_message_id: string, recipient: ?string, disposition: string, mode: ?string}
     */
    public function dispositionNotification(string $content): ?array
    {
        $fields = array_merge(...array_reverse($this->blocks($content) ?: [[]]));
        $messageId = static::messageId($fields['original-message-id'] ?? null);

        // "manual-action/MDN-sent-manually; displayed"
        if ($messageId === null || ! preg_match('/^\s*([a-z-]+\/[a-z-]+)?\s*;\s*([a-z-]+)/i', $fields['disposition'] ?? '', $matches)) {
            return null;
        }

        return [
            'original_message_id' => $messageId,
            'recipient' => static::address($fields['final-recipient'] ?? $fields['original-recipient'] ?? null),
            'disposition' => strtolower($matches[2]),
            'mode' => filled($matches[1]) ? strtolower($matches[1]) : null,
        ];
    }

    /**
     * RFC 3464 delivery status: envelope id and one entry per recipient.
     *
     * @return ?array{envelope_id: ?string, reporting_mta: ?string, recipients: array<int, array{recipient: string, action: string, status: ?string, diagnostic: ?string}>}
     */
    public function deliveryStatus(string $content): ?array
    {
        $blocks = $this->blocks($content);

        if ($blocks === []) {
            return null;
        }

        $message = $blocks[0];
        $recipients = [];

        foreach ($blocks as $block) {
            $recipient = static::address($block['final-recipient'] ?? $block['original-recipient'] ?? null);
            $action = strtolower(trim($block['action'] ?? ''));

            if ($recipient === null || ! in_array($action, ['failed', 'delayed', 'delivered', 'relayed', 'expanded'], true)) {
                continue;
            }

            $recipients[] = [
                'recipient' => $recipient,
                'action' => $action,
                'status' => preg_match('/^\s*([245]\.\d{1,3}\.\d{1,3})/', $block['status'] ?? '', $matches) ? $matches[1] : null,
                'diagnostic' => isset($block['diagnostic-code']) ? mb_substr(strip_tags($block['diagnostic-code']), 0, 500) : null,
            ];
        }

        if ($recipients === []) {
            return null;
        }

        return [
            'envelope_id' => isset($message['original-envelope-id']) ? mb_substr(static::decodeXtext($message['original-envelope-id']), 0, 100) : null,
            'reporting_mta' => isset($message['reporting-mta']) ? mb_substr(trim(str_contains($message['reporting-mta'], ';') ? substr($message['reporting-mta'], strpos($message['reporting-mta'], ';') + 1) : $message['reporting-mta']), 0, 255) : null,
            'recipients' => $recipients,
        ];
    }

    public static function decodeXtext(string $value): string
    {
        return (string) preg_replace_callback('/\+([0-9A-F]{2})/', fn (array $matches): string => chr((int) hexdec($matches[1])), trim($value));
    }

    /**
     * "rfc822;jane@example.com" → "jane@example.com".
     */
    public static function address(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $address = trim(str_contains($value, ';') ? substr($value, strpos($value, ';') + 1) : $value, " \t<>");

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? mb_strtolower($address) : null;
    }

    /**
     * "<id@example.com>" → "id@example.com".
     */
    public static function messageId(?string $value): ?string
    {
        if (blank($value) || ! preg_match('/<?([^<>\s]+@[^<>\s]+)>?/', $value, $matches)) {
            return null;
        }

        return mb_substr($matches[1], 0, 255);
    }
}
