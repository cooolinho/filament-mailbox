<?php

namespace Cooolinho\FilamentMailbox\Filament\Pages;

use BackedEnum;
use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Widgets\Statistics\HourlyChartWidget;
use Cooolinho\FilamentMailbox\Filament\Widgets\Statistics\ReplyTimeChartWidget;
use Cooolinho\FilamentMailbox\Filament\Widgets\Statistics\StatisticsOverviewWidget;
use Cooolinho\FilamentMailbox\Filament\Widgets\Statistics\TopDomainsWidget;
use Cooolinho\FilamentMailbox\Filament\Widgets\Statistics\UnansweredWidget;
use Cooolinho\FilamentMailbox\Filament\Widgets\Statistics\VolumeChartWidget;
use Cooolinho\FilamentMailbox\Filament\Widgets\Statistics\WeekdayChartWidget;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Statistics\StatisticsQuery;
use Cooolinho\FilamentMailbox\Statistics\StatisticsSettings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Mailbox statistics from daily aggregates: volume, busy hours, reply times,
 * SLA, unanswered messages and sender domains. Mailbox aggregates only, never
 * figures per person.
 */
class MailboxStatistics extends Page
{
    use HasFiltersForm;

    protected static ?string $slug = 'mailbox-statistics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public static function canAccess(): bool
    {
        return StatisticsSettings::allowedMailboxIds(Filament::auth()->user()) !== null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-mailbox::mailbox.statistics.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-mailbox::mailbox.statistics.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FilamentMailboxPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = FilamentMailboxPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 5;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['md' => 2, 'xl' => 4])
                ->schema([
                    Select::make('period')
                        ->label(__('filament-mailbox::mailbox.statistics.filters.period'))
                        ->options([
                            '7' => __('filament-mailbox::mailbox.statistics.filters.last_days', ['days' => 7]),
                            '30' => __('filament-mailbox::mailbox.statistics.filters.last_days', ['days' => 30]),
                            '90' => __('filament-mailbox::mailbox.statistics.filters.last_days', ['days' => 90]),
                            'custom' => __('filament-mailbox::mailbox.statistics.filters.custom'),
                        ])
                        ->default('30')
                        ->selectablePlaceholder(false),
                    DatePicker::make('from')
                        ->label(__('filament-mailbox::mailbox.statistics.filters.from'))
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->maxDate(now()),
                    DatePicker::make('until')
                        ->label(__('filament-mailbox::mailbox.statistics.filters.until'))
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->maxDate(now()),
                    Select::make('mailboxes')
                        ->label(__('filament-mailbox::mailbox.resource.plural_label'))
                        ->placeholder(__('filament-mailbox::mailbox.statistics.filters.all_mailboxes'))
                        ->multiple()
                        ->options(fn (): array => static::mailboxOptions())
                        ->columnSpan(['md' => 2, 'xl' => 1]),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Grid::make(['md' => 2])
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets())),
        ]);
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            StatisticsOverviewWidget::class,
            VolumeChartWidget::class,
            HourlyChartWidget::class,
            WeekdayChartWidget::class,
            ReplyTimeChartWidget::class,
            UnansweredWidget::class,
            TopDomainsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('filament-mailbox::mailbox.statistics.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->authorize(fn (): bool => static::canAccess())
                ->action(fn (): StreamedResponse => $this->export()),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function mailboxOptions(): array
    {
        return Mailbox::query()
            ->whereKey(StatisticsSettings::allowedMailboxIds(Filament::auth()->user()) ?? [])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Daily aggregates of the filtered, allowed mailboxes as CSV.
     */
    public function export(): StreamedResponse
    {
        $query = StatisticsQuery::fromFilters($this->filters, StatisticsSettings::allowedMailboxIds(Filament::auth()->user()));
        $columns = ['inbound_count', 'outbound_count', 'auto_generated_count', 'replied_count', 'reply_minutes_p50', 'reply_minutes_p90', 'reply_business_minutes_p50', 'reply_business_minutes_p90', 'sla_met_count', 'unique_sender_domains', 'attachment_bytes_in', 'attachment_bytes_out'];
        $filename = sprintf('mailbox-statistics-%s-%s.csv', $query->from->format('Ymd'), $query->until->format('Ymd'));

        return response()->streamDownload(function () use ($query, $columns): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'mailbox_id', 'mailbox', ...$columns, 'inbound_by_hour', 'outbound_by_hour'], escape: '');

            $query->daily()
                ->join('mailboxes', 'mailboxes.id', '=', 'mailbox_stats_daily.mailbox_id')
                ->orderBy('mailbox_stats_daily.date')
                ->orderBy('mailboxes.name')
                ->select(['mailbox_stats_daily.*', 'mailboxes.name as mailbox_name'])
                ->lazy(500)
                ->each(function (object $row) use ($out, $columns): void {
                    fputcsv($out, [
                        substr((string) $row->date, 0, 10),
                        $row->mailbox_id,
                        // Prevent formula injection in spreadsheet applications.
                        preg_replace('/^[=+\-@\t\r]/', "'$0", (string) $row->mailbox_name),
                        ...array_map(fn (string $column): mixed => $row->{$column}, $columns),
                        implode('|', (array) json_decode((string) $row->inbound_by_hour, true)),
                        implode('|', (array) json_decode((string) $row->outbound_by_hour, true)),
                    ], escape: '');
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
