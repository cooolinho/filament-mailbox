<?php

namespace Cooolinho\FilamentMailbox\Contracts;

use Cooolinho\FilamentMailbox\Data\LabelData;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;

/**
 * Server-side labels (IMAP keywords, Gmail labels, Graph categories).
 *
 * During synchronisation the label keys of a message are transported in
 * MessageFlags::$keywords.
 */
interface SupportsLabels
{
    public function labelSource(): LabelSource;

    /**
     * The label catalogue known on the server.
     *
     * @return array<int, LabelData>
     */
    public function labels(): array;

    /**
     * @throws UnsupportedOperation when the server does not allow new labels
     */
    public function createLabel(string $name, ?LabelColor $color = null): LabelData;

    /**
     * Rename a label on the server. Providers whose label keys are not
     * renameable (IMAP keywords) return the label unchanged; the display name
     * is then only changed locally.
     */
    public function renameLabel(LabelData $label, string $name, ?LabelColor $color = null): LabelData;

    /**
     * Delete a label on the server. For IMAP keywords this is a no-op; the
     * keyword is removed from the messages instead.
     */
    public function deleteLabel(LabelData $label): void;

    /**
     * @param  array<int, string>  $add  Remote keys
     * @param  array<int, string>  $remove  Remote keys
     */
    public function changeLabels(MessageIdentifier $message, array $add, array $remove): void;
}
