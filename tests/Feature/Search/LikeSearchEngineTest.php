<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Search;

use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\Engines\LikeSearchEngine;
use Cooolinho\FilamentMailbox\Tests\Contracts\MessageSearchEngineContractTest;

class LikeSearchEngineTest extends MessageSearchEngineContractTest
{
    protected bool $searchesAttachmentText = false;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-mailbox.search.engine', 'like');
        $app['config']->set('filament-mailbox.search.include_body', true);
    }

    protected function engine(): MessageSearchEngine
    {
        return app(LikeSearchEngine::class);
    }

    public function test_body_and_attachments(): void
    {
        // The like engine searches the plain text body only.
        $this->assertHits('unterlagen', [$this->application]);
        $this->assertHits('überweisen', [$this->invoice]);
    }
}
