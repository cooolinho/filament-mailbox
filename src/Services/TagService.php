<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Events\MessageTagsChanged;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxTag;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * App-only tags on messages. No provider is involved, so changes are local
 * and transactional.
 */
class TagService
{
    public const PIVOT_TABLE = 'mailbox_message_tag';

    /**
     * @return Collection<int, MailboxTag>
     */
    public function availableFor(Mailbox $mailbox): Collection
    {
        return MailboxTag::query()->visibleFor($mailbox)->ordered()->get();
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     *
     * @throws InvalidArgumentException when the tag belongs to another mailbox
     */
    public function attach(MailboxMessage|iterable $messages, MailboxTag $tag, ?Authenticatable $by = null): void
    {
        $messages = $this->collect($messages);

        $this->ensureAvailable($messages, $tag);

        DB::transaction(function () use ($messages, $tag, $by): void {
            foreach ($messages as $message) {
                $message->tags()->syncWithoutDetaching([$tag->getKey() => $this->pivot($message, $by)]);
            }
        });

        $this->dispatch($messages);
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     */
    public function detach(MailboxMessage|iterable $messages, MailboxTag $tag): void
    {
        $messages = $this->collect($messages);

        DB::table(self::PIVOT_TABLE)
            ->where('tag_id', $tag->getKey())
            ->whereIn('message_id', $messages->modelKeys())
            ->delete();

        $this->dispatch($messages);
    }

    /**
     * Replace the tags of a message.
     *
     * @param  iterable<MailboxTag>  $tags
     */
    public function sync(MailboxMessage $message, iterable $tags, ?Authenticatable $by = null): void
    {
        $tags = Collection::make($tags);

        $this->ensureAvailable(Collection::make([$message]), ...$tags->all());

        $existing = $message->tags()->pluck('mailbox_tags.id')->all();

        $message->tags()->sync($tags->mapWithKeys(fn (MailboxTag $tag): array => [
            // Keep who tagged a message originally.
            $tag->getKey() => in_array($tag->getKey(), $existing, true) ? [] : $this->pivot($message, $by),
        ])->all());

        $this->dispatch(Collection::make([$message]));
    }

    /**
     * Move the tag assignments of a deleted copy of the same message (same
     * stable key) to a newly imported message, e.g. after a UIDVALIDITY change.
     */
    public function reattach(MailboxMessage $message): int
    {
        $key = $this->messageKey($message);

        $previous = MailboxMessage::onlyTrashed()
            ->where('mailbox_id', $message->mailbox_id)
            ->whereKeyNot($message->getKey())
            ->whereIn('id', DB::table(self::PIVOT_TABLE)->where('message_key', $key)->select('message_id'))
            ->pluck('id');

        if ($previous->isEmpty()) {
            return 0;
        }

        $moved = DB::transaction(function () use ($message, $key, $previous): int {
            $existing = $message->tags()->pluck('mailbox_tags.id');

            // Tags the new message already has would violate the unique index.
            DB::table(self::PIVOT_TABLE)
                ->where('message_key', $key)
                ->whereIn('message_id', $previous)
                ->whereIn('tag_id', $existing)
                ->delete();

            $rows = DB::table(self::PIVOT_TABLE)
                ->where('message_key', $key)
                ->whereIn('message_id', $previous)
                ->orderBy('id')
                ->get(['id', 'tag_id']);

            // Several deleted copies may carry the same tag: keep one assignment per tag.
            $keep = $rows->unique('tag_id')->pluck('id');

            DB::table(self::PIVOT_TABLE)->whereIn('id', $rows->pluck('id')->diff($keep))->delete();

            return DB::table(self::PIVOT_TABLE)->whereIn('id', $keep)->update(['message_id' => $message->getKey()]);
        });

        if ($moved > 0) {
            $this->dispatch(Collection::make([$message]));
        }

        return $moved;
    }

    /**
     * Stable identity of a message across moves and re-imports: the Message-ID
     * header, or a heuristic of sender, date and subject without one.
     */
    public function messageKey(MailboxMessage $message): string
    {
        $parts = filled($message->message_id)
            ? [$message->mailbox_id, mb_strtolower(trim($message->message_id, '<> '))]
            : [$message->mailbox_id, mb_strtolower((string) $message->from_address), ($message->sent_at ?? $message->received_at)?->toIso8601String(), $message->subject];

        return sha1(implode('|', array_map('strval', $parts)));
    }

    /**
     * Remove assignments of messages deleted longer than the given number of days ago.
     */
    public function prune(int $days): int
    {
        return DB::table(self::PIVOT_TABLE)
            ->whereIn('message_id', MailboxMessage::onlyTrashed()->where('deleted_at', '<', now()->subDays($days))->select('id'))
            ->delete();
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    protected function ensureAvailable(Collection $messages, MailboxTag ...$tags): void
    {
        foreach ($tags as $tag) {
            foreach ($messages as $message) {
                if (! $tag->isAvailableFor($message->mailbox_id)) {
                    throw new InvalidArgumentException('The tag belongs to another mailbox.');
                }
            }
        }
    }

    /**
     * @return array{message_key: string, tagged_by: mixed}
     */
    protected function pivot(MailboxMessage $message, ?Authenticatable $by): array
    {
        return [
            'message_key' => $this->messageKey($message),
            'tagged_by' => $by?->getAuthIdentifier(),
        ];
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @return Collection<int, MailboxMessage>
     */
    protected function collect(MailboxMessage|iterable $messages): Collection
    {
        return Collection::make($messages instanceof MailboxMessage ? [$messages] : $messages);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    protected function dispatch(Collection $messages): void
    {
        foreach ($messages->groupBy('mailbox_id') as $group) {
            MessageTagsChanged::dispatch($group->first()->mailbox, $group->modelKeys());
        }
    }
}
