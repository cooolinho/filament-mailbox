<?php

namespace Cooolinho\FilamentMailbox\Http\Controllers;

use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\OAuth\OAuthFlow;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

class OAuthCallbackController
{
    public function __invoke(Request $request, string $provider, OAuthFlow $flow): RedirectResponse
    {
        abort_unless(Filament::auth()->check(), 403);

        $context = $flow->context($request, $provider);
        $returnUrl = $context['return_url'];

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->failed($returnUrl);
        }

        try {
            $connection = $flow->complete(
                OAuthApplication::findOrFail($context['application_id']),
                (string) $request->query('code'),
                $context['code_verifier'],
                ProviderType::tryFrom($context['provider'] ?? '') ?? ProviderType::Imap,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->failed($returnUrl);
        }

        // Reconnecting an existing mailbox saves the connection right away.
        if ($context['mailbox_id'] && ($mailbox = Mailbox::find($context['mailbox_id']))) {
            abort_unless(Gate::forUser(Filament::auth()->user())->allows('update', $mailbox), 403);

            $mailbox->forceFill([
                'auth_mode' => AuthMode::OAuth,
                'oauth_connection_id' => $connection->getKey(),
            ])->save();
        }

        Notification::make()
            ->success()
            ->title(__('filament-mailbox::mailbox.oauth.connected', ['account' => $connection->account_email]))
            ->send();

        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return redirect()->to($returnUrl.$separator.http_build_query([
            'oauth_connection' => $connection->getKey(),
            'provider' => $context['provider'] ?? ProviderType::Imap->value,
        ]));
    }

    protected function failed(string $returnUrl): RedirectResponse
    {
        Notification::make()
            ->danger()
            ->title(__('filament-mailbox::mailbox.oauth.failed'))
            ->send();

        return redirect()->to($returnUrl);
    }
}
