<?php

namespace Cooolinho\FilamentMailbox\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * HTML to plain text conversion for quoting, forwarding and the text
 * alternative of composed HTML mail.
 */
class HtmlToText
{
    public static function convert(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        // Script and style contents are never part of the readable text.
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $html = preg_replace('/<(br|\/p|\/div)\b[^>]*>/i', "\n", $html) ?? '';

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
    }

    /**
     * The text body of a message, converted from HTML when it has none.
     */
    public static function body(?string $text, ?string $html): string
    {
        return blank($text) && filled($html) ? static::convert($html) : (string) $text;
    }

    /**
     * Structured conversion of composed HTML: paragraphs separated by blank
     * lines, lists with "-" / "1.", quotes with "> ", links as "text <url>"
     * and table rows with " | " between the cells.
     */
    public static function readable(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body) {
            return '';
        }

        $text = static::blocks($body);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text, "\n");
    }

    protected static function blocks(DOMNode $node): string
    {
        $output = '';
        $inline = '';

        $flush = function () use (&$output, &$inline): void {
            $line = trim(preg_replace('/[ \t\r\n]+/u', ' ', $inline) ?? $inline);
            $inline = '';

            if ($line !== '') {
                $output .= $line."\n\n";
            }
        };

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $inline .= $child->textContent;

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            switch ($tag) {
                case 'script':
                case 'style':
                case 'head':
                case 'title':
                    break;
                case 'br':
                    $inline .= "\u{2028}";
                    break;
                case 'p':
                case 'div':
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                case 'pre':
                case 'section':
                case 'article':
                case 'header':
                case 'footer':
                    $flush();
                    $content = static::blocks($child);
                    $output .= $content === '' ? '' : rtrim($content, "\n")."\n\n";
                    break;
                case 'hr':
                    $flush();
                    $output .= "---\n\n";
                    break;
                case 'blockquote':
                    $flush();
                    $quoted = rtrim(static::blocks($child), "\n");
                    $output .= implode("\n", array_map(
                        fn (string $line): string => rtrim('> '.$line),
                        explode("\n", $quoted),
                    ))."\n\n";
                    break;
                case 'ul':
                case 'ol':
                    $flush();
                    $number = 1;
                    foreach ($child->childNodes as $item) {
                        if (! $item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                            continue;
                        }

                        $marker = $tag === 'ol' ? ($number++).'. ' : '- ';
                        $lines = explode("\n", preg_replace("/\n{2,}/", "\n", rtrim(static::blocks($item), "\n")) ?? '');
                        $output .= $marker.implode("\n".str_repeat(' ', strlen($marker)), $lines)."\n";
                    }
                    $output .= "\n";
                    break;
                case 'table':
                    $flush();
                    foreach ($child->getElementsByTagName('tr') as $row) {
                        $cells = [];

                        foreach ($row->childNodes as $cell) {
                            if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                                $cells[] = str_replace("\n", ' ', trim(static::blocks($cell)));
                            }
                        }

                        $output .= implode(' | ', $cells)."\n";
                    }
                    $output .= "\n";
                    break;
                default:
                    $inline .= static::inline($child);
            }
        }

        $flush();

        return str_replace("\u{2028}", "\n", preg_replace("/ ?\u{2028} ?/u", "\u{2028}", $output) ?? $output);
    }

    /**
     * Inline content of an element (marks, spans, links, images).
     */
    protected static function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->textContent;
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, ['script', 'style'], true)) {
            return '';
        }

        if ($tag === 'br') {
            return "\u{2028}";
        }

        if ($tag === 'img') {
            return $node->getAttribute('alt');
        }

        $content = '';

        foreach ($node->childNodes as $child) {
            $content .= static::inline($child);
        }

        if ($tag !== 'a') {
            return $content;
        }

        $label = trim($content);
        $href = trim($node->getAttribute('href'));

        return match (true) {
            $href === '', str_starts_with($href, '#') => $content,
            str_starts_with(strtolower($href), 'mailto:') && strcasecmp(substr($href, 7), $label) === 0 => $content,
            $label === '', $label === $href => $href,
            default => $label.' <'.$href.'>',
        };
    }
}
