<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Data\LabelData;
use Cooolinho\FilamentMailbox\Database\Factories\MailboxLabelFactory;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property LabelSource $source
 * @property string $remote_key
 * @property string $name
 * @property ?LabelColor $color
 * @property bool $is_hidden
 */
class MailboxLabel extends Model
{
    /** @use HasFactory<MailboxLabelFactory> */
    use HasFactory;

    protected $table = 'mailbox_labels';

    protected $fillable = [
        'mailbox_id',
        'source',
        'remote_key',
        'name',
        'color',
        'is_hidden',
    ];

    protected $attributes = [
        'is_hidden' => false,
    ];

    protected function casts(): array
    {
        return [
            'source' => LabelSource::class,
            'color' => LabelColor::class,
            'is_hidden' => 'boolean',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(MailboxMessage::class, 'mailbox_message_labels', 'label_id', 'message_id');
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    public function toData(): LabelData
    {
        return new LabelData($this->source, $this->remote_key, $this->name, $this->color);
    }

    protected static function newFactory(): MailboxLabelFactory
    {
        return MailboxLabelFactory::new();
    }
}
