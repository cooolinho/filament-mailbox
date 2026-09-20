<?php

namespace Cooolinho\FilamentMailbox\Search;

use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\HtmlToText;
use Illuminate\Support\HtmlString;

/**
 * A short excerpt of the body around the first hit. The text is escaped;
 * only <mark> is added.
 */
class SnippetBuilder
{
    public const RADIUS = 70;

    public function for(MailboxMessage $message, SearchQuery $query): ?HtmlString
    {
        $tokens = array_values(array_unique(array_filter(
            array_merge(...array_map(fn (SearchTerm $term): array => $term->tokens(), $query->terms()) ?: [[]]),
            fn (string $token): bool => mb_strlen($token) >= 2,
        )));

        if ($tokens === []) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', HtmlToText::body($message->text_body, $message->html_body)) ?? '');
        $lower = mb_strtolower($text);
        $position = null;

        foreach ($tokens as $token) {
            $found = mb_strpos($lower, $token);

            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }

        if ($position === null) {
            return null;
        }

        $start = max(0, $position - self::RADIUS);
        $excerpt = mb_substr($text, $start, self::RADIUS * 2 + 20);
        $pattern = '/('.implode('|', array_map(fn (string $token): string => preg_quote(e($token), '/'), $tokens)).')/iu';

        $html = preg_replace($pattern, '<mark>$1</mark>', e($excerpt)) ?? e($excerpt);

        return new HtmlString(($start > 0 ? '… ' : '').$html.($start + mb_strlen($excerpt) < mb_strlen($text) ? ' …' : ''));
    }
}
