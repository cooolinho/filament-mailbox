<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Providers\Imap\ImapFolderMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImapFolderMapperTest extends TestCase
{
    /**
     * @return array<string, array{string, array<int, string>, ?SpecialUse}>
     */
    public static function specialUseProvider(): array
    {
        return [
            'inbox' => ['INBOX', ['\HasNoChildren'], SpecialUse::Inbox],
            'inbox lowercase' => ['inbox', [], SpecialUse::Inbox],
            'localized sent' => ['Gesendet', ['\HasNoChildren', '\Sent'], SpecialUse::Sent],
            'nested drafts' => ['INBOX.Drafts', ['\Drafts'], SpecialUse::Drafts],
            'localized trash' => ['Papierkorb', ['\Trash'], SpecialUse::Trash],
            'junk' => ['Spam', ['\Junk'], SpecialUse::Junk],
            'archive' => ['Archiv', ['\Archive'], SpecialUse::Archive],
            'all mail is no archive' => ['[Gmail]/All Mail', ['\All'], SpecialUse::All],
            'name alone is not enough' => ['Sent', ['\HasNoChildren'], null],
            'custom folder' => ['Customers/Acme', [], null],
        ];
    }

    /**
     * @param  array<int, string>  $flags
     */
    #[DataProvider('specialUseProvider')]
    public function test_special_use_is_detected_from_metadata(string $path, array $flags, ?SpecialUse $expected): void
    {
        $this->assertSame($expected, ImapFolderMapper::specialUse($path, $flags));
    }

    public function test_parent_is_derived_from_delimiter(): void
    {
        $this->assertSame('Customers', ImapFolderMapper::parent('Customers/Acme', '/'));
        $this->assertSame('INBOX.Projects', ImapFolderMapper::parent('INBOX.Projects.A', '.'));
        $this->assertNull(ImapFolderMapper::parent('INBOX', '/'));
        $this->assertNull(ImapFolderMapper::parent('Customers/Acme', null));
    }
}
