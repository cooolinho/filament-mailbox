<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Models\MailboxTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxTag>
 */
class MailboxTagFactory extends Factory
{
    protected $model = MailboxTag::class;

    public function definition(): array
    {
        return [
            'mailbox_id' => null,
            'name' => ucfirst(fake()->unique()->word()),
            'color' => fake()->randomElement(LabelColor::cases()),
        ];
    }
}
