<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets\Statistics;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Statistics\LiveStatsProvider;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UnansweredWidget extends TableWidget
{
    use InteractsWithStatistics;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('mailbox')
                ->label(__('filament-mailbox::mailbox.resource.label'))
                ->url(fn (array $record): ?string => $record['url']),
        ];

        foreach (array_keys(LiveStatsProvider::BUCKETS) as $bucket) {
            $columns[] = TextColumn::make($bucket)
                ->label(__('filament-mailbox::mailbox.statistics.buckets.'.$bucket))
                ->numeric()
                ->alignEnd()
                ->color(fn (int $state): string => $state > 0 && $bucket === 'over_3d' ? 'danger' : 'gray');
        }

        return $table
            ->heading(__('filament-mailbox::mailbox.statistics.tables.unanswered'))
            ->records(fn (LiveStatsProvider $live): array => Mailbox::query()
                ->whereKey($this->statisticsQuery()->mailboxIds)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Mailbox $mailbox): array => [$mailbox->id => [
                    'mailbox' => $mailbox->name,
                    // Opens the inbox only for users assigned to the mailbox.
                    'url' => MailboxResource::canView($mailbox) && ($inbox = $mailbox->folderFor(SpecialUse::Inbox))
                        ? MailboxResource::getUrl('browse', ['record' => $mailbox, 'folder' => $inbox->id])
                        : null,
                    ...$live->get([$mailbox->id])['unanswered'],
                ]])
                ->all())
            ->paginated(false)
            ->columns($columns)
            ->emptyStateHeading(__('filament-mailbox::mailbox.statistics.empty'));
    }
}
