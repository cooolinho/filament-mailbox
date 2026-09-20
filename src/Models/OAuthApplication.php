<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\OAuthApplicationFactory;
use Cooolinho\FilamentMailbox\Enums\OAuthProviderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An app registration at Microsoft Entra ID or Google Cloud.
 *
 * @property int $id
 * @property string $name
 * @property OAuthProviderType $provider
 * @property ?string $tenant
 * @property string $client_id
 * @property ?string $client_secret
 * @property ?string $certificate
 * @property ?array<string, mixed> $extra
 */
class OAuthApplication extends Model
{
    /** @use HasFactory<OAuthApplicationFactory> */
    use HasFactory;

    protected $table = 'mailbox_oauth_applications';

    protected $fillable = [
        'name',
        'provider',
        'tenant',
        'client_id',
        'client_secret',
        'certificate',
        'extra',
    ];

    protected $hidden = [
        'client_secret',
        'certificate',
    ];

    protected function casts(): array
    {
        return [
            'provider' => OAuthProviderType::class,
            'client_secret' => 'encrypted',
            'certificate' => 'encrypted',
            'extra' => 'array',
        ];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(OAuthConnection::class, 'application_id');
    }

    protected static function newFactory(): OAuthApplicationFactory
    {
        return OAuthApplicationFactory::new();
    }
}
