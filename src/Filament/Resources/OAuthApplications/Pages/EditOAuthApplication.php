<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\Pages;

use Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\OAuthApplicationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOAuthApplication extends EditRecord
{
    protected static string $resource = OAuthApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
