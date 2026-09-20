<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailboxFolder>
 */
class MailboxFolderFactory extends Factory
{
    protected $model = MailboxFolder::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'mailbox_id' => Mailbox::factory(),
            'name' => $name,
            'full_name' => $name,
            'remote_id' => fn (array $attributes): string => $attributes['full_name'],
            'delimiter' => '/',
            'sync_cursor' => ['uid_validity' => 1, 'last_uid' => 0],
            'is_active' => true,
        ];
    }

    public function inbox(): static
    {
        return $this->state([
            'name' => 'INBOX',
            'full_name' => 'INBOX',
            'special_use' => SpecialUse::Inbox,
        ]);
    }

    public function specialUse(SpecialUse $specialUse): static
    {
        return $this->state(['special_use' => $specialUse]);
    }
}
