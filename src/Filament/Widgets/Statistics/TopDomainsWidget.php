<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets\Statistics;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TopDomainsWidget extends TableWidget
{
    use InteractsWithStatistics;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('filament-mailbox::mailbox.statistics.tables.top_domains'))
            ->records(fn (): array => $this->statisticsQuery()
                ->topDomains(max(1, (int) config('filament-mailbox.statistics.top_domains', 10)))
                ->mapWithKeys(fn (object $row): array => [$row->domain => ['domain' => $row->domain, 'count' => $row->count]])
                ->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('domain')->label(__('filament-mailbox::mailbox.statistics.fields.domain')),
                TextColumn::make('count')->label(__('filament-mailbox::mailbox.statistics.fields.messages'))->numeric()->alignEnd(),
            ])
            ->emptyStateHeading(__('filament-mailbox::mailbox.statistics.empty'));
    }
}
