<?php

namespace Cooolinho\FilamentMailbox\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Mailbox client settings per user (layout, ...), stored in mailbox_user_preferences.
 */
class UserPreferences
{
    /** @var array<int|string, array<string, mixed>> */
    protected array $loaded = [];

    public function get(?Authenticatable $user, string $key, mixed $default = null): mixed
    {
        if (! $user) {
            return $default;
        }

        return $this->all($user)[$key] ?? $default;
    }

    public function set(Authenticatable $user, string $key, mixed $value): void
    {
        $preferences = [...$this->all($user), $key => $value];

        DB::table('mailbox_user_preferences')->upsert(
            [[
                'user_id' => $user->getAuthIdentifier(),
                'preferences' => json_encode($preferences),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id'],
            ['preferences', 'updated_at'],
        );

        $this->loaded[$user->getAuthIdentifier()] = $preferences;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(Authenticatable $user): array
    {
        return $this->loaded[$user->getAuthIdentifier()] ??= (array) json_decode(
            (string) DB::table('mailbox_user_preferences')->where('user_id', $user->getAuthIdentifier())->value('preferences'),
            true,
        );
    }
}
