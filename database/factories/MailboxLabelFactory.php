<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxLabel>
 */
class MailboxLabelFactory extends Factory
{
    protected $model = MailboxLabel::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'mailbox_id' => Mailbox::factory(),
            'source' => LabelSource::ImapKeyword,
            'remote_key' => $name,
            'name' => $name,
        ];
    }
}
