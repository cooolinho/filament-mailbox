<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Data\RenderedTemplate;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Models\MailboxTemplate;
use Cooolinho\FilamentMailbox\Support\HtmlToText;
use Cooolinho\FilamentMailbox\Support\PlaceholderRenderer;

/**
 * Renders templates with their placeholders. No template language: values
 * are substituted, HTML-escaped for HTML, unknown placeholders are kept.
 */
class TemplateRenderer
{
    /**
     * @param  array<string, string|null>  $variables
     */
    public function render(string $template, array $variables, string $format = ComposeMessageForm::FORMAT_TEXT): RenderedTemplate
    {
        return PlaceholderRenderer::render($template, $variables, html: ComposeMessageForm::isHtmlFormat($format));
    }

    /**
     * @param  array<string, string|null>  $variables
     */
    public function text(MailboxTemplate $template, array $variables): RenderedTemplate
    {
        $text = filled($template->body_text) ? (string) $template->body_text : HtmlToText::readable($template->body_html);

        return $this->render($text, $variables);
    }

    /**
     * The HTML variant, or the rendered text as paragraphs.
     *
     * @param  array<string, string|null>  $variables
     */
    public function html(MailboxTemplate $template, array $variables): RenderedTemplate
    {
        if (blank($template->body_html)) {
            $text = $this->text($template, $variables);

            return new RenderedTemplate(ComposeMessageForm::textToHtml($text->content), $text->unknown);
        }

        return $this->render($template->body_html, $variables, ComposeMessageForm::FORMAT_HTML);
    }

    /**
     * @param  array<string, string|null>  $variables
     */
    public function subject(MailboxTemplate $template, array $variables): string
    {
        return $this->render((string) $template->subject, $variables)->content;
    }
}
