<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Models\MailboxTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxTemplate>
 */
class MailboxTemplateFactory extends Factory
{
    protected $model = MailboxTemplate::class;

    public function definition(): array
    {
        return [
            'mailbox_id' => null,
            'name' => fake()->words(3, true),
            'category' => null,
            'subject' => null,
            'body_text' => fake()->sentence(),
            'contexts' => ['new', 'reply', 'forward'],
        ];
    }

    /**
     * @param  array<int, string>  $contexts
     */
    public function contexts(array $contexts): static
    {
        return $this->state(['contexts' => $contexts]);
    }
}
