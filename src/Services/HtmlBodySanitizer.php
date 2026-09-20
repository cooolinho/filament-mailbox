<?php

namespace Cooolinho\FilamentMailbox\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitises untrusted e-mail HTML.
 *
 * Incoming HTML is additionally rendered inside a sandboxed iframe whose
 * Content-Security-Policy blocks scripts and any remote resource. Outgoing
 * HTML (composed mail, signatures, templates) uses a narrow allow-list.
 */
class HtmlBodySanitizer
{
    public const CSP = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:";

    /**
     * Elements with their attributes allowed in outgoing HTML.
     *
     * @var array<string, array<int, string>>
     */
    public const OUTGOING_ELEMENTS = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'h2' => [], 'h3' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [],
        'pre' => [], 'code' => [], 'div' => ['data-signature'],
        'a' => ['href'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'img' => ['data-cid', 'alt', 'width', 'height'],
    ];

    protected ?HtmlSanitizer $sanitizer = null;

    protected ?HtmlSanitizer $strictSanitizer = null;

    /** @var array<string, HtmlSanitizer> */
    protected array $outgoingSanitizers = [];

    /**
     * @param  bool  $strict  Remove links, e.g. for messages in the spam folder
     */
    public function sanitize(?string $html, bool $strict = false): string
    {
        if (blank($html)) {
            return '';
        }

        return ($strict ? $this->strictSanitizer() : $this->sanitizer())->sanitize($html);
    }

    /**
     * Sanitise HTML that is sent: no styles, classes, scripts or event
     * handlers, links only to http(s)/mailto and images only as "cid:"
     * references to inline parts.
     *
     * @param  bool  $keepImageIds  Keep the storage path of editor images ("data-id") for stored content
     *                              (signatures, templates, drafts) whose images are embedded when sending
     */
    public function outgoing(?string $html, bool $keepImageIds = false): string
    {
        if (blank($html)) {
            return '';
        }

        // Symfony's URL sanitizer rejects host-less "cid:" URLs, so they pass as data attribute.
        $html = preg_replace_callback(
            '/(<img\b[^>]*?)\ssrc\s*=\s*(["\'])cid:([^"\']*)\2/i',
            fn (array $match): string => $match[1].' data-cid="'.e($match[3]).'"',
            $html,
        ) ?? '';

        $html = preg_replace_callback(
            '/\sdata-cid="([^"]*)"/',
            function (array $match): string {
                $contentId = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);

                return preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/', $contentId) ? ' src="cid:'.$contentId.'"' : '';
            },
            $this->outgoingSanitizer($keepImageIds)->sanitize($html),
        ) ?? '';

        // Images whose source was removed are dropped entirely.
        return preg_replace($keepImageIds ? '/<img\b(?![^>]*\s(?:src|data-id)=")[^>]*>/i' : '/<img\b(?![^>]*\ssrc=")[^>]*>/i', '', $html) ?? '';
    }

    /**
     * Build a standalone document for an iframe "srcdoc" attribute.
     */
    public function document(?string $html, bool $strict = false): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            .'<meta http-equiv="Content-Security-Policy" content="'.self::CSP.'">'
            .($strict ? '' : '<base target="_blank">')
            .'<style>body{margin:0;padding:1rem;font-family:ui-sans-serif,system-ui,sans-serif;color:#111827;background:#fff;word-wrap:break-word}img{max-width:100%;height:auto}</style>'
            .'</head><body>'.$this->sanitize($html, $strict).'</body></html>';
    }

    protected function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ??= new HtmlSanitizer($this->config());
    }

    /**
     * Links are unwrapped to their text, so phishing targets cannot be opened.
     */
    protected function strictSanitizer(): HtmlSanitizer
    {
        return $this->strictSanitizer ??= new HtmlSanitizer(
            $this->config()
                ->blockElement('a')
                ->blockElement('area')
                ->allowLinkSchemes([]),
        );
    }

    protected function outgoingSanitizer(bool $keepImageIds = false): HtmlSanitizer
    {
        if (isset($this->outgoingSanitizers[$key = $keepImageIds ? 'ids' : 'default'])) {
            return $this->outgoingSanitizers[$key];
        }

        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes(['cid'])
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength((int) config('filament-mailbox.html.max_input_length', 1_048_576));

        foreach (self::OUTGOING_ELEMENTS as $element => $attributes) {
            $config = $config->allowElement($element, $element === 'img' && $keepImageIds ? [...$attributes, 'data-id'] : $attributes);
        }

        // Unknown wrappers (span, font, section, …) keep their text.
        foreach (['span', 'font', 'section', 'article', 'header', 'footer', 'center', 'small', 'sub', 'sup', 'mark', 'h1', 'h4', 'h5', 'h6', 'label', 'tfoot', 'caption', 'figure', 'figcaption', 'details', 'summary'] as $element) {
            $config = $config->blockElement($element);
        }

        return $this->outgoingSanitizers[$key] = new HtmlSanitizer($config);
    }

    protected function config(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowAttribute('style', '*')
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes(['data'])
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength((int) config('filament-mailbox.html.max_input_length', 1_048_576));
    }
}
