<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Support\FolderPath;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use InvalidArgumentException;

class FolderPathTest extends TestCase
{
    public function test_names_are_encoded_as_imap_utf7(): void
    {
        $this->assertSame('Entw&APw-rfe', FolderPath::imap('Entwürfe', null, '/'));
        $this->assertSame('Projekte/Kunde A&-B', FolderPath::imap('Kunde A&B', 'Projekte', '/'));
        $this->assertSame('INBOX.Rechnungen', FolderPath::imap('Rechnungen', null, '.', 'INBOX.'));
        $this->assertSame('Entwürfe', FolderPath::decode('Entw&APw-rfe'));
    }

    public function test_names_are_validated(): void
    {
        $this->assertNull(FolderPath::nameError('Kunden 2026', '/'));
        $this->assertSame('name_required', FolderPath::nameError('  ', '/'));
        $this->assertSame('name_delimiter', FolderPath::nameError('a/b', '/'));
        $this->assertSame('name_delimiter', FolderPath::nameError('a.b', '.'));
        $this->assertSame('name_invalid', FolderPath::nameError('all*', '/'));
        $this->assertSame('name_invalid', FolderPath::nameError('50%', '/'));
        $this->assertSame('name_invalid', FolderPath::nameError("a\nb", '/'));
        $this->assertSame('name_too_long', FolderPath::nameError(str_repeat('a', 256), '/'));

        $this->expectException(InvalidArgumentException::class);
        FolderPath::imap('a/b', null, '/');
    }

    public function test_namespace_prefix_is_derived_from_existing_folders(): void
    {
        $this->assertSame('INBOX.', FolderPath::namespacePrefix(['INBOX', 'INBOX.Sent', 'INBOX.Trash'], '.'));
        $this->assertSame('', FolderPath::namespacePrefix(['INBOX', 'Sent', 'INBOX/Sub'], '/'));
        $this->assertSame('', FolderPath::namespacePrefix(['INBOX'], '.'));
    }

    public function test_prefixes_of_descendants_are_replaced(): void
    {
        $this->assertSame('Kunden', FolderPath::replacePrefix('Projekte', 'Projekte', 'Kunden', '/'));
        $this->assertSame('Kunden/Acme/2026', FolderPath::replacePrefix('Projekte/Acme/2026', 'Projekte', 'Kunden', '/'));
        $this->assertSame('Projekte2/Acme', FolderPath::replacePrefix('Projekte2/Acme', 'Projekte', 'Kunden', '/'));

        $this->assertTrue(FolderPath::isWithin('Projekte/Acme', 'Projekte', '/'));
        $this->assertFalse(FolderPath::isWithin('Projekte2', 'Projekte', '/'));
    }
}
