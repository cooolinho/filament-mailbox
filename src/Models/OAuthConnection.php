<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\OAuthConnectionFactory;
use Cooolinho\FilamentMailbox\Enums\OAuthConnectionStatus;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tokens of one account at an OAuth application. A connection can be shared
 * by several mailboxes (e.g. shared mailboxes of the same account).
 *
 * @property int $id
 * @property int $application_id
 * @property OAuthGrantType $grant_type
 * @property ?string $account_email
 * @property ?string $account_subject
 * @property array<int, string> $scopes
 * @property ?string $refresh_token
 * @property ?string $access_token
 * @property ?\Illuminate\Support\Carbon $access_token_expires_at
 * @property OAuthConnectionStatus $status
 * @property ?string $last_error
 * @property ?int $created_by
 * @property-read OAuthApplication $application
 */
class OAuthConnection extends Model
{
    /** @use HasFactory<OAuthConnectionFactory> */
    use HasFactory;

    protected $table = 'mailbox_oauth_connections';

    protected $fillable = [
        'application_id',
        'grant_type',
        'account_email',
        'account_subject',
        'scopes',
        'refresh_token',
        'access_token',
        'access_token_expires_at',
        'status',
        'last_error',
        'created_by',
    ];

    protected $hidden = [
        'refresh_token',
        'access_token',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'grant_type' => OAuthGrantType::class,
            'scopes' => 'array',
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'status' => OAuthConnectionStatus::class,
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OAuthApplication::class, 'application_id');
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'oauth_connection_id');
    }

    public function isActive(): bool
    {
        return $this->status === OAuthConnectionStatus::Active;
    }

    protected static function newFactory(): OAuthConnectionFactory
    {
        return OAuthConnectionFactory::new();
    }
}
