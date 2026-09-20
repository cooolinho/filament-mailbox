<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProviderType: string implements HasLabel
{
    case Imap = 'imap';
    case Graph = 'graph';
    case Gmail = 'gmail';

    public function getLabel(): string
    {
        return match ($this) {
            self::Imap => 'IMAP',
            self::Graph => 'Microsoft Graph',
            self::Gmail => 'Gmail API',
        };
    }

    /**
     * Whether the mailbox connects to a host with IMAP settings.
     */
    public function usesServerSettings(): bool
    {
        return $this === self::Imap;
    }

    /**
     * API providers only work with OAuth.
     */
    public function requiresOAuth(): bool
    {
        return $this !== self::Imap;
    }

    /**
     * Static capabilities of the provider type, resolvable without connecting.
     *
     * @return array<int, ProviderCapability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Imap => [
                ProviderCapability::Folders,
                ProviderCapability::FolderManagement,
                ProviderCapability::FolderSubscriptions,
                ProviderCapability::MoveMessages,
                ProviderCapability::Flags,
                ProviderCapability::Flagged,
                ProviderCapability::Keywords,
                ProviderCapability::AppendMessages,
                ProviderCapability::PermanentDelete,
            ],
            self::Graph => [
                ProviderCapability::Folders,
                ProviderCapability::FolderManagement,
                ProviderCapability::MoveMessages,
                ProviderCapability::Flags,
                ProviderCapability::Flagged,
                ProviderCapability::Labels,
                ProviderCapability::ServerSideSend,
                ProviderCapability::PushNotifications,
                ProviderCapability::DeltaSync,
                ProviderCapability::PermanentDelete,
            ],
            self::Gmail => [
                ProviderCapability::Folders,
                ProviderCapability::MoveMessages,
                ProviderCapability::Flags,
                ProviderCapability::Flagged,
                ProviderCapability::Labels,
                ProviderCapability::ServerSideSend,
                ProviderCapability::PushNotifications,
                ProviderCapability::DeltaSync,
                ProviderCapability::MailboxWideSync,
            ],
        };
    }

    /**
     * The identity provider an OAuth application for this mailbox type must belong to.
     */
    public function oauthProvider(): ?OAuthProviderType
    {
        return match ($this) {
            self::Imap => null,
            self::Graph => OAuthProviderType::Microsoft,
            self::Gmail => OAuthProviderType::Google,
        };
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}
