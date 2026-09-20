<?php

namespace Cooolinho\FilamentMailbox\Search;

use Closure;
use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\Engines\DatabaseSearchEngine;
use Cooolinho\FilamentMailbox\Search\Engines\LikeSearchEngine;
use Cooolinho\FilamentMailbox\Search\Meilisearch\MeilisearchSearchEngine;
use Cooolinho\FilamentMailbox\Search\Scout\ScoutSearchEngine;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Search engines by name: the built-in ones, classes from the configuration
 * ("search.engines") and engines registered by applications or plugins.
 */
class SearchEngineRegistry
{
    /** @var array<string, class-string<MessageSearchEngine>> */
    public const BUILT_IN = [
        'like' => LikeSearchEngine::class,
        'database' => DatabaseSearchEngine::class,
        'meilisearch' => MeilisearchSearchEngine::class,
        'scout' => ScoutSearchEngine::class,
    ];

    /** @var array<string, Closure(Application): MessageSearchEngine> */
    protected array $factories = [];

    /**
     * @param  Closure(Application): MessageSearchEngine  $factory
     */
    public function register(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]) || isset(self::BUILT_IN[$name]) || filled(config("filament-mailbox.search.engines.{$name}"));
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_values(array_unique([...array_keys(self::BUILT_IN), ...array_keys($this->factories), ...array_keys((array) config('filament-mailbox.search.engines', []))]));
    }

    public function resolve(?string $name = null): MessageSearchEngine
    {
        $name = $name ?: (string) config('filament-mailbox.search.engine', 'like');

        if (isset($this->factories[$name])) {
            return $this->factories[$name](app());
        }

        // The configuration may override built-in names with own classes.
        $class = config("filament-mailbox.search.engines.{$name}") ?? self::BUILT_IN[$name] ?? $name;
        $engine = is_string($class) && (class_exists($class) || app()->bound($class)) ? app($class) : null;

        if (! $engine instanceof MessageSearchEngine) {
            throw new InvalidArgumentException("Unknown mailbox search engine [{$name}].");
        }

        return $engine;
    }
}
