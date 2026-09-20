<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\MailboxTemplates\Pages;

use Cooolinho\FilamentMailbox\Filament\Resources\MailboxTemplates\MailboxTemplateResource;
use Cooolinho\FilamentMailbox\Models\MailboxTemplate;
use Cooolinho\FilamentMailbox\Services\TemplateRepository;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ListMailboxTemplates extends ManageRecords
{
    protected static string $resource = MailboxTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalWidth('4xl')
                ->using(function (array $data, TemplateRepository $templates): MailboxTemplate {
                    $template = MailboxTemplate::create([
                        ...MailboxTemplateResource::attributes($data),
                        'created_by' => Filament::auth()->id(),
                    ]);

                    $templates->syncAttachments($template, (array) ($data['attachment_paths'] ?? []), (array) ($data['attachment_names'] ?? []));

                    return $template;
                }),
        ];
    }
}
