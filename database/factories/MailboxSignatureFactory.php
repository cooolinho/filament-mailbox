<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxSignature;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxSignature>
 */
class MailboxSignatureFactory extends Factory
{
    protected $model = MailboxSignature::class;

    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'name' => fake()->words(2, true),
            'body_text' => fake()->name()."\n".fake()->companyEmail(),
        ];
    }

    public function personal(Authenticatable $user): static
    {
        return $this->state(['user_id' => $user->getAuthIdentifier()]);
    }

    public function default(): static
    {
        return $this->state(['is_default_new' => true, 'is_default_reply' => true, 'is_default_forward' => true]);
    }
}
