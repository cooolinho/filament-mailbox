<div class="fi-mailbox-shortcuts-help">
    <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($shortcuts as $action => $bindings)
                <tr>
                    <td class="py-2 pe-4 text-gray-700 dark:text-gray-200">{{ __('filament-mailbox::mailbox.shortcuts.actions.'.$action) }}</td>
                    <td class="py-2 text-end">
                        @foreach ($bindings as $binding)
                            <kbd class="inline-block rounded border border-gray-300 bg-gray-50 px-1.5 py-0.5 font-mono text-xs dark:border-white/20 dark:bg-white/5">{{ \Cooolinho\FilamentMailbox\Support\ShortcutRegistry::label($binding) }}</kbd>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('filament-mailbox::mailbox.shortcuts.help_footer') }}</p>
</div>
