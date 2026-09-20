<?php

namespace Cooolinho\FilamentMailbox\Support;

use Cooolinho\FilamentMailbox\Services\UserPreferences;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Keyboard shortcuts of the mailbox: bindings from the config, per-user switch.
 */
class ShortcutRegistry
{
    public const PREFERENCE = 'shortcuts_enabled';

    /** Actions and the pages they work on. */
    public const LIST_ACTIONS = ['compose', 'search', 'next', 'previous', 'open', 'archive', 'star', 'delete', 'mark_read', 'mark_unread', 'select', 'reply', 'reply_all', 'forward', 'go_inbox', 'help'];

    public const MESSAGE_ACTIONS = ['back', 'reply', 'reply_all', 'forward', 'archive', 'star', 'delete', 'mark_unread', 'go_inbox', 'help'];

    public static function configured(): bool
    {
        return (bool) config('filament-mailbox.shortcuts.enabled', true);
    }

    public static function enabled(?Authenticatable $user = null): bool
    {
        $user ??= Filament::auth()->user();

        return static::configured() && (bool) app(UserPreferences::class)->get($user, self::PREFERENCE, true);
    }

    /**
     * @return array<string, array<int, string>> Bindings keyed by action
     */
    public static function all(): array
    {
        return collect((array) config('filament-mailbox.shortcuts.bindings', []))
            ->map(fn (mixed $bindings): array => array_values(array_filter(array_map(
                fn (mixed $binding): string => strtolower(trim((string) $binding)),
                (array) $bindings,
            ))))
            ->filter()
            ->all();
    }

    /**
     * Action keyed by binding, for the script of a page.
     *
     * @param  array<int, string>  $actions
     * @return array<string, string>
     */
    public static function map(array $actions): array
    {
        $map = [];

        foreach (static::all() as $action => $bindings) {
            if (! in_array($action, $actions, true)) {
                continue;
            }

            foreach ($bindings as $binding) {
                $map[$binding] ??= $action;
            }
        }

        return $map;
    }

    /**
     * "shift+i" → "Shift + I", "g i" → "g, i".
     */
    public static function label(string $binding): string
    {
        return collect(explode(' ', $binding))
            ->map(fn (string $combo): string => collect(explode('+', $combo))
                ->map(fn (string $key): string => match ($key) {
                    'shift' => 'Shift',
                    'enter' => 'Enter',
                    'del' => __('filament-mailbox::mailbox.shortcuts.keys.del'),
                    default => strlen($key) === 1 && str_contains($combo, 'shift+') ? strtoupper($key) : $key,
                })
                ->implode(' + '))
            ->implode(', ');
    }
}
