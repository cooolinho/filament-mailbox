<?php

namespace Cooolinho\FilamentMailbox\Filament\Pages;

use BackedEnum;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Pwa\Pwa;
use Cooolinho\FilamentMailbox\Services\NotificationPreferences;
use Cooolinho\FilamentMailbox\Services\UserPreferences;
use Cooolinho\FilamentMailbox\Support\FolderPath;
use Cooolinho\FilamentMailbox\Support\ShortcutRegistry;
use Cooolinho\FilamentMailbox\WebPush\WebPushSender;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Mailbox settings of the signed-in user (user menu): notifications and keyboard shortcuts.
 *
 * @property-read Schema $form
 */
class MailboxSettings extends Page
{
    protected static ?string $slug = 'mailbox-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (NotificationPreferences::enabled() || ShortcutRegistry::configured()) && Filament::auth()->check();
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-mailbox::mailbox.notifications.settings.title');
    }

    public function mount(NotificationPreferences $preferences): void
    {
        $user = Filament::auth()->user();

        $this->form->fill([
            'shortcuts' => ShortcutRegistry::enabled($user),
            'notifications' => $preferences->isEnabled($user),
            'show_content' => $preferences->showContent($user),
            'mailboxes' => $this->mailboxes()->mapWithKeys(function (Mailbox $mailbox) use ($preferences, $user): array {
                $assignment = $preferences->assignment($user, $mailbox);

                return ['m'.$mailbox->getKey() => [
                    'notify' => $assignment['notify'] ?? true,
                    'folders' => $assignment['folder_ids'] ?? [],
                ]];
            })->all(),
        ]);
    }

    /**
     * @return Collection<int, Mailbox>
     */
    protected function mailboxes(): Collection
    {
        return Mailbox::query()->assignedTo(Filament::auth()->user())->orderBy('name')->get();
    }

    public function form(Schema $schema): Schema
    {
        $mailboxSections = $this->mailboxes()->map(fn (Mailbox $mailbox): Section => Section::make($mailbox->name)
            ->description($mailbox->email)
            ->compact()
            ->columns(2)
            ->visible(fn (Get $get): bool => NotificationPreferences::enabled() && (bool) $get('notifications'))
            ->schema([
                Toggle::make("mailboxes.m{$mailbox->getKey()}.notify")
                    ->label(__('filament-mailbox::mailbox.notifications.settings.mailbox_notify'))
                    ->live(),
                Select::make("mailboxes.m{$mailbox->getKey()}.folders")
                    ->label(__('filament-mailbox::mailbox.notifications.settings.folders'))
                    ->placeholder(__('filament-mailbox::mailbox.notifications.settings.default_folders'))
                    ->multiple()
                    ->options(fn (): array => $mailbox->folders()
                        ->where('is_active', true)
                        ->orderBy('full_name')
                        ->get()
                        ->mapWithKeys(fn (MailboxFolder $folder): array => [$folder->getKey() => $folder->special_use?->getLabel() ?? FolderPath::decode($folder->full_name)])
                        ->all())
                    ->visible(fn (Get $get): bool => (bool) $get("mailboxes.m{$mailbox->getKey()}.notify")),
            ]))->all();

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('filament-mailbox::mailbox.notifications.settings.general'))
                    ->visible(fn (): bool => NotificationPreferences::enabled())
                    ->schema([
                        Toggle::make('notifications')
                            ->label(__('filament-mailbox::mailbox.notifications.settings.enabled'))
                            ->helperText(__('filament-mailbox::mailbox.notifications.settings.enabled_help'))
                            ->live(),
                        Toggle::make('show_content')
                            ->label(__('filament-mailbox::mailbox.notifications.settings.show_content'))
                            ->helperText(__('filament-mailbox::mailbox.notifications.settings.show_content_help'))
                            ->visible(fn (Get $get): bool => (bool) $get('notifications')),
                    ]),
                ...$mailboxSections,
                Section::make(__('filament-mailbox::mailbox.notifications.settings.device'))
                    ->description(__('filament-mailbox::mailbox.notifications.settings.device_help'))
                    ->visible(fn (): bool => NotificationPreferences::enabled() && ((bool) config('filament-mailbox.notifications.browser', true) || WebPushSender::enabled()))
                    ->schema([
                        View::make('filament-mailbox::notifications.device-settings')
                            ->viewData([
                                'browser' => (bool) config('filament-mailbox.notifications.browser', true),
                                'push' => WebPushSender::enabled() && Pwa::enabled(),
                                'publicKey' => (string) config('filament-mailbox.notifications.web_push.public_key'),
                                'subscribeUrl' => url(trim(Filament::getCurrentPanel()?->getPath() ?? '', '/').'/filament-mailbox/push-subscriptions'),
                            ]),
                    ]),
                Section::make(__('filament-mailbox::mailbox.shortcuts.settings.section'))
                    ->visible(fn (): bool => ShortcutRegistry::configured())
                    ->schema([
                        Toggle::make('shortcuts')
                            ->label(__('filament-mailbox::mailbox.shortcuts.settings.enabled'))
                            ->helperText(__('filament-mailbox::mailbox.shortcuts.settings.enabled_help')),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('filament-actions::edit.single.modal.actions.save.label'))
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(NotificationPreferences $preferences, UserPreferences $userPreferences): void
    {
        $data = $this->form->getState();
        $user = Filament::auth()->user();

        if (ShortcutRegistry::configured()) {
            $userPreferences->set($user, ShortcutRegistry::PREFERENCE, (bool) ($data['shortcuts'] ?? true));
        }

        if (! NotificationPreferences::enabled()) {
            Notification::make()
                ->success()
                ->title(__('filament-mailbox::mailbox.notifications.settings.saved'))
                ->send();

            return;
        }

        $userPreferences->set($user, 'notifications', (bool) ($data['notifications'] ?? false));
        $userPreferences->set($user, 'notifications_show_content', (bool) ($data['show_content'] ?? false));

        // Only assigned mailboxes; keys of other mailboxes in the state are ignored.
        foreach ($this->mailboxes() as $mailbox) {
            $settings = $data['mailboxes']['m'.$mailbox->getKey()] ?? null;

            if (! is_array($settings)) {
                continue;
            }

            $preferences->setMailbox(
                $user,
                $mailbox,
                (bool) ($settings['notify'] ?? true),
                array_map('intval', array_filter((array) ($settings['folders'] ?? []), 'is_numeric')) ?: null,
            );
        }

        Notification::make()
            ->success()
            ->title(__('filament-mailbox::mailbox.notifications.settings.saved'))
            ->send();
    }
}
