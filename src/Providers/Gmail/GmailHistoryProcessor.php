<?php

namespace Cooolinho\FilamentMailbox\Providers\Gmail;

/**
 * Reduces Gmail history records to the messages whose current state must be
 * loaded and the messages that were deleted.
 */
final class GmailHistoryProcessor
{
    /**
     * @param  array<int, array<string, mixed>>  $history  history records in chronological order
     * @return array{array<int, string>, array<int, string>} Changed and deleted message ids
     */
    public static function process(array $history): array
    {
        $state = [];

        foreach ($history as $record) {
            foreach (['messagesAdded' => 'changed', 'labelsAdded' => 'changed', 'labelsRemoved' => 'changed', 'messagesDeleted' => 'deleted'] as $type => $result) {
                foreach ($record[$type] ?? [] as $entry) {
                    $id = $entry['message']['id'] ?? null;

                    if (! is_string($id)) {
                        continue;
                    }

                    // A deletion is final; label changes afterwards (duplicates) are ignored.
                    if (($state[$id] ?? null) !== 'deleted') {
                        $state[$id] = $result;
                    }
                }
            }
        }

        return [
            array_map('strval', array_keys(array_filter($state, fn (string $result): bool => $result === 'changed'))),
            array_map('strval', array_keys(array_filter($state, fn (string $result): bool => $result === 'deleted'))),
        ];
    }
}
