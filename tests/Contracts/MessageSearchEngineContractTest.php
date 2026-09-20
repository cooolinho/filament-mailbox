<?php

namespace Cooolinho\FilamentMailbox\Tests\Contracts;

use Cooolinho\FilamentMailbox\Testing\MessageSearchEngineTests;
use Cooolinho\FilamentMailbox\Tests\TestCase;

/**
 * Runs the shipped engine contract (Testing\MessageSearchEngineTests) in this package.
 */
abstract class MessageSearchEngineContractTest extends TestCase
{
    use MessageSearchEngineTests;
}
