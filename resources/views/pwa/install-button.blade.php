<div
    x-data="{ installable: !! window.filamentMailboxPwa?.installPrompt }"
    x-on:filament-mailbox-pwa-installable.window="installable = true"
    x-on:filament-mailbox-pwa-installed.window="installable = false"
    x-show="installable"
    x-cloak
>
    <x-filament::icon-button
        :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDownTray"
        :label="__('filament-mailbox::mailbox.pwa.install')"
        :tooltip="__('filament-mailbox::mailbox.pwa.install')"
        color="gray"
        x-on:click="window.filamentMailboxPwa.install()"
    />
</div>
