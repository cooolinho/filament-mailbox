<?php

namespace Cooolinho\FilamentMailbox\Support;

use Cooolinho\FilamentMailbox\Data\RenderedTemplate;

/**
 * Replaces "{name}" / "{group.name}" placeholders with plain values.
 *
 * Deliberately no template language (Blade, Twig): signatures and templates
 * are user content, so nothing in them is ever compiled or executed. Values
 * are HTML-escaped for HTML content; unknown placeholders stay as they are.
 */
class PlaceholderRenderer
{
    public const PATTERN = '/\{([a-z][a-z_]*(?:\.[a-z][a-z_]*)?)\}/';

    /**
     * @param  array<string, string|null>  $variables
     */
    public static function render(?string $template, array $variables, bool $html = false): RenderedTemplate
    {
        $unknown = [];

        $content = preg_replace_callback(self::PATTERN, function (array $match) use ($variables, $html, &$unknown): string {
            if (! array_key_exists($match[1], $variables)) {
                $unknown[] = $match[1];

                return $match[0];
            }

            $value = (string) $variables[$match[1]];

            return $html ? e($value) : $value;
        }, (string) $template) ?? (string) $template;

        return new RenderedTemplate($content, array_values(array_unique($unknown)));
    }
}
