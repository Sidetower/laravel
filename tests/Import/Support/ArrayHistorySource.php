<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Tests\Import\Support;

use Carbon\CarbonInterface;
use Sidetower\Laravel\Import\HistorySource;

/**
 * A history source over a fixed list of items, which remembers the `since` it was asked for.
 */
final class ArrayHistorySource implements HistorySource
{
    public ?CarbonInterface $askedSince = null;

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(private readonly string $name, private readonly array $items) {}

    public function name(): string
    {
        return $this->name;
    }

    public function items(CarbonInterface $since): iterable
    {
        $this->askedSince = $since;

        yield from $this->items;
    }

    /**
     * Registers the source in the container and in the config, the way an application lists its sources.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function register(string $name, array $items): self
    {
        $source = new self($name, $items);

        app()->instance('history.'.$name, $source);
        config()->set('sidetower.ai.history_sources', [...config('sidetower.ai.history_sources', []), 'history.'.$name]);

        return $source;
    }
}
