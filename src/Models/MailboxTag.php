<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\MailboxTagFactory;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * App-only tag. Unlike labels, tags never reach the mail server.
 *
 * @property int $id
 * @property ?int $mailbox_id
 * @property string $name
 * @property LabelColor $color
 * @property ?string $description
 * @property int $sort
 */
class MailboxTag extends Model
{
    /** @use HasFactory<MailboxTagFactory> */
    use HasFactory;

    protected $table = 'mailbox_tags';

    protected $fillable = [
        'mailbox_id',
        'name',
        'color',
        'description',
        'sort',
    ];

    protected $attributes = [
        'color' => 'gray',
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'color' => LabelColor::class,
            'sort' => 'integer',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(MailboxMessage::class, 'mailbox_message_tag', 'tag_id', 'message_id')
            ->withPivot(['message_key', 'tagged_by'])
            ->withTimestamps();
    }

    public function isGlobal(): bool
    {
        return $this->mailbox_id === null;
    }

    public function isAvailableFor(Mailbox|int $mailbox): bool
    {
        return $this->isGlobal() || $this->mailbox_id === ($mailbox instanceof Mailbox ? $mailbox->getKey() : $mailbox);
    }

    /**
     * Global tags and the tags of the given mailbox.
     */
    public function scopeVisibleFor(Builder $query, Mailbox|int $mailbox): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('mailbox_tags.mailbox_id')
            ->orWhere('mailbox_tags.mailbox_id', $mailbox instanceof Mailbox ? $mailbox->getKey() : $mailbox));
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('mailbox_tags.sort')->orderBy('mailbox_tags.name');
    }

    protected static function newFactory(): MailboxTagFactory
    {
        return MailboxTagFactory::new();
    }
}
