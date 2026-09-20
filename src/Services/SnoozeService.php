<?php

namespace Cooolinho\FilamentMailbox\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\MessagesSnoozed;
use Cooolinho\FilamentMailbox\Events\MessagesUnsnoozed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Notifications\SnoozedMessagesReturned;
use Cooolinho\FilamentMailbox\Support\CredentialRedactor;
use Cooolinho\FilamentMailbox\Support\RelativeDate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Snooze is state of the app: a snoozed message is hidden until its time
 * has come, then it returns unread and on top of the list. With
 * "snooze.server_folder" it is also moved to that folder on the server and
 * back, so other clients do not show it meanwhile.
 */
class SnoozeService
{
    public const MAX_DAYS = 366;

    public function __construct(
        protected MessageService $messages,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.snooze.enabled', true);
    }

    /**
     * @return array<string, string> Relative date expressions keyed by preset
     */
    public function presets(): array
    {
        return array_filter((array) config('filament-mailbox.snooze.presets', []), fn (mixed $expression): bool => is_string($expression) && $expression !== '');
    }

    /**
     * The time of a preset, calculated in the given time zone (the user's).
     */
    public function resolvePreset(string $preset, ?string $timezone = null): ?CarbonImmutable
    {
        $expression = $this->presets()[$preset] ?? null;

        if ($expression === null) {
            return null;
        }

        return RelativeDate::resolve($expression, $timezone);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validateUntil(CarbonInterface $until): void
    {
        if (! $until->isFuture() || $until->greaterThan(now()->addDays(self::MAX_DAYS))) {
            throw new InvalidArgumentException('The snooze time must be in the future and within one year.');
        }
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @return int Number of snoozed messages
     *
     * @throws InvalidArgumentException
     */
    public function snooze(MailboxMessage|iterable $messages, CarbonInterface $until, Authenticatable $by): int
    {
        $this->validateUntil($until);

        // Stored in the application time zone, like every other timestamp.
        $until = CarbonImmutable::instance($until)->setTimezone(config('app.timezone'))->startOfMinute();
        $records = $this->records($messages);

        foreach ($records->groupBy('mailbox_id') as $group) {
            $group = Collection::make($group);

            foreach ($group as $message) {
                $message->forceFill(['snoozed_until' => $until, 'snoozed_by' => $by->getAuthIdentifier()])->save();
            }

            $this->moveToServerFolder($group);

            MessagesSnoozed::dispatch($group->first()->mailbox, $group->modelKeys(), $until);
        }

        return $records->count();
    }

    /**
     * Cancel the snooze of messages. They keep their place in the list.
     *
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     */
    public function unsnooze(MailboxMessage|iterable $messages): int
    {
        $records = $this->records($messages)->filter(fn (MailboxMessage $message): bool => $message->snoozed_until !== null);

        foreach ($records->groupBy('mailbox_id') as $group) {
            $group = Collection::make($group);

            $this->moveBack($group);

            foreach ($group as $message) {
                $message->forceFill(['snoozed_until' => null, 'snoozed_by' => null])->save();
            }

            MessagesUnsnoozed::dispatch($group->first()->mailbox, $group->modelKeys());
        }

        return $records->count();
    }

    /**
     * Let messages return whose snooze time has come: visible, unread, on top
     * of the list, and the user who snoozed them is notified. Every message is
     * claimed with a conditional update, so parallel runs never wake it twice.
     *
     * @param  array<int, int>  $ids
     * @return int Number of woken messages
     */
    public function wake(array $ids): int
    {
        $now = now();

        $candidates = MailboxMessage::query()
            ->with(['mailbox', 'folder'])
            ->whereKey($ids)
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', $now)
            ->get();

        $woken = $candidates->filter(function (MailboxMessage $message) use ($now): bool {
            $claimed = MailboxMessage::query()
                ->whereKey($message->getKey())
                ->whereNotNull('snoozed_until')
                ->where('snoozed_until', '<=', $now)
                ->update(['snoozed_until' => null, 'unsnoozed_at' => $now, 'sort_at' => $now]) === 1;

            if ($claimed) {
                $message->forceFill(['snoozed_until' => null, 'unsnoozed_at' => $now, 'sort_at' => $now])->syncOriginal();
            }

            return $claimed;
        });

        foreach ($woken->groupBy('mailbox_id') as $group) {
            $group = Collection::make($group);
            $mailbox = $group->first()->mailbox;

            // Before moving back: the flag travels with the message.
            if (config('filament-mailbox.snooze.mark_unread_on_wake', true)) {
                $this->markUnread($mailbox, $group);
            }

            $this->moveBack($group);
            $this->notify($group);

            MailboxMessage::query()->whereKey($group->modelKeys())->update(['snoozed_by' => null]);

            MessagesUnsnoozed::dispatch($mailbox, $group->modelKeys(), woken: true);
        }

        return $woken->count();
    }

    /**
     * The server folder of the mailbox for snoozed messages, created when missing.
     */
    public function serverFolder(Mailbox $mailbox): ?MailboxFolder
    {
        $name = trim((string) config('filament-mailbox.snooze.server_folder'));

        if ($name === '' || ! $mailbox->supports(ProviderCapability::MoveMessages)) {
            return null;
        }

        $folder = $mailbox->folders()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->get()
            ->first(fn (MailboxFolder $folder): bool => mb_strtolower($folder->name) === mb_strtolower($name));

        if ($folder || ! app(FolderManager::class)->supports($mailbox)) {
            return $folder;
        }

        return app(FolderManager::class)->create($mailbox, $name);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $group  messages of one mailbox
     */
    protected function moveToServerFolder(Collection $group): void
    {
        $mailbox = $group->first()->mailbox;

        try {
            $folder = $this->serverFolder($mailbox);

            if (! $folder) {
                return;
            }

            $moving = $group->filter(fn (MailboxMessage $message): bool => $message->folder_id !== $folder->getKey() && $message->folder?->special_use !== SpecialUse::Drafts);

            foreach ($moving as $message) {
                $message->forceFill(['snoozed_from_folder_id' => $message->folder_id])->save();
            }

            $this->messages->move($moving, $folder);
        } catch (Throwable $exception) {
            // The snooze works locally anyway.
            $this->log('Could not move snoozed messages to the server folder.', $mailbox, $exception);

            foreach ($group as $message) {
                if ($message->snoozed_from_folder_id === $message->folder_id && ! $message->trashed()) {
                    $message->forceFill(['snoozed_from_folder_id' => null])->save();
                }
            }
        }
    }

    /**
     * Move messages of the server variant back to the folder they came from (or the inbox).
     *
     * @param  Collection<int, MailboxMessage>  $group  messages of one mailbox
     */
    protected function moveBack(Collection $group): void
    {
        $returning = $group->filter(fn (MailboxMessage $message): bool => $message->snoozed_from_folder_id !== null);

        if ($returning->isEmpty()) {
            return;
        }

        $mailbox = $group->first()->mailbox;

        foreach ($returning->groupBy('snoozed_from_folder_id') as $folderId => $messages) {
            $target = $mailbox->folders()->where('is_active', true)->find($folderId) ?? $mailbox->folderFor(SpecialUse::Inbox);

            try {
                if ($target) {
                    $this->messages->move(Collection::make($messages), $target);
                }
            } catch (Throwable $exception) {
                $this->log('Could not move snoozed messages back.', $mailbox, $exception);
            }
        }

        foreach ($returning as $message) {
            $message->forceFill(['snoozed_from_folder_id' => null])->save();
        }
    }

    /**
     * @param  Collection<int, MailboxMessage>  $group
     */
    protected function markUnread(Mailbox $mailbox, Collection $group): void
    {
        try {
            $this->messages->markUnread($group);
        } catch (Throwable $exception) {
            $this->log('Could not mark returned snoozed messages as unread on the server.', $mailbox, $exception);
        }

        // Unread in the app in any case.
        MailboxMessage::query()->whereKey($group->modelKeys())->update(['is_read' => false]);
        $group->each(fn (MailboxMessage $message) => $message->forceFill(['is_read' => false])->syncOriginal());
    }

    /**
     * @param  Collection<int, MailboxMessage>  $group  messages of one mailbox
     */
    protected function notify(Collection $group): void
    {
        $model = config('filament-mailbox.user_model');

        foreach ($group->whereNotNull('snoozed_by')->groupBy('snoozed_by') as $userId => $messages) {
            try {
                /** @var ?User $user */
                $user = class_exists($model) ? $model::query()->find($userId) : null;

                // Only while the user may still read the mailbox.
                $visible = $user ? Collection::make($messages)->filter(fn (MailboxMessage $message): bool => Gate::forUser($user)->allows('view', $message)) : Collection::make();

                if ($visible->isNotEmpty()) {
                    app(SnoozedMessagesReturned::class)->send($user, $visible);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @return Collection<int, MailboxMessage>
     */
    protected function records(MailboxMessage|iterable $messages): Collection
    {
        return Collection::make($messages instanceof MailboxMessage ? [$messages] : $messages)
            ->reject(fn (MailboxMessage $message): bool => $message->trashed())
            ->values()
            ->loadMissing('mailbox', 'folder');
    }

    protected function log(string $message, Mailbox $mailbox, Throwable $exception): void
    {
        Log::channel(config('filament-mailbox.sync.log_channel'))->warning($message, [
            'mailbox_id' => $mailbox->getKey(),
            'error' => CredentialRedactor::redact($exception->getMessage(), $mailbox),
        ]);
    }
}
