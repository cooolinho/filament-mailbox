<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\MailboxTags\Pages;

use Cooolinho\FilamentMailbox\Filament\Resources\MailboxTags\MailboxTagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ListMailboxTags extends ManageRecords
{
    protected static string $resource = MailboxTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
