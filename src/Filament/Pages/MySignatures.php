<?php

namespace Cooolinho\FilamentMailbox\Filament\Pages;

use BackedEnum;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\SignatureForm;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxSignature;
use Cooolinho\FilamentMailbox\Services\SignatureResolver;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Personal signatures of the signed-in user for the mailboxes assigned to them.
 */
class MySignatures extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'mailbox-signatures';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencil;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return SignatureResolver::personalEnabled() && Filament::auth()->check();
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-mailbox::mailbox.signatures.personal_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-mailbox::mailbox.signatures.personal_title');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->query(fn (): Builder => MailboxSignature::query()
                ->with('mailbox')
                ->personalFor($user)
                ->whereIn('mailbox_id', Mailbox::query()->assignedTo($user)->select('id')))
            ->defaultSort('name')
            ->columns(SignatureForm::columns(withMailbox: true))
            ->headerActions([
                CreateAction::make()
                    ->model(MailboxSignature::class)
                    ->modalWidth('4xl')
                    ->schema($this->formComponents())
                    // The owner is always the signed-in user.
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'user_id' => $user->getAuthIdentifier()]),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth('4xl')
                    ->schema($this->formComponents())
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'user_id' => $user->getAuthIdentifier()]),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('filament-mailbox::mailbox.signatures.empty'));
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected function formComponents(): array
    {
        $user = Filament::auth()->user();

        $components = SignatureForm::components(mailboxes: fn (): array => SignatureForm::assignedMailboxes($user));

        // Only assigned mailboxes are accepted, also with a manipulated state.
        $components[0]->rule(Rule::in(array_keys(SignatureForm::assignedMailboxes($user))));

        return $components;
    }
}
