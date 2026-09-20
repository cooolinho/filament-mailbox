<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxAttachment>
 */
class MailboxAttachmentFactory extends Factory
{
    protected $model = MailboxAttachment::class;

    public function definition(): array
    {
        return [
            'message_id' => MailboxMessage::factory(),
            'filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'disk' => 'local',
            'storage_path' => 'mailbox/'.fake()->uuid(),
        ];
    }
}
