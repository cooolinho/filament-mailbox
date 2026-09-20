<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\MailboxFactory;
use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Support\ProviderCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;

/**
 * @property int $id
 * @property string $name
 * @property ProviderType $provider
 * @property AuthMode $auth_mode
 * @property ?int $oauth_connection_id
 * @property ?string $remote_user
 * @property string $email
 * @property ?string $host
 * @property ?int $port
 * @property ?Encryption $encryption
 * @property bool $validate_cert
 * @property ?string $username
 * @property ?string $password
 * @property ?string $smtp_host
 * @property ?int $smtp_port
 * @property bool $is_active
 * @property ?\Illuminate\Support\Carbon $last_synced_at
 * @property ?string $last_sync_error
 * @property ?array<string, scalar|null> $sync_cursor
 * @property ?int $initial_sync_days
 * @property ?\Illuminate\Support\Carbon $watch_expires_at
 * @property ?int $archive_folder_id
 * @property ?int $spam_folder_id
 * @property string $health
 * @property int $consecutive_failures
 * @property ?\Illuminate\Support\Carbon $last_success_at
 * @property ?string $last_run_status
 * @property ?int $last_run_duration_ms
 * @property ?string $last_error_type
 * @property int $failing_folders
 * @property ?array{timezone?: ?string, hours?: array<int, array{day: string, start: string, end: string}>, holidays?: array<int, string>} $business_hours
 * @property ?int $sla_minutes
 * @property-read ?OAuthConnection $oauthConnection
 */
class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use HasFactory;

    protected $table = 'mailboxes';

    protected $fillable = [
        'name',
        'provider',
        'auth_mode',
        'oauth_connection_id',
        'remote_user',
        'email',
        'host',
        'port',
        'encryption',
        'validate_cert',
        'username',
        'password',
        'smtp_host',
        'smtp_port',
        'initial_sync_days',
        'archive_folder_id',
        'spam_folder_id',
        'compose_format',
        'business_hours',
        'sla_minutes',
        'request_read_receipts',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected $attributes = [
        'provider' => 'imap',
        'auth_mode' => 'password',
        'validate_cert' => true,
        'is_active' => true,
        'health' => 'unknown',
        'consecutive_failures' => 0,
        'failing_folders' => 0,
    ];

    protected function casts(): array
    {
        return [
            'provider' => ProviderType::class,
            'auth_mode' => AuthMode::class,
            'smtp_port' => 'integer',
            'request_read_receipts' => 'boolean',
            'port' => 'integer',
            'encryption' => Encryption::class,
            'validate_cert' => 'boolean',
            'password' => 'encrypted',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'sync_cursor' => 'array',
            'initial_sync_days' => 'integer',
            'watch_expires_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_run_duration_ms' => 'integer',
            'failing_folders' => 'integer',
            'business_hours' => 'array',
            'sla_minutes' => 'integer',
        ];
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(MailboxDraft::class);
    }

    public function outgoingMessages(): HasMany
    {
        return $this->hasMany(MailboxOutgoingMessage::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(MailboxSignature::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(config('filament-mailbox.user_model'), 'mailbox_user')->withTimestamps();
    }

    public function oauthConnection(): BelongsTo
    {
        return $this->belongsTo(OAuthConnection::class, 'oauth_connection_id');
    }

    public function usesOAuth(): bool
    {
        return $this->auth_mode === AuthMode::OAuth;
    }

    public function folders(): HasMany
    {
        return $this->hasMany(MailboxFolder::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MailboxMessage::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(MailboxLabel::class);
    }

    /**
     * Whether server-side labels (keywords, Gmail labels, categories) are available.
     */
    public function supportsLabels(): bool
    {
        return $this->supports(ProviderCapability::Labels) || $this->supports(ProviderCapability::Keywords);
    }

    /**
     * Target folder for archiving: the configured folder, otherwise the
     * folder with the Archive special-use role.
     */
    public function archiveFolder(): ?MailboxFolder
    {
        return $this->configuredFolder($this->archive_folder_id) ?? $this->folderFor(SpecialUse::Archive);
    }

    /**
     * Target folder for spam: the configured folder, otherwise the folder
     * with the Junk special-use role.
     */
    public function spamFolder(): ?MailboxFolder
    {
        return $this->configuredFolder($this->spam_folder_id) ?? $this->folderFor(SpecialUse::Junk);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(MailboxSyncRun::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(MailboxAlert::class);
    }

    public function blockedSenders(): HasMany
    {
        return $this->hasMany(MailboxBlockedSender::class);
    }

    /**
     * An active folder of this mailbox, never a folder of another mailbox.
     */
    protected function configuredFolder(?int $id): ?MailboxFolder
    {
        return $id ? $this->folders()->where('is_active', true)->find($id) : null;
    }

    public function folderFor(SpecialUse $specialUse): ?MailboxFolder
    {
        return $this->folders()
            ->where('is_active', true)
            ->where('special_use', $specialUse)
            ->first();
    }

    /**
     * Capabilities are static per provider type, so no connection is needed.
     */
    public function supports(ProviderCapability $capability): bool
    {
        return app(ProviderCapabilities::class)->supports($this, $capability);
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->users()->whereKey($user->getKey())->exists();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeAssignedTo(Builder $query, User $user): void
    {
        $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->getKey()));
    }

    protected static function newFactory(): MailboxFactory
    {
        return MailboxFactory::new();
    }
}
