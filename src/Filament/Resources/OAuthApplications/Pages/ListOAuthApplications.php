<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\Pages;

use Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\OAuthApplicationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOAuthApplications extends ListRecords
{
    protected static string $resource = OAuthApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
