<?php

namespace Cooolinho\FilamentMailbox\Database\Factories;

use Cooolinho\FilamentMailbox\Enums\OAuthProviderType;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OAuthApplication>
 */
class OAuthApplicationFactory extends Factory
{
    protected $model = OAuthApplication::class;

    public function definition(): array
    {
        return [
            'name' => 'Microsoft 365',
            'provider' => OAuthProviderType::Microsoft,
            'tenant' => 'contoso-tenant',
            'client_id' => fake()->uuid(),
            'client_secret' => 'app-secret-value',
        ];
    }

    public function google(): static
    {
        return $this->state(['name' => 'Google', 'provider' => OAuthProviderType::Google, 'tenant' => null]);
    }
}
