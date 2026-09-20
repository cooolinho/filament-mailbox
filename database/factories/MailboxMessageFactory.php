<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxMessage>
 */
class MailboxMessageFactory extends Factory
{
    protected $model = MailboxMessage::class;

    public function definition(): array
    {
        return [
            'folder_id' => MailboxFolder::factory(),
            'mailbox_id' => fn (array $attributes) => MailboxFolder::find($attributes['folder_id'])->mailbox_id,
            'remote_id' => '1:'.fake()->unique()->numberBetween(1, 1_000_000),
            'message_id' => '<'.fake()->uuid().'@example.com>',
            'from_address' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'to' => [['address' => fake()->safeEmail(), 'name' => null]],
            'cc' => [],
            'subject' => fake()->sentence(4),
            'text_body' => fake()->paragraph(),
            'html_body' => null,
            'is_read' => false,
            'has_attachments' => false,
            'sent_at' => now(),
            'received_at' => now(),
        ];
    }

    public function read(): static
    {
        return $this->state(['is_read' => true]);
    }
}
