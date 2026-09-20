<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxBlockedSender;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxBlockedSender>
 */
class MailboxBlockedSenderFactory extends Factory
{
    protected $model = MailboxBlockedSender::class;

    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'pattern' => fake()->unique()->safeEmail(),
        ];
    }
}
