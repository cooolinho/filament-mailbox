<?php

namespace Cooolinho\FilamentMailbox\Providers\Graph;

use Cooolinho\FilamentMailbox\Enums\LabelColor;

/**
 * Maps Outlook category presets (preset0 … preset24) to label colours.
 */
final class GraphCategoryColor
{
    protected const PRESETS = [
        'preset0' => LabelColor::Red, 'preset1' => LabelColor::Orange, 'preset2' => LabelColor::Amber,
        'preset3' => LabelColor::Yellow, 'preset4' => LabelColor::Green, 'preset5' => LabelColor::Teal,
        'preset6' => LabelColor::Lime, 'preset7' => LabelColor::Blue, 'preset8' => LabelColor::Purple,
        'preset9' => LabelColor::Rose, 'preset10' => LabelColor::Sky, 'preset11' => LabelColor::Indigo,
        'preset12' => LabelColor::Gray, 'preset13' => LabelColor::Gray, 'preset14' => LabelColor::Gray,
        'preset15' => LabelColor::Red, 'preset16' => LabelColor::Orange, 'preset17' => LabelColor::Amber,
        'preset18' => LabelColor::Yellow, 'preset19' => LabelColor::Green, 'preset20' => LabelColor::Teal,
        'preset21' => LabelColor::Lime, 'preset22' => LabelColor::Blue, 'preset23' => LabelColor::Violet,
        'preset24' => LabelColor::Pink,
    ];

    public static function toLabel(?string $preset): ?LabelColor
    {
        return static::PRESETS[strtolower((string) $preset)] ?? null;
    }

    public static function toPreset(?LabelColor $color): string
    {
        if (! $color) {
            return 'none';
        }

        return (string) (array_search($color, static::PRESETS, true) ?: match ($color) {
            LabelColor::Cyan => 'preset5',
            default => 'none',
        });
    }
}
