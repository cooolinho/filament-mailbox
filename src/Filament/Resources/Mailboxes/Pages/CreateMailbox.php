<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\MailboxForm;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateMailbox extends CreateRecord
{
    protected static string $resource = MailboxResource::class;

    /**
     * After the OAuth callback the connected account prefills the form.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $connectionId = request()->query('oauth_connection');

        if (! is_numeric($connectionId)) {
            return;
        }

        // Only connections the current user created can be taken over.
        $connection = OAuthConnection::query()
            ->whereKey($connectionId)
            ->where('created_by', Filament::auth()->id())
            ->first();

        if ($connection) {
            $this->form->fill(MailboxForm::stateForConnection(
                $connection,
                $this->form->getRawState(),
                MailboxForm::providerType(request()->query('provider')),
            ));
        }
    }
}
