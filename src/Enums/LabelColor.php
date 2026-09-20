<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasLabel;

/**
 * Predefined label colours. Only these values are stored, so no arbitrary
 * CSS can be injected.
 */
enum LabelColor: string implements HasLabel
{
    case Gray = 'gray';
    case Red = 'red';
    case Orange = 'orange';
    case Amber = 'amber';
    case Yellow = 'yellow';
    case Lime = 'lime';
    case Green = 'green';
    case Teal = 'teal';
    case Cyan = 'cyan';
    case Sky = 'sky';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Purple = 'purple';
    case Pink = 'pink';
    case Rose = 'rose';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return array<int, string>
     */
    public function palette(): array
    {
        return constant(Color::class.'::'.$this->name);
    }
}
