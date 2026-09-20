<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\MailboxSignatureFactory;
use Cooolinho\FilamentMailbox\Services\HtmlBodySanitizer;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signature of a mailbox, or a personal signature of a user for a mailbox.
 *
 * Only one signature per scope (mailbox, or user + mailbox) is the default
 * for each compose context (new, reply, forward).
 *
 * @property int $id
 * @property int $mailbox_id
 * @property ?int $user_id
 * @property string $name
 * @property ?string $body_text
 * @property ?string $body_html
 * @property bool $is_default_new
 * @property bool $is_default_reply
 * @property bool $is_default_forward
 */
class MailboxSignature extends Model
{
    /** @use HasFactory<MailboxSignatureFactory> */
    use HasFactory;

    protected $table = 'mailbox_signatures';

    protected $fillable = [
        'mailbox_id',
        'user_id',
        'name',
        'body_text',
        'body_html',
        'is_default_new',
        'is_default_reply',
        'is_default_forward',
    ];

    protected function casts(): array
    {
        return [
            'is_default_new' => 'boolean',
            'is_default_reply' => 'boolean',
            'is_default_forward' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MailboxSignature $signature): void {
            $html = app(HtmlBodySanitizer::class)->outgoing($signature->body_html, keepImageIds: true);

            $signature->body_html = blank(strip_tags($html, '<img>')) ? null : $html;
        });

        static::saved(function (MailboxSignature $signature): void {
            foreach (ComposeContext::cases() as $context) {
                $column = $context->defaultColumn();

                if (! $signature->{$column}) {
                    continue;
                }

                static::query()
                    ->where('mailbox_id', $signature->mailbox_id)
                    ->where(fn (Builder $query) => $signature->user_id === null
                        ? $query->whereNull('user_id')
                        : $query->where('user_id', $signature->user_id))
                    ->whereKeyNot($signature->getKey())
                    ->where($column, true)
                    ->update([$column => false]);
            }
        });
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('filament-mailbox.user_model'));
    }

    public function isPersonal(): bool
    {
        return $this->user_id !== null;
    }

    public function isOwnedBy(?Authenticatable $user): bool
    {
        return $user !== null && $this->user_id !== null && (string) $this->user_id === (string) $user->getAuthIdentifier();
    }

    public function isDefaultFor(ComposeContext $context): bool
    {
        return (bool) $this->{$context->defaultColumn()};
    }

    /**
     * @param  Builder<MailboxSignature>  $query
     */
    public function scopeShared(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /**
     * @param  Builder<MailboxSignature>  $query
     */
    public function scopePersonalFor(Builder $query, Authenticatable $user): void
    {
        $query->where('user_id', $user->getAuthIdentifier());
    }

    protected static function newFactory(): MailboxSignatureFactory
    {
        return MailboxSignatureFactory::new();
    }
}
