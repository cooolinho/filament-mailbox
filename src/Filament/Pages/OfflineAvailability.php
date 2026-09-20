<?php

namespace Cooolinho\FilamentMailbox\Filament\Pages;

use BackedEnum;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxOfflineDevice;
use Cooolinho\FilamentMailbox\Pwa\Pwa;
use Cooolinho\FilamentMailbox\Services\Offline\OfflineDeviceService;
use Cooolinho\FilamentMailbox\Support\FolderPath;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Opt-in per device for an encrypted offline copy of selected folders (user menu).
 *
 * @property-read Schema $form
 */
class OfflineAvailability extends Page
{
    protected static ?string $slug = 'mailbox-offline';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowDown;

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return OfflineDeviceService::enabled() && Pwa::enabled() && Filament::auth()->check();
    }

    /**
     * Identifies the user of a local copy without exposing the id.
     */
    public static function userHash(Authenticatable $user): string
    {
        return substr(hash_hmac('sha256', 'filament-mailbox-offline:'.$user->getAuthIdentifier(), (string) config('app.key')), 0, 32);
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-mailbox::mailbox.offline.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'folder_ids' => [],
            'days' => min(14, OfflineDeviceService::maxDays()),
            'max_messages' => min(200, OfflineDeviceService::maxMessages()),
            'attachments' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('filament-mailbox::mailbox.offline.selection'))
                    ->description(__('filament-mailbox::mailbox.offline.privacy'))
                    ->columns(3)
                    ->schema([
                        Select::make('folder_ids')
                            ->label(__('filament-mailbox::mailbox.offline.fields.folders'))
                            ->multiple()
                            ->required()
                            ->options(fn (): array => $this->folderOptions())
                            ->columnSpanFull(),
                        TextInput::make('days')
                            ->label(__('filament-mailbox::mailbox.offline.fields.days'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(OfflineDeviceService::maxDays())
                            ->required(),
                        TextInput::make('max_messages')
                            ->label(__('filament-mailbox::mailbox.offline.fields.max_messages'))
                            ->helperText(__('filament-mailbox::mailbox.offline.fields.per_folder'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(OfflineDeviceService::maxMessages())
                            ->required(),
                        Toggle::make('attachments')
                            ->label(__('filament-mailbox::mailbox.offline.fields.attachments', ['size' => OfflineDeviceService::maxAttachmentKilobytes() / 1024]))
                            ->visible(fn (): bool => OfflineDeviceService::maxAttachmentKilobytes() > 0)
                            ->inline(false),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            View::make('filament-mailbox::offline.device')
                ->viewData(fn (): array => [
                    'appUrl' => Pwa::url(Filament::getCurrentPanel(), 'filament-mailbox/offline/app'),
                    'user' => static::userHash(Filament::auth()->user()),
                ]),
            Section::make(__('filament-mailbox::mailbox.offline.devices'))
                ->schema([
                    View::make('filament-mailbox::offline.devices')
                        ->viewData(fn (): array => ['devices' => $this->devices()]),
                ]),
        ]);
    }

    /**
     * Opt-in on this device; the browser keeps the returned id.
     *
     * @return array{uuid: string, user: string}
     */
    public function enableDevice(?string $label, OfflineDeviceService $devices): array
    {
        $data = $this->form->getState();
        $user = Filament::auth()->user();
        $device = $devices->register($user, $data, $label);

        Notification::make()->success()->title(__('filament-mailbox::mailbox.offline.enabled'))->send();

        return ['uuid' => $device->device_uuid, 'user' => static::userHash($user)];
    }

    public function saveSelection(?string $uuid, OfflineDeviceService $devices): void
    {
        $data = $this->form->getState();
        $user = Filament::auth()->user();
        $device = $devices->find($user, $uuid);

        if (! $device || $device->isRevoked()) {
            return;
        }

        $devices->updateSelection($device, $user, $data);

        Notification::make()->success()->title(__('filament-mailbox::mailbox.offline.saved'))->send();
    }

    /**
     * Opt-out on this device: the browser deletes its copy.
     */
    public function disableDevice(?string $uuid, OfflineDeviceService $devices): void
    {
        if ($device = $devices->find(Filament::auth()->user(), $uuid)) {
            $devices->revoke($device);
        }

        Notification::make()->success()->title(__('filament-mailbox::mailbox.offline.disabled'))->send();
    }

    /**
     * Revoke another device: its copy is deleted on its next contact with the server.
     */
    public function revokeDevice(int $id, OfflineDeviceService $devices): void
    {
        $device = MailboxOfflineDevice::query()->find($id);

        if (! $device || ! $device->isOwnedBy(Filament::auth()->user())) {
            return;
        }

        $devices->revoke($device);

        Notification::make()->success()->title(__('filament-mailbox::mailbox.offline.revoked'))->send();
    }

    /**
     * Selection of the device for the form (the id comes from the browser).
     *
     * @return ?array<string, mixed>
     */
    public function loadDevice(?string $uuid, OfflineDeviceService $devices): ?array
    {
        $device = $devices->find(Filament::auth()->user(), $uuid);

        if (! $device || $device->isRevoked()) {
            return null;
        }

        $this->form->fill($device->selection);

        return ['uuid' => $device->device_uuid];
    }

    /**
     * @return Collection<int, MailboxOfflineDevice>
     */
    protected function devices(): Collection
    {
        return MailboxOfflineDevice::query()
            ->where('user_id', Filament::auth()->id())
            ->whereNull('revoked_at')
            ->latest()
            ->get();
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function folderOptions(): array
    {
        return Mailbox::query()
            ->active()
            ->assignedTo(Filament::auth()->user())
            ->with(['folders' => fn ($folders) => $folders->where('is_active', true)->orderBy('full_name')])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Mailbox $mailbox): array => [$mailbox->name => $mailbox->folders
                ->mapWithKeys(fn (MailboxFolder $folder): array => [$folder->getKey() => $folder->special_use?->getLabel() ?? FolderPath::decode($folder->full_name)])
                ->all()])
            ->filter()
            ->all();
    }
}
