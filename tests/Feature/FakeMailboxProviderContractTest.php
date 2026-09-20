<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Tests\Contracts\MailboxProviderContractTest;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;

class FakeMailboxProviderContractTest extends MailboxProviderContractTest
{
    protected FakeMailboxProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = (new FakeMailboxProvider)
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox), uidValidity: 5)
            ->addFolder(new FolderData('Archive', 'Archive', '/'), uidValidity: 6);
    }

    protected function provider(): MailboxProvider
    {
        return $this->fake;
    }

    protected function inbox(): FolderIdentifier
    {
        return new FolderIdentifier('INBOX');
    }

    protected function otherFolder(): FolderIdentifier
    {
        return new FolderIdentifier('Archive');
    }

    protected function seedMessage(FolderIdentifier $folder, string $rawMime): void
    {
        $this->fake->appendMessage($folder, $rawMime);
    }
}
