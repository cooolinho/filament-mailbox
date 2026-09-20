<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxSignature;
use Cooolinho\FilamentMailbox\Support\HtmlToText;
use Cooolinho\FilamentMailbox\Support\PlaceholderRenderer;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Renders a signature with its placeholders ({user.name}, {user.email},
 * {mailbox.name}, {mailbox.email}) as text or HTML.
 */
class SignatureRenderer
{
    public const PLACEHOLDERS = ['user.name', 'user.email', 'mailbox.name', 'mailbox.email'];

    public function text(MailboxSignature $signature, Mailbox $mailbox, ?Authenticatable $user): string
    {
        $text = filled($signature->body_text) ? (string) $signature->body_text : HtmlToText::readable($signature->body_html);

        return trim(PlaceholderRenderer::render($text, $this->variables($mailbox, $user))->content, "\n");
    }

    /**
     * The HTML variant, or the text as paragraphs when the signature has none.
     * Images keep their "data-id" and are embedded when sending.
     */
    public function html(MailboxSignature $signature, Mailbox $mailbox, ?Authenticatable $user): string
    {
        if (blank($signature->body_html)) {
            return ComposeMessageForm::textToHtml($this->text($signature, $mailbox, $user));
        }

        return PlaceholderRenderer::render($signature->body_html, $this->variables($mailbox, $user), html: true)->content;
    }

    /**
     * The signature block appended to a text body, with the RFC 3676 separator "-- ".
     */
    public function textBlock(MailboxSignature $signature, Mailbox $mailbox, ?Authenticatable $user): string
    {
        return config('filament-mailbox.signatures.separator', "-- \n").$this->text($signature, $mailbox, $user);
    }

    public function htmlBlock(MailboxSignature $signature, Mailbox $mailbox, ?Authenticatable $user): string
    {
        return '<div data-signature="1">'.$this->html($signature, $mailbox, $user).'</div>';
    }

    /**
     * @return array<string, string>
     */
    public function variables(Mailbox $mailbox, ?Authenticatable $user): array
    {
        return [
            'user.name' => (string) data_get($user, 'name'),
            'user.email' => (string) data_get($user, 'email'),
            'mailbox.name' => (string) $mailbox->name,
            'mailbox.email' => (string) $mailbox->email,
        ];
    }
}
