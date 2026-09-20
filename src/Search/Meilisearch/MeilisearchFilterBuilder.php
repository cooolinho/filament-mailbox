<?php

namespace Cooolinho\FilamentMailbox\Search\Meilisearch;

use Carbon\CarbonInterface;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Search\SearchFilter;
use Cooolinho\FilamentMailbox\Search\SearchDocumentMapper;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchScope;

/**
 * Meilisearch filter expressions. The mailbox of the scope is always required;
 * values are typed or escaped.
 */
class MeilisearchFilterBuilder
{
    /**
     * @return array<int, string>
     */
    public function build(SearchQuery $query, SearchScope $scope): array
    {
        $filters = ['mailbox_id = '.$scope->mailboxId];

        if ($scope->folderId !== null) {
            $filters[] = 'folder_id = '.(int) $scope->folderId;
        }

        foreach ($query->filters as $filter) {
            $expression = match ($filter->name) {
                SearchFilter::FROM => $this->tokens('from_tokens', (string) $filter->value),
                SearchFilter::TO => $this->tokens('to_tokens', (string) $filter->value),
                SearchFilter::CC => $this->tokens('cc_tokens', (string) $filter->value),
                SearchFilter::SUBJECT => $this->tokens('subject_tokens', (string) $filter->value),
                SearchFilter::FILENAME => $this->tokens('attachment_name_tokens', (string) $filter->value),
                SearchFilter::HAS_ATTACHMENT => 'has_attachments = '.($filter->value ? 'true' : 'false'),
                SearchFilter::IS_READ => 'is_read = '.($filter->value ? 'true' : 'false'),
                SearchFilter::IS_FLAGGED => 'is_flagged = '.($filter->value ? 'true' : 'false'),
                SearchFilter::IN_FOLDER => $this->folder((string) $filter->value, $scope),
                SearchFilter::BEFORE => 'received_at_ts < '.$this->timestamp($filter->value),
                SearchFilter::AFTER => 'received_at_ts >= '.$this->timestamp($filter->value),
                default => null,
            };

            if ($expression === null) {
                continue;
            }

            // Flags are negated by their value already.
            $filters[] = $filter->negated && ! in_array($filter->name, [SearchFilter::HAS_ATTACHMENT, SearchFilter::IS_READ, SearchFilter::IS_FLAGGED], true)
                ? 'NOT ('.$expression.')'
                : $expression;
        }

        return $filters;
    }

    /**
     * Every word of the value must be a token of the attribute.
     */
    protected function tokens(string $attribute, string $value): ?string
    {
        $tokens = SearchDocumentMapper::tokens($value);

        if ($tokens === []) {
            return null;
        }

        return '('.implode(' AND ', array_map(fn (string $token): string => $attribute.' = '.static::escape($token), $tokens)).')';
    }

    protected function folder(string $value, SearchScope $scope): string
    {
        $name = mb_strtolower($value);
        $specialUse = match ($name) {
            'spam' => SpecialUse::Junk,
            'bin' => SpecialUse::Trash,
            default => SpecialUse::tryFrom($name),
        };

        $ids = MailboxFolder::query()
            ->where('mailbox_id', $scope->mailboxId)
            ->where(fn ($folders) => $folders
                ->when($specialUse, fn ($folders) => $folders->orWhere('special_use', $specialUse))
                ->orWhereRaw('LOWER(name) = ?', [$name])
                ->orWhereRaw('LOWER(full_name) = ?', [$name]))
            ->pluck('id')
            ->all();

        return 'folder_id IN ['.implode(', ', $ids === [] ? [0] : array_map('intval', $ids)).']';
    }

    protected function timestamp(mixed $value): int
    {
        return $value instanceof CarbonInterface ? $value->getTimestamp() : 0;
    }

    /**
     * Single-quoted string with escaped quotes and backslashes (Meilisearch filter syntax).
     */
    public static function escape(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
