<?php

namespace Cooolinho\FilamentMailbox\Search;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Applies search operators to a query of messages or search documents.
 * Folders of "in:" are resolved within the mailbox of the scope only.
 */
class FilterConstraints
{
    /**
     * @param  array{from: array<int, string>, to: array<int, string>, cc: array<int, string>, subject: array<int, string>, filename: ?string, has_attachment: string, is_read: string, is_flagged: string, folder: string, date: string}  $columns  SQL expressions (lower-case text) per filter
     */
    public function apply(EloquentBuilder|Builder $query, SearchQuery $search, SearchScope $scope, array $columns): void
    {
        foreach ($search->filters as $filter) {
            match ($filter->name) {
                SearchFilter::FROM, SearchFilter::TO, SearchFilter::CC, SearchFilter::SUBJECT => $this->like($query, $columns[$filter->name], (string) $filter->value, $filter->negated),
                SearchFilter::FILENAME => $columns['filename'] === null
                    ? $query->{$filter->negated ? 'whereDoesntHave' : 'whereHas'}('attachments', fn (EloquentBuilder $attachments) => $this->like($attachments, ['LOWER(filename)'], (string) $filter->value, false))
                    : $this->like($query, [$columns['filename']], (string) $filter->value, $filter->negated),
                SearchFilter::HAS_ATTACHMENT => $query->where($columns['has_attachment'], (bool) $filter->value),
                SearchFilter::IS_READ => $query->where($columns['is_read'], (bool) $filter->value),
                SearchFilter::IS_FLAGGED => $query->where($columns['is_flagged'], (bool) $filter->value),
                SearchFilter::IN_FOLDER => $this->folder($query, $columns['folder'], (string) $filter->value, $filter->negated, $scope),
                SearchFilter::BEFORE => $query->where($columns['date'], '<', $filter->value),
                SearchFilter::AFTER => $query->where($columns['date'], '>=', $filter->value),
                default => null,
            };
        }
    }

    /**
     * Case-insensitive substring match on one of the expressions.
     *
     * @param  array<int, string>  $expressions
     */
    public function like(EloquentBuilder|Builder $query, array $expressions, string $value, bool $negated): void
    {
        $pattern = static::pattern($value);

        $query->{$negated ? 'whereNot' : 'where'}(function ($query) use ($expressions, $pattern): void {
            foreach ($expressions as $expression) {
                $query->orWhereRaw("{$expression} LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    /**
     * "!" works as LIKE escape character on every supported database.
     */
    public static function pattern(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)).'%';
    }

    protected function folder(EloquentBuilder|Builder $query, string $column, string $value, bool $negated, SearchScope $scope): void
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

        $negated ? $query->whereNotIn($column, $ids) : $query->whereIn($column, $ids === [] ? [0] : $ids);
    }
}
