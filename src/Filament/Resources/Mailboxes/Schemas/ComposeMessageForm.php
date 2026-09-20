<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Mail\HtmlMailRenderer;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxTemplateAttachment;
use Cooolinho\FilamentMailbox\Services\HtmlBodySanitizer;
use Cooolinho\FilamentMailbox\Services\InlineImageProcessor;
use Cooolinho\FilamentMailbox\Services\OutboxService;
use Cooolinho\FilamentMailbox\Services\Receipts\DeliveryReportService;
use Cooolinho\FilamentMailbox\Services\Receipts\ReadReceiptService;
use Cooolinho\FilamentMailbox\Services\SignatureRenderer;
use Cooolinho\FilamentMailbox\Services\SignatureResolver;
use Cooolinho\FilamentMailbox\Services\TemplateRenderer;
use Cooolinho\FilamentMailbox\Services\TemplateRepository;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Cooolinho\FilamentMailbox\Support\HtmlToText;
use Cooolinho\FilamentMailbox\Support\TemplateContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ComposeMessageForm
{
    public const FORMAT_HTML = 'html';

    public const FORMAT_TEXT = 'text';

    /**
     * @param  bool|Closure  $quote  Show the quoted original (replies and forwards)
     * @param  ?Closure(): Mailbox  $mailbox  The sending mailbox, for signatures and templates
     * @param  ?ComposeContext  $context  What the form is used for, for templates
     * @param  ?Closure(): MailboxMessage  $original  The replied or forwarded message, for template placeholders
     * @param  array<int, string>  $imageDirectories  Further directories images of the body may come from (drafts)
     * @return array<int, Component>
     */
    public static function components(bool $bodyRequired = true, bool|Closure $quote = false, ?Closure $mailbox = null, ?ComposeContext $context = null, ?Closure $original = null, array $imageDirectories = []): array
    {
        $templates = fn (): bool => $mailbox !== null && $context !== null && TemplateRepository::enabled();
        // Drafts may be saved incomplete.
        $required = fn (mixed $livewire): bool => ! static::isSavingDraft($livewire);

        return [
            Select::make('template_id')
                ->label(__('filament-mailbox::mailbox.templates.insert'))
                ->placeholder(__('filament-mailbox::mailbox.templates.insert_placeholder'))
                ->options(fn (): array => $templates() ? app(TemplateRepository::class)->options($mailbox(), $context) : [])
                ->searchable()
                ->live()
                // A command, not a value: the template is inserted and the select is cleared.
                ->dehydrated(false)
                ->afterStateUpdated(fn (Get $get, Set $set, mixed $state) => $templates()
                    ? static::applyTemplate($mailbox(), $context, $original ? $original() : null, $state, $get, $set)
                    : null)
                ->visible(fn (): bool => $templates() && app(TemplateRepository::class)->availableFor($mailbox(), $context)->isNotEmpty()),
            Hidden::make('template_ids'),
            Hidden::make('attachments_template_id'),
            static::recipients('to')->required($required),
            static::recipients('cc'),
            static::recipients('bcc'),
            TextInput::make('subject')
                ->label(__('filament-mailbox::mailbox.messages.fields.subject'))
                ->required($required)
                ->maxLength(255),
            ToggleButtons::make('format')
                ->label(__('filament-mailbox::mailbox.compose.format'))
                ->hiddenLabel()
                ->options([
                    self::FORMAT_HTML => __('filament-mailbox::mailbox.compose.formats.html'),
                    self::FORMAT_TEXT => __('filament-mailbox::mailbox.compose.formats.text'),
                ])
                ->icons([
                    self::FORMAT_HTML => Heroicon::OutlinedPaintBrush,
                    self::FORMAT_TEXT => Heroicon::OutlinedBars3BottomLeft,
                ])
                ->grouped()
                ->default(fn (): string => static::defaultFormat())
                ->required()
                ->in([self::FORMAT_HTML, self::FORMAT_TEXT])
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => static::switchFormat($get, $set, $state)),
            Textarea::make('body')
                ->label(__('filament-mailbox::mailbox.messages.fields.body'))
                ->required(fn (mixed $livewire): bool => $bodyRequired && $required($livewire))
                ->rows(12)
                ->maxLength(100_000)
                ->visible(fn (Get $get): bool => ! static::isHtml($get)),
            static::richEditor('body_html', attachments: true, allowedDirectories: $imageDirectories)
                ->label(__('filament-mailbox::mailbox.messages.fields.body'))
                ->required(fn (mixed $livewire): bool => $bodyRequired && $required($livewire))
                ->visible(fn (Get $get): bool => static::isHtml($get)),
            Select::make('signature_id')
                ->label(__('filament-mailbox::mailbox.signatures.label'))
                ->placeholder(__('filament-mailbox::mailbox.signatures.none'))
                // Options are resolved for the current user; the Select rejects every other id.
                ->options(fn (): array => $mailbox ? app(SignatureResolver::class)->options($mailbox(), Filament::auth()->user()) : [])
                ->live()
                ->visible(fn (): bool => $mailbox !== null && app(SignatureResolver::class)->available($mailbox(), Filament::auth()->user())->isNotEmpty()),
            Html::make(fn (Get $get): ?HtmlString => $mailbox ? static::signaturePreview($mailbox(), $get('signature_id'), $get('format')) : null)
                ->visible(fn (Get $get): bool => $mailbox !== null && filled($get('signature_id'))),
            Section::make(__('filament-mailbox::mailbox.compose.quoted'))
                ->collapsible()
                ->compact()
                ->visible($quote)
                ->schema([
                    Textarea::make('quoted')
                        ->hiddenLabel()
                        ->rows(8)
                        ->maxLength(1_000_000)
                        ->visible(fn (Get $get): bool => ! static::isHtml($get)),
                    static::richEditor('quoted_html', attachments: false, allowedDirectories: $imageDirectories)
                        ->hiddenLabel()
                        ->visible(fn (Get $get): bool => static::isHtml($get)),
                ]),
            CheckboxList::make('template_attachments')
                ->label(__('filament-mailbox::mailbox.templates.attachments'))
                ->options(fn (Get $get): array => $templates() ? static::templateAttachmentOptions($mailbox(), $context, $get('attachments_template_id')) : [])
                ->columns(2)
                ->visible(fn (Get $get): bool => $templates() && filled($get('attachments_template_id'))),
            FileUpload::make('attachments')
                ->label(__('filament-mailbox::mailbox.messages.fields.attachments'))
                ->multiple()
                ->storeFiles(false)
                ->maxFiles(10)
                ->maxSize((int) config('filament-mailbox.mail.max_attachment_size', 10240)),
            Actions::make([static::previewAction($mailbox)])
                ->key('preview')
                ->visible(fn (Get $get): bool => static::isHtml($get)),
            Grid::make(['default' => 1, 'sm' => 2])
                ->schema([
                    Select::make('send_at_preset')
                        ->label(__('filament-mailbox::mailbox.outbox.schedule.label'))
                        ->placeholder(__('filament-mailbox::mailbox.outbox.schedule.now'))
                        ->options(fn (): array => static::scheduleOptions())
                        ->live(),
                    DateTimePicker::make('send_at')
                        ->label(__('filament-mailbox::mailbox.outbox.schedule.custom'))
                        ->seconds(false)
                        ->timezone(fn (): string => FilamentTimezone::get())
                        ->minDate(fn (): CarbonInterface => now()->startOfMinute())
                        ->maxDate(fn (): CarbonInterface => now()->addDays(OutboxService::MAX_SCHEDULE_DAYS))
                        ->visible(fn (Get $get): bool => $get('send_at_preset') === 'custom')
                        ->required(fn (Get $get, mixed $livewire): bool => $get('send_at_preset') === 'custom' && $required($livewire)),
                ])
                ->visible(fn (): bool => OutboxService::schedulingEnabled()),
            Checkbox::make('request_read_receipt')
                ->label(__('filament-mailbox::mailbox.read_receipts.request'))
                ->helperText(__('filament-mailbox::mailbox.read_receipts.request_help'))
                ->visible(fn (): bool => ReadReceiptService::enabled()),
            Checkbox::make('request_delivery_receipt')
                ->label(__('filament-mailbox::mailbox.delivery_receipts.request'))
                ->helperText(__('filament-mailbox::mailbox.delivery_receipts.request_help'))
                ->visible(fn (): bool => DeliveryReportService::enabled()),
        ];
    }

    /**
     * "Send later" presets with their time in the panel time zone, and a custom time.
     *
     * @return array<string, string>
     */
    public static function scheduleOptions(): array
    {
        $outbox = app(OutboxService::class);
        $options = [];

        foreach (array_keys($outbox->presets()) as $preset) {
            if (! ($time = $outbox->resolvePreset($preset, FilamentTimezone::get())) || ! $time->isFuture()) {
                continue;
            }

            $key = "filament-mailbox::mailbox.outbox.schedule.presets.{$preset}";
            $options[$preset] = (Lang::has($key) ? __($key) : Str::headline($preset)).' ('.$time->translatedFormat('D, j. M, H:i').')';
        }

        return [...$options, 'custom' => __('filament-mailbox::mailbox.outbox.schedule.presets.custom')];
    }

    /**
     * The chosen send time in the application time zone, null to send now.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException for an unknown preset or a missing time
     */
    public static function sendAt(array $data): ?CarbonInterface
    {
        $preset = $data['send_at_preset'] ?? null;

        if (blank($preset) || ! OutboxService::schedulingEnabled()) {
            return null;
        }

        $time = match (true) {
            $preset !== 'custom' => app(OutboxService::class)->resolvePreset((string) $preset, FilamentTimezone::get()),
            // The picker converts the user's input from the panel time zone.
            filled($data['send_at'] ?? null) => CarbonImmutable::parse($data['send_at'], config('app.timezone')),
            default => null,
        };

        if ($time === null) {
            throw new InvalidArgumentException('Unknown send time.');
        }

        app(OutboxService::class)->validateSendAt($time);

        return $time;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $references
     * @param  array<int, AttachmentData>  $additionalAttachments
     */
    public static function toData(
        array $data,
        ?string $inReplyTo = null,
        array $references = [],
        ?string $providerThreadId = null,
        array $additionalAttachments = [],
        ?string $forwardedMessageId = null,
        ?Mailbox $mailbox = null,
        ?ComposeContext $context = null,
        array $imageDirectories = [],
        ?Authenticatable $user = null,
    ): OutgoingMessageData
    {
        $body = static::body($data, $mailbox, $imageDirectories, $user);
        // Only attachments of the inserted template, if it is available in the mailbox and context.
        $templateAttachments = $mailbox && $context
            ? app(TemplateRepository::class)->attachments($mailbox, $context, $data['attachments_template_id'] ?? null, array_values((array) ($data['template_attachments'] ?? [])))
            : [];

        return new OutgoingMessageData(
            headers: $mailbox && ReadReceiptService::enabled() && ($data['request_read_receipt'] ?? false) ? ReadReceiptService::requestHeaders($mailbox) : [],
            requestDeliveryReceipt: DeliveryReportService::enabled() && (bool) ($data['request_delivery_receipt'] ?? false),
            to: array_values($data['to'] ?? []),
            subject: (string) $data['subject'],
            body: $body['text'],
            cc: array_values($data['cc'] ?? []),
            bcc: array_values($data['bcc'] ?? []),
            attachments: [...$additionalAttachments, ...$templateAttachments, ...static::uploadedAttachments($data), ...$body['inline']],
            inReplyTo: $inReplyTo,
            references: $references,
            providerThreadId: $providerThreadId,
            forwardedMessageId: $forwardedMessageId,
            bodyHtml: $body['html'],
        );
    }

    /**
     * Count the templates used for a sent message.
     *
     * @param  array<string, mixed>  $data
     */
    public static function afterSent(array $data, Mailbox $mailbox, ComposeContext $context): void
    {
        app(TemplateRepository::class)->recordUsage($mailbox, $context, array_values((array) ($data['template_ids'] ?? [])), Filament::auth()->user());
    }

    /**
     * Insert a template: replaces an empty body or is appended, sets the subject
     * of new messages when it is empty and preselects the template attachments.
     */
    public static function applyTemplate(Mailbox $mailbox, ComposeContext $context, ?MailboxMessage $original, mixed $templateId, Get $get, Set $set): void
    {
        $set('template_id', null);

        $template = app(TemplateRepository::class)->find($mailbox, $context, $templateId);

        if (! $template) {
            return;
        }

        $renderer = app(TemplateRenderer::class);
        $variables = TemplateContext::for($mailbox, Filament::auth()->user(), $original, array_values((array) $get('to')), recipientIsSender: $context === ComposeContext::Reply);

        if (static::isHtml($get)) {
            $rendered = $renderer->html($template, $variables);
            $current = static::html($get('body_html'));
            $set('body_html', static::isEmptyHtml($current) ? $rendered->content : $current.$rendered->content);
        } else {
            $rendered = $renderer->text($template, $variables);
            $current = rtrim((string) $get('body'));
            $set('body', $current === '' ? $rendered->content : $current."\n\n".$rendered->content);
        }

        if ($context === ComposeContext::New && blank($get('subject')) && filled($template->subject)) {
            $set('subject', $renderer->subject($template, $variables));
        }

        if ($template->attachments()->exists()) {
            $set('attachments_template_id', $template->getKey());
            $set('template_attachments', $template->attachments()->pluck('id')->all());
        }

        $set('template_ids', array_values(array_unique([...(array) $get('template_ids'), $template->getKey()])));

        if ($rendered->unknown !== []) {
            Notification::make()
                ->warning()
                ->title(__('filament-mailbox::mailbox.templates.unknown_placeholders', [
                    'placeholders' => implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $rendered->unknown)),
                ]))
                ->send();
        }
    }

    /**
     * @return array<int|string, string>
     */
    public static function templateAttachmentOptions(Mailbox $mailbox, ComposeContext $context, mixed $templateId): array
    {
        return app(TemplateRepository::class)->find($mailbox, $context, $templateId)?->attachments
            ->mapWithKeys(fn (MailboxTemplateAttachment $attachment): array => [
                $attachment->getKey() => $attachment->filename.' ('.Number::fileSize($attachment->size).')',
            ])
            ->all() ?? [];
    }

    /**
     * Text and HTML body of the form state: body, signature (above the quote)
     * and quote. The HTML images become inline parts and the HTML is
     * sanitised; the text alternative is generated from it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $imageDirectories  Further directories images may come from
     * @param  ?Authenticatable  $user  The author (default: the signed-in user), for signatures and uploads
     * @return array{text: string, html: ?string, inline: array<int, AttachmentData>}
     */
    public static function body(array $data, ?Mailbox $mailbox = null, array $imageDirectories = [], ?Authenticatable $user = null): array
    {
        $user ??= Filament::auth()->user();
        // The id is checked again: only signatures available to the user in this mailbox are used.
        $signature = $mailbox ? app(SignatureResolver::class)->find($mailbox, $user, $data['signature_id'] ?? null) : null;
        $signatures = app(SignatureRenderer::class);

        if (! static::isHtmlFormat($data['format'] ?? null)) {
            return [
                'text' => implode("\n\n", array_filter([
                    rtrim((string) ($data['body'] ?? '')),
                    $signature && $mailbox ? $signatures->textBlock($signature, $mailbox, $user) : null,
                    trim((string) ($data['quoted'] ?? ''), "\n"),
                ], filled(...))),
                'html' => null,
                'inline' => [],
            ];
        }

        $images = app(InlineImageProcessor::class)->process(
            static::joinHtml([
                static::html($data['body_html'] ?? null),
                $signature && $mailbox ? $signatures->htmlBlock($signature, $mailbox, $user) : null,
                static::html($data['quoted_html'] ?? null),
            ]),
            [InlineImageProcessor::composeDirectory($user?->getAuthIdentifier()), ...static::inlineImageDirectories($imageDirectories)],
        );

        $html = app(HtmlBodySanitizer::class)->outgoing($images->html);
        $text = HtmlToText::readable($html);

        return [
            'text' => $text,
            'html' => filled($text) || str_contains($html, '<img') ? $html : null,
            'inline' => $images->attachments,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, AttachmentData>
     */
    public static function uploadedAttachments(array $data): array
    {
        return array_map(
            fn (TemporaryUploadedFile $file): AttachmentData => new AttachmentData(
                filename: $file->getClientOriginalName(),
                mimeType: $file->getMimeType() ?: 'application/octet-stream',
                contents: (string) $file->get(),
            ),
            array_values(array_filter($data['attachments'] ?? [], fn ($file) => $file instanceof TemporaryUploadedFile)),
        );
    }

    /**
     * Initial form state of a compose context: format and default signature.
     *
     * @return array{format: string, signature_id: int|null}
     */
    public static function defaults(Mailbox $mailbox, ComposeContext $context): array
    {
        return [
            'format' => static::defaultFormat($mailbox),
            'request_read_receipt' => ReadReceiptService::requestByDefault($mailbox),
            'request_delivery_receipt' => DeliveryReportService::requestSuccessByDefault(),
            'signature_id' => app(SignatureResolver::class)->defaultFor($mailbox, Filament::auth()->user(), $context)?->getKey(),
        ];
    }

    public static function defaultFormat(?Mailbox $mailbox = null): string
    {
        $format = $mailbox?->compose_format ?: config('filament-mailbox.compose.default_format', self::FORMAT_HTML);

        return $format === self::FORMAT_TEXT ? self::FORMAT_TEXT : self::FORMAT_HTML;
    }

    public static function isHtmlFormat(mixed $format): bool
    {
        return $format === self::FORMAT_HTML;
    }

    /**
     * Directories on the attachments disk images of the HTML body may come from.
     *
     * @param  array<int, string>  $extra
     * @return array<int, string>
     */
    public static function inlineImageDirectories(array $extra = []): array
    {
        return [
            InlineImageProcessor::composeDirectory(Filament::auth()->id()),
            InlineImageProcessor::directory('signatures'),
            InlineImageProcessor::directory('templates'),
            ...$extra,
        ];
    }

    /**
     * Whether the form is submitted to save a draft: required fields are not enforced then.
     */
    public static function isSavingDraft(mixed $livewire): bool
    {
        if (is_object($livewire) && method_exists($livewire, 'isSavingDraft')) {
            return (bool) $livewire->isSavingDraft();
        }

        if (is_object($livewire) && method_exists($livewire, 'getMountedAction')) {
            return (bool) ($livewire->getMountedAction()?->getArguments()['draft'] ?? false);
        }

        return false;
    }

    /**
     * Plain text as HTML paragraphs.
     */
    public static function textToHtml(?string $text): string
    {
        $paragraphs = preg_split("/\R{2,}/u", trim((string) $text)) ?: [];

        return implode('', array_map(
            fn (string $paragraph): string => '<p>'.preg_replace('/\R/u', '<br>', e($paragraph)).'</p>',
            array_filter($paragraphs, filled(...)),
        ));
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    public static function joinHtml(array $parts): string
    {
        return implode('', array_filter($parts, fn (?string $part): bool => filled($part) && ! static::isEmptyHtml($part)));
    }

    public static function isEmptyHtml(?string $html): bool
    {
        return blank(trim(strip_tags((string) $html, '<img><hr><table>')));
    }

    /**
     * Rich editor state as HTML (the raw state of an editor is a TipTap document).
     */
    public static function html(mixed $state): ?string
    {
        if (is_array($state)) {
            return RichContentRenderer::make($state)->toUnsafeHtml();
        }

        return is_string($state) ? $state : null;
    }

    public static function signaturePreview(Mailbox $mailbox, mixed $signatureId, mixed $format): ?HtmlString
    {
        $user = Filament::auth()->user();
        $signature = app(SignatureResolver::class)->find($mailbox, $user, $signatureId);

        if (! $signature) {
            return null;
        }

        $renderer = app(SignatureRenderer::class);
        $style = 'padding: 0.5rem 0.75rem; border: 1px dashed rgb(209 213 219); border-radius: 0.5rem; font-size: 0.875rem;';

        if (static::isHtmlFormat($format)) {
            // Images are embedded when sending only.
            return new HtmlString('<div style="'.$style.'">'.app(HtmlBodySanitizer::class)->outgoing($renderer->html($signature, $mailbox, $user)).'</div>');
        }

        return new HtmlString('<div style="'.$style.' white-space: pre-wrap;">'.e($renderer->textBlock($signature, $mailbox, $user)).'</div>');
    }

    protected static function isHtml(Get $get): bool
    {
        return static::isHtmlFormat($get('format'));
    }

    protected static function switchFormat(Get $get, Set $set, ?string $format): void
    {
        if (static::isHtmlFormat($format)) {
            $set('body_html', static::textToHtml($get('body')) ?: null);
            $set('quoted_html', static::textToHtml($get('quoted')) ?: null);

            return;
        }

        $set('body', HtmlToText::readable(static::html($get('body_html'))));
        $set('quoted', HtmlToText::readable(static::html($get('quoted_html'))));
    }

    /**
     * @param  ?string  $directory  Upload directory on the attachments disk (default: the user's compose directory)
     * @param  array<int, string>  $allowedDirectories  Further directories stored images may come from
     */
    public static function richEditor(string $name, bool $attachments, ?string $directory = null, array $allowedDirectories = []): RichEditor
    {
        $attachments = $attachments && config('filament-mailbox.compose.inline_images', true);

        return RichEditor::make($name)
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'strike', 'link'],
                ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                $attachments ? ['table', 'attachFiles'] : ['table'],
                ['undo', 'redo', 'clearFormatting'],
            ])
            ->linkProtocols(['http', 'https', 'mailto'])
            ->fileAttachments($attachments)
            ->fileAttachmentsDisk(fn (): string => (string) config('filament-mailbox.attachments.disk'))
            ->fileAttachmentsDirectory(fn (): string => $directory ?? InlineImageProcessor::composeDirectory(Filament::auth()->id()))
            ->fileAttachmentsVisibility('private')
            ->fileAttachmentsMaxSize((int) config('filament-mailbox.compose.max_inline_image_size', 2048))
            // Images kept from a stored state (drafts, templates) must come from allowed directories.
            ->preventFileAttachmentPathTampering(allowFilePathUsing: fn (string $file): bool => InlineImageProcessor::isAllowedPath($file, static::inlineImageDirectories($allowedDirectories)))
            ->extraInputAttributes(['style' => 'min-height: 12rem']);
    }

    /**
     * @param  ?Closure(): Mailbox  $mailbox
     */
    protected static function previewAction(?Closure $mailbox = null): Action
    {
        return Action::make('preview')
            ->label(__('filament-mailbox::mailbox.compose.preview'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->link()
            ->modalHeading(__('filament-mailbox::mailbox.compose.preview'))
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament-mailbox::mailbox.compose.close'))
            ->modalContent(fn (Get $get): View => view('filament-mailbox::compose.preview', [
                'document' => static::previewDocument([
                    'format' => $get('format'),
                    'body_html' => $get('body_html'),
                    'signature_id' => $get('signature_id'),
                    'quoted_html' => $get('quoted_html'),
                ], $mailbox ? $mailbox() : null),
            ]));
    }

    /**
     * The composed HTML as sandboxed iframe document, images as data URIs.
     *
     * @param  array<string, mixed>  $data
     */
    public static function previewDocument(array $data, ?Mailbox $mailbox = null): string
    {
        $body = static::body([...$data, 'format' => self::FORMAT_HTML], $mailbox);
        $html = app(HtmlMailRenderer::class)->render((string) $body['html']);

        foreach ($body['inline'] as $image) {
            $html = str_replace('cid:'.$image->contentId, 'data:'.$image->mimeType.';base64,'.base64_encode($image->contents), $html);
        }

        return app(HtmlBodySanitizer::class)->document($html);
    }

    protected static function recipients(string $name): TagsInput
    {
        return TagsInput::make($name)
            ->label(__("filament-mailbox::mailbox.messages.fields.{$name}"))
            ->placeholder(__('filament-mailbox::mailbox.compose.recipients_placeholder'))
            ->splitKeys(['Tab', ',', ';', ' '])
            ->nestedRecursiveRules(['email:rfc', 'max:255']);
    }
}
