<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\ComposeMessageAction;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\ForwardAction;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Models\MailboxDraftAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\DraftService;
use Cooolinho\FilamentMailbox\Services\ForwardBuilder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Editor for a draft with save, autosave, send and discard.
 *
 * @property Mailbox $record
 */
class EditDraft extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MailboxResource::class;

    protected string $view = 'filament-mailbox::pages.edit-draft';

    #[Locked]
    public int $draftId;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public ?string $savedHash = null;

    /** Required fields are only enforced when sending. */
    public bool $sending = false;

    protected ?MailboxDraft $cachedDraft = null;

    public function mount(int|string $record, int|string $draft): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(DraftService::enabled() && static::getResource()::canView($this->getRecord()), 403);

        $this->draftId = (int) $draft;

        abort_unless(Gate::allows('update', $this->getDraft()), 403);

        $this->form->fill(app(DraftService::class)->formState($this->getDraft()));
        $this->savedHash = $this->stateHash();
    }

    /**
     * Drafts are always resolved through the mailbox.
     */
    public function getDraft(): MailboxDraft
    {
        if ($this->cachedDraft?->getKey() === $this->draftId) {
            return $this->cachedDraft;
        }

        /** @var Mailbox $mailbox */
        $mailbox = $this->getRecord();

        return $this->cachedDraft = $mailbox->drafts()->with(['mailbox', 'replyTo.attachments'])->findOrFail($this->draftId);
    }

    public function isSavingDraft(): bool
    {
        return ! $this->sending;
    }

    public function getAutosaveSeconds(): int
    {
        return max(0, (int) config('filament-mailbox.drafts.autosave_seconds', 30));
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getDraft()->subject ?: __('filament-mailbox::mailbox.drafts.label');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('filament-mailbox::mailbox.drafts.saved_at', [
            'time' => $this->getDraft()->updated_at->toTimeString('minute'),
        ]);
    }

    public function getBreadcrumbs(): array
    {
        return [
            MailboxResource::getUrl() => MailboxResource::getBreadcrumb(),
            MailboxResource::getUrl('browse', ['record' => $this->getRecord()]) => $this->getRecord()->name,
            MailboxResource::getUrl('drafts', ['record' => $this->getRecord()]) => __('filament-mailbox::mailbox.drafts.plural_label'),
            __('filament-mailbox::mailbox.drafts.label'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $draft = $this->getDraft();
        $original = fn (): ?MailboxMessage => $this->getDraft()->replyTo;
        $directories = [$draft->directory()];

        $components = $draft->mode === MailboxDraft::MODE_FORWARD && $draft->replyTo
            ? ForwardAction::components(fn (): MailboxMessage => $this->getDraft()->replyTo, $directories)
            : ComposeMessageForm::components(
                quote: $draft->mode !== MailboxDraft::MODE_NEW || filled($draft->quoted) || filled($draft->quoted_html),
                mailbox: fn (): Mailbox => $this->getRecord(),
                context: $draft->context(),
                original: $draft->replyTo ? $original : null,
                imageDirectories: $directories,
            );

        return $schema
            ->statePath('data')
            ->components([
                ...$components,
                CheckboxList::make('draft_attachments')
                    ->label(__('filament-mailbox::mailbox.drafts.attachments'))
                    ->options(fn (): array => $this->getDraft()->attachments()->get()
                        ->mapWithKeys(fn (MailboxDraftAttachment $attachment): array => [
                            $attachment->getKey() => $attachment->filename.' ('.Number::fileSize($attachment->size).')',
                        ])
                        ->all())
                    ->columns(2)
                    ->visible(fn (): bool => $this->getDraft()->attachments()->exists()),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label(__('filament-mailbox::mailbox.actions.compose.submit'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->keyBindings(['mod+enter'])
                ->action(fn () => $this->send()),
            Action::make('save')
                ->label(__('filament-mailbox::mailbox.drafts.actions.save'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->keyBindings(['mod+s'])
                ->action(fn () => $this->save()),
            Action::make('discard')
                ->label(__('filament-mailbox::mailbox.drafts.actions.discard'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('filament-mailbox::mailbox.drafts.discard_description'))
                ->action(function (DraftService $drafts): void {
                    $drafts->discard($this->getDraft());

                    Notification::make()
                        ->success()
                        ->title(__('filament-mailbox::mailbox.drafts.discarded'))
                        ->send();

                    $this->redirect(MailboxResource::getUrl('drafts', ['record' => $this->getRecord()]));
                }),
        ];
    }

    public function save(bool $notify = true): void
    {
        abort_unless(Gate::allows('update', $this->getDraft()), 403);

        $state = $this->form->getState();
        $draft = app(DraftService::class)->save($this->getRecord(), $state, $this->getDraft(), Filament::auth()->user());

        $this->cachedDraft = null;

        // Uploads and template attachments are stored with the draft now.
        $this->data = [
            ...$this->data,
            'body_html' => $this->data['body_html'] ?? null,
            'attachments' => [],
            'template_attachments' => [],
            'attachments_template_id' => null,
            'draft_attachments' => $draft->attachments()->pluck('id')->all(),
        ];
        $this->savedHash = $this->stateHash();

        if ($notify) {
            Notification::make()
                ->success()
                ->title(__('filament-mailbox::mailbox.drafts.saved'))
                ->send();
        }
    }

    /**
     * Saves changes silently; incomplete or invalid input is kept for the next attempt.
     */
    public function autosave(): void
    {
        if ($this->getAutosaveSeconds() === 0 || $this->stateHash() === $this->savedHash) {
            return;
        }

        try {
            $this->save(notify: false);
        } catch (ValidationException) {
            $this->resetErrorBag();
        }
    }

    public function send(): void
    {
        abort_unless(Gate::allows('update', $this->getDraft()) && Gate::allows('send', $this->getRecord()), 403);

        $this->sending = true;

        try {
            $state = $this->form->getState();
        } finally {
            $this->sending = false;
        }

        $draft = $this->getDraft();
        $drafts = app(DraftService::class);

        try {
            $outgoing = $drafts->outgoing($draft, $state, Filament::auth()->user());

            if (app(ForwardBuilder::class)->exceedsSizeLimit($outgoing->attachments)) {
                Notification::make()
                    ->danger()
                    ->title(__('filament-mailbox::mailbox.forward.too_large', [
                        'size' => Number::fileSize((int) config('filament-mailbox.mail.max_attachment_size', 10240) * 1024),
                    ]))
                    ->send();

                return;
            }

        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title(__('filament-mailbox::mailbox.actions.compose.failure'))
                ->send();

            return;
        }

        $forward = $draft->mode === MailboxDraft::MODE_FORWARD ? $draft->replyTo : null;
        $sent = ComposeMessageAction::queue($this->getRecord(), $outgoing, $state, array_filter([
            'reply_to' => $forward ? null : $draft->replyTo,
            'forward_of' => $forward,
            'as_attachment' => $forward && ($state['as_attachment'] ?? false),
        ]));

        if (! $sent) {
            return;
        }

        $drafts->sent($draft, $state, $outgoing, markForwarded: false);

        $this->redirect(MailboxResource::getUrl('browse', ['record' => $this->getRecord()]));
    }

    protected function stateHash(): string
    {
        return md5((string) json_encode($this->normalize($this->data ?? [])));
    }

    protected function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof UploadedFile => 'upload:'.$value->getFilename(),
            is_array($value) => array_map($this->normalize(...), $value),
            is_object($value) => spl_object_id($value),
            default => $value,
        };
    }
}
