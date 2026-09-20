<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Data\LabelData;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\ImapKeyword;

/**
 * Mirrors server-side labels into mailbox_labels and the message pivot.
 */
class LabelSynchronizer
{
    /**
     * Align the labels of a message with the given remote keys. Unknown keys
     * create labels. Returns whether the assignment changed.
     *
     * @param  array<int, string>  $remoteKeys
     */
    public function syncMessageLabels(MailboxMessage $message, LabelSource $source, array $remoteKeys): bool
    {
        $keys = array_values(array_unique(array_filter(
            $remoteKeys,
            fn (string $key): bool => $source !== LabelSource::ImapKeyword || ! ImapKeyword::isHidden($key),
        )));

        $ids = array_map(fn (string $key): int => $this->label($message->mailbox_id, $source, $key)->getKey(), $keys);

        // Labels of other sources are left untouched.
        $current = $message->labels()->where('source', $source)->pluck('mailbox_labels.id')->all();

        $attach = array_diff($ids, $current);
        $detach = array_diff($current, $ids);

        if ($attach === [] && $detach === []) {
            return false;
        }

        $message->labels()->attach($attach);
        $message->labels()->detach($detach);

        return true;
    }

    /**
     * Mirror the label catalogue. Labels that disappeared on the server and are
     * no longer assigned to any message are deleted.
     *
     * @param  array<int, LabelData>  $labels
     */
    public function syncCatalog(Mailbox $mailbox, LabelSource $source, array $labels): void
    {
        $keys = [];

        foreach ($labels as $data) {
            if ($source === LabelSource::ImapKeyword && ImapKeyword::isHidden($data->remoteKey)) {
                continue;
            }

            $label = $this->label($mailbox->getKey(), $source, $data->remoteKey, $data->name);

            // Server-side names win for providers with named labels; IMAP names are local.
            if ($source !== LabelSource::ImapKeyword) {
                $label->forceFill(['name' => $data->name, 'color' => $data->color ?? $label->color])->save();
            }

            $keys[] = $data->remoteKey;
        }

        $mailbox->labels()
            ->where('source', $source)
            ->whereNotIn('remote_key', $keys)
            ->whereDoesntHave('messages')
            ->delete();
    }

    public function label(int $mailboxId, LabelSource $source, string $remoteKey, ?string $name = null): MailboxLabel
    {
        return MailboxLabel::query()->firstOrCreate(
            ['mailbox_id' => $mailboxId, 'source' => $source, 'remote_key' => $remoteKey],
            ['name' => mb_substr($name ?? ($source === LabelSource::ImapKeyword ? ImapKeyword::toName($remoteKey) : $remoteKey), 0, 255)],
        );
    }
}
