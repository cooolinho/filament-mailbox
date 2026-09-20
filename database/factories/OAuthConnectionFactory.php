<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Enums\OAuthConnectionStatus;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OAuthConnection>
 */
class OAuthConnectionFactory extends Factory
{
    protected $model = OAuthConnection::class;

    public function definition(): array
    {
        return [
            'application_id' => OAuthApplication::factory(),
            'grant_type' => OAuthGrantType::AuthorizationCode,
            'account_email' => fake()->safeEmail(),
            'account_subject' => fake()->uuid(),
            'scopes' => ['https://outlook.office.com/IMAP.AccessAsUser.All', 'offline_access'],
            'refresh_token' => 'refresh-token-value',
            'access_token' => 'access-token-value',
            'access_token_expires_at' => now()->addHour(),
            'status' => OAuthConnectionStatus::Active,
        ];
    }

    public function expired(): static
    {
        return $this->state(['access_token_expires_at' => now()->subMinute()]);
    }
}
