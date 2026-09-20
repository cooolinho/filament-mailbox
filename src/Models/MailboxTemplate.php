<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\MailboxTemplateFactory;
use Cooolinho\FilamentMailbox\Services\HtmlBodySanitizer;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable text for composing, global or for one mailbox.
 *
 * @property int $id
 * @property ?int $mailbox_id
 * @property string $name
 * @property ?string $category
 * @property ?string $subject
 * @property ?string $body_text
 * @property ?string $body_html
 * @property array<int, string> $contexts
 * @property ?int $created_by
 * @property int $usage_count
 */
class MailboxTemplate extends Model
{
    /** @use HasFactory<MailboxTemplateFactory> */
    use HasFactory;

    protected $table = 'mailbox_templates';

    protected $fillable = [
        'mailbox_id',
        'name',
        'category',
        'subject',
        'body_text',
        'body_html',
        'contexts',
        'created_by',
    ];

    protected $attributes = [
        'contexts' => '["new","reply","forward"]',
    ];

    protected function casts(): array
    {
        return [
            'contexts' => 'array',
            'usage_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MailboxTemplate $template): void {
            $html = app(HtmlBodySanitizer::class)->outgoing($template->body_html, keepImageIds: true);

            $template->body_html = blank(strip_tags($html, '<img>')) ? null : $html;
            $template->category = filled($template->category) ? trim($template->category) : null;
        });

        static::deleting(function (MailboxTemplate $template): void {
            // Deletes the stored files as well.
            $template->attachments->each->delete();
        });
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * @return HasMany<MailboxTemplateAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MailboxTemplateAttachment::class, 'template_id');
    }

    public function supportsContext(ComposeContext $context): bool
    {
        return in_array($context->value, $this->contexts ?? [], true);
    }

    /**
     * Global templates and templates of the mailbox usable in the context.
     *
     * @param  Builder<MailboxTemplate>  $query
     */
    public function scopeAvailableFor(Builder $query, Mailbox $mailbox, ?ComposeContext $context = null): void
    {
        $query
            ->where(fn (Builder $query) => $query->whereNull('mailbox_id')->orWhere('mailbox_id', $mailbox->getKey()))
            ->when($context, fn (Builder $query) => $query->whereJsonContains('contexts', $context->value));
    }

    protected static function newFactory(): MailboxTemplateFactory
    {
        return MailboxTemplateFactory::new();
    }
}
