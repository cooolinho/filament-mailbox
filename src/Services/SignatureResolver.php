<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxSignature;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Cooolinho\FilamentMailbox\Support\MailboxAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Signatures a user may use in a mailbox and the default per compose context:
 * the personal default, then the default of the mailbox.
 */
class SignatureResolver
{
    public function __construct(
        protected MailboxAccess $access,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.signatures.enabled', true);
    }

    public static function personalEnabled(): bool
    {
        return static::enabled() && config('filament-mailbox.signatures.personal', true);
    }

    /**
     * Personal signatures of the user first, then the signatures of the mailbox.
     *
     * @return Collection<int, MailboxSignature>
     */
    public function available(Mailbox $mailbox, ?Authenticatable $user): Collection
    {
        if (! static::enabled() || ! $user || ! $this->access->isAssigned($user, $mailbox->getKey())) {
            return new Collection;
        }

        return MailboxSignature::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->where(fn (Builder $query) => $query
                ->whereNull('user_id')
                ->when(static::personalEnabled(), fn (Builder $query) => $query->orWhere('user_id', $user->getAuthIdentifier())))
            ->orderByRaw('case when user_id is null then 1 else 0 end')
            ->orderBy('name')
            ->get();
    }

    public function find(Mailbox $mailbox, ?Authenticatable $user, mixed $id): ?MailboxSignature
    {
        if (blank($id) || ! is_scalar($id)) {
            return null;
        }

        return $this->available($mailbox, $user)->first(fn (MailboxSignature $signature): bool => (string) $signature->getKey() === (string) $id);
    }

    public function defaultFor(Mailbox $mailbox, ?Authenticatable $user, ComposeContext $context): ?MailboxSignature
    {
        $available = $this->available($mailbox, $user);

        return $available->first(fn (MailboxSignature $signature): bool => $signature->isPersonal() && $signature->isDefaultFor($context))
            ?? $available->first(fn (MailboxSignature $signature): bool => ! $signature->isPersonal() && $signature->isDefaultFor($context));
    }

    /**
     * Select options, grouped into personal and mailbox signatures.
     *
     * @return array<string, array<int|string, string>>
     */
    public function options(Mailbox $mailbox, ?Authenticatable $user): array
    {
        return $this->available($mailbox, $user)
            ->groupBy(fn (MailboxSignature $signature): string => __('filament-mailbox::mailbox.signatures.groups.'.($signature->isPersonal() ? 'personal' : 'mailbox')))
            ->map(fn (Collection $signatures): array => $signatures->pluck('name', 'id')->all())
            ->all();
    }
}
