<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\TestConnectionAction;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMailbox extends EditRecord
{
    protected static string $resource = MailboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TestConnectionAction::make(),
            BrowseMailbox::manageFoldersAction($this->getRecord()),
            DeleteAction::make(),
        ];
    }
}
