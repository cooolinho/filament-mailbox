<?php

namespace Cooolinho\FilamentMailbox\Statistics;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class StatisticsSettings
{
    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.statistics.enabled', false);
    }

    public static function slaMinutes(Mailbox $mailbox): int
    {
        return max(1, (int) ($mailbox->sla_minutes ?? config('filament-mailbox.statistics.sla_default_minutes', 240)));
    }

    /**
     * Mailboxes whose statistics the user may see: all for managers, assigned
     * ones for other users when "visible_to" is "assigned_users".
     *
     * @return array<int, int>|null null = none
     */
    public static function allowedMailboxIds(?Authenticatable $user): ?array
    {
        if (! $user || ! static::enabled()) {
            return null;
        }

        if (app(MailboxAuthorization::class)->canManage($user)) {
            return Mailbox::query()->orderBy('name')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        }

        if (config('filament-mailbox.statistics.visible_to', 'managers') !== 'assigned_users') {
            return null;
        }

        return DB::table('mailbox_user')->where('user_id', $user->getAuthIdentifier())->pluck('mailbox_id')->map(fn (mixed $id): int => (int) $id)->all();
    }
}
