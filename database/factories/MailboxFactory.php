<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
{
    protected $model = Mailbox::class;

    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'name' => fake()->words(2, true),
            'provider' => 'imap',
            'email' => $email,
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => Encryption::Ssl,
            'validate_cert' => true,
            'username' => $email,
            'password' => 'secret',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
