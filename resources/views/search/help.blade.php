<table class="w-full text-sm">
    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach (__('filament-mailbox::mailbox.search.operators') as [$example, $description])
            <tr>
                <td class="py-2 pe-4 align-top"><code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-white/10">{{ $example }}</code></td>
                <td class="py-2 text-gray-700 dark:text-gray-300">{{ $description }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
