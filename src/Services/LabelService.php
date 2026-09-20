<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Contracts\SupportsLabels;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Events\LabelsChanged;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Label operations: the server is updated first, then the local copy.
 */
class LabelService
{
    public function __construct(
        protected MailboxProviderFactory $providers,
        protected MessageService $messages,
        protected LabelSynchronizer $synchronizer,
    ) {}

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     */
    public function attach(MailboxMessage|iterable $messages, MailboxLabel $label): void
    {
        $this->change($messages, [$label], []);
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     */
    public function detach(MailboxMessage|iterable $messages, MailboxLabel $label): void
    {
        $this->change($messages, [], [$label]);
    }

    /**
     * Set exactly the given labels on a message.
     *
     * @param  iterable<MailboxLabel>  $labels
     */
    public function sync(MailboxMessage $message, iterable $labels): void
    {
        $wanted = Collection::make($labels)->keyBy('id');
        $current = $message->labels()->get()->keyBy('id');

        $this->change(
            $message,
            $wanted->diffKeys($current)->values()->all(),
            $current->diffKeys($wanted)->values()->all(),
        );
    }

    public function create(Mailbox $mailbox, string $name, ?LabelColor $color = null): MailboxLabel
    {
        return $this->withProvider($mailbox, function (SupportsLabels $provider) use ($mailbox, $name, $color): MailboxLabel {
            $existing = $mailbox->labels()->where('source', $provider->labelSource())->where('name', $name)->first();

            if ($existing) {
                return $existing;
            }

            $data = $provider->createLabel($name, $color);

            $label = $this->synchronizer->label($mailbox->getKey(), $data->source, $data->remoteKey, $name);
            $label->forceFill(['name' => $name, 'color' => $color])->save();

            return $label;
        });
    }

    public function update(MailboxLabel $label, string $name, ?LabelColor $color, bool $hidden = false): MailboxLabel
    {
        return $this->withProvider($label->mailbox, function (SupportsLabels $provider) use ($label, $name, $color, $hidden): MailboxLabel {
            $data = $label->name !== $name || $label->color !== $color
                ? $provider->renameLabel($label->toData(), $name, $color)
                : $label->toData();

            $label->forceFill([
                'remote_key' => $data->remoteKey,
                'name' => $name,
                'color' => $color,
                'is_hidden' => $hidden,
            ])->save();

            return $label;
        });
    }

    public function delete(MailboxLabel $label): void
    {
        $messages = $label->messages()->with('folder', 'mailbox')->get();

        if ($label->source === LabelSource::ImapKeyword && $messages->isNotEmpty()) {
            // Keywords have no server-side catalogue: remove them from the messages.
            $this->detach($messages, $label);
        }

        $this->withProvider($label->mailbox, fn (SupportsLabels $provider) => $provider->deleteLabel($label->toData()));

        $label->delete();
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @param  array<int, MailboxLabel>  $add
     * @param  array<int, MailboxLabel>  $remove
     */
    protected function change(MailboxMessage|iterable $messages, array $add, array $remove): void
    {
        if ($add === [] && $remove === []) {
            return;
        }

        $messages = Collection::make($messages instanceof MailboxMessage ? [$messages] : $messages)
            ->loadMissing('mailbox', 'folder');

        foreach ($messages->groupBy('mailbox_id') as $group) {
            $mailbox = $group->first()->mailbox;

            foreach ([...$add, ...$remove] as $label) {
                if ($label->mailbox_id !== $mailbox->getKey()) {
                    throw new InvalidArgumentException('Labels can only be assigned within their mailbox.');
                }
            }

            $this->withProvider($mailbox, function (SupportsLabels&MailboxProvider $provider) use ($group, $add, $remove, $mailbox): void {
                foreach ($group as $message) {
                    $identifier = $this->messages->identifier($message, $provider);
                    $addKeys = array_map(fn (MailboxLabel $label): string => $label->remote_key, $add);
                    $removeKeys = array_map(fn (MailboxLabel $label): string => $label->remote_key, $remove);

                    if ($identifier) {
                        $provider->changeLabels($identifier, $addKeys, $removeKeys);
                    }

                    $message->labels()->syncWithoutDetaching(array_map(fn (MailboxLabel $label): int => $label->getKey(), $add));
                    $message->labels()->detach(array_map(fn (MailboxLabel $label): int => $label->getKey(), $remove));

                    $keywords = array_values(array_diff(array_unique([...($message->keywords ?? []), ...$addKeys]), $removeKeys));
                    $message->forceFill(['keywords' => $keywords === [] ? null : $keywords])->save();
                }

                LabelsChanged::dispatch($mailbox, $group->modelKeys());
            });
        }
    }

    /**
     * @template T
     *
     * @param  callable(SupportsLabels&MailboxProvider): T  $callback
     * @return T
     */
    protected function withProvider(Mailbox $mailbox, callable $callback): mixed
    {
        $provider = $this->providers->make($mailbox);

        try {
            if (! $provider instanceof SupportsLabels
                || ! ($provider->supports(ProviderCapability::Labels) || $provider->supports(ProviderCapability::Keywords))) {
                throw UnsupportedOperation::for('labels', ProviderCapability::Labels);
            }

            return $callback($provider);
        } finally {
            $provider->disconnect();
        }
    }
}
