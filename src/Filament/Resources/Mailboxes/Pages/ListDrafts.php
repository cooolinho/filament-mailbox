<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\DraftActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Services\DraftService;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Drafts written in the app: the user's own, or all for users who may manage mailboxes.
 *
 * @property Mailbox $record
 */
class ListDrafts extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = MailboxResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(DraftService::enabled() && static::getResource()::canView($this->getRecord()) && Gate::allows('send', $this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-mailbox::mailbox.drafts.title', ['mailbox' => $this->getRecord()->name]);
    }

    public function getBreadcrumb(): ?string
    {
        return __('filament-mailbox::mailbox.drafts.plural_label');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public static function query(Mailbox $mailbox): Builder
    {
        $user = Filament::auth()->user();

        return $mailbox->drafts()
            ->getQuery()
            ->when(! $user || ! app(MailboxAuthorization::class)->canManage($user), fn (Builder $query) => $query->where('user_id', $user?->getAuthIdentifier() ?? 0));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::query($this->getRecord())->with('user')->withCount('attachments'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('subject')
                    ->label(__('filament-mailbox::mailbox.messages.fields.subject'))
                    ->placeholder(__('filament-mailbox::mailbox.messages.no_subject'))
                    ->description(fn (MailboxDraft $record): string => __('filament-mailbox::mailbox.drafts.modes.'.$record->mode))
                    ->searchable()
                    ->limit(80),
                TextColumn::make('to')
                    ->label(__('filament-mailbox::mailbox.messages.fields.to'))
                    ->state(fn (MailboxDraft $record): string => implode(', ', $record->to ?? []))
                    ->placeholder('—')
                    ->limit(60),
                TextColumn::make('user.name')
                    ->label(__('filament-mailbox::mailbox.drafts.fields.author'))
                    ->visible(fn (): bool => app(MailboxAuthorization::class)->canManage(Filament::auth()->user())),
                IconColumn::make('attachments_count')
                    ->label(__('filament-mailbox::mailbox.messages.fields.attachments'))
                    ->icon(fn (int $state): ?Heroicon => $state > 0 ? Heroicon::OutlinedPaperClip : null)
                    ->color('gray'),
                IconColumn::make('server_synced_at')
                    ->label(__('filament-mailbox::mailbox.drafts.fields.on_server'))
                    ->state(fn (MailboxDraft $record): bool => $record->isSynced())
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label(__('filament-mailbox::mailbox.drafts.fields.updated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordUrl(fn (MailboxDraft $record): ?string => Gate::allows('update', $record) ? DraftActions::url($record) : null)
            ->recordActions([
                Action::make('discard')
                    ->label(__('filament-mailbox::mailbox.drafts.actions.discard'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->authorize(fn (MailboxDraft $record): bool => Gate::allows('delete', $record))
                    ->requiresConfirmation()
                    ->modalDescription(__('filament-mailbox::mailbox.drafts.discard_description'))
                    ->action(function (MailboxDraft $record, DraftService $drafts): void {
                        $drafts->discard($record);

                        Notification::make()
                            ->success()
                            ->title(__('filament-mailbox::mailbox.drafts.discarded'))
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('filament-mailbox::mailbox.drafts.empty'));
    }
}
