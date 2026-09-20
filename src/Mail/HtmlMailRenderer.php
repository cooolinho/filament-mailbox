<?php

namespace Cooolinho\FilamentMailbox\Mail;

use Illuminate\Contracts\View\Factory;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Renders sanitised HTML into a simple mail layout with inline CSS, which
 * mail clients without <style> support (Outlook, Gmail) respect.
 */
class HtmlMailRenderer
{
    public const CSS = <<<'CSS'
        .message { font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #111827; }
        p { margin: 0 0 12px 0; }
        h2 { font-size: 20px; line-height: 1.3; margin: 16px 0 8px 0; }
        h3 { font-size: 16px; line-height: 1.3; margin: 16px 0 8px 0; }
        ul, ol { margin: 0 0 12px 0; padding-left: 24px; }
        blockquote { margin: 0 0 12px 0; padding: 0 0 0 12px; border-left: 3px solid #d1d5db; color: #4b5563; }
        table { border-collapse: collapse; margin: 0 0 12px 0; }
        th, td { border: 1px solid #d1d5db; padding: 4px 8px; text-align: left; vertical-align: top; }
        td.message { border: 0; padding: 16px; }
        th { background-color: #f3f4f6; }
        a { color: #2563eb; }
        img { max-width: 100%; height: auto; }
        pre { font-family: monospace; white-space: pre-wrap; }
        hr { border: 0; border-top: 1px solid #d1d5db; margin: 16px 0; }
        CSS;

    public function __construct(
        protected Factory $views,
    ) {}

    public function render(string $sanitizedHtml): string
    {
        $html = $this->views->make('filament-mailbox::mail.message-html', ['html' => $sanitizedHtml])->render();

        return (new CssToInlineStyles)->convert($html, self::CSS);
    }
}
