<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxDraft>
 */
class MailboxDraftFactory extends Factory
{
    protected $model = MailboxDraft::class;

    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'to' => [fake()->safeEmail()],
            'subject' => fake()->sentence(3),
            'body_format' => 'text',
            'body' => fake()->paragraph(),
        ];
    }

    public function by(Authenticatable $user): static
    {
        return $this->state(['user_id' => $user->getAuthIdentifier()]);
    }
}
