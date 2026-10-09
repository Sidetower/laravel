<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use Sidetower\Laravel\Sidetower;

/**
 * A Sidetower that keeps what is recorded in memory and sends and buffers nothing, switched on whether or not a key is set.
 * Made by `Sidetower::fake()`.
 */
class SidetowerFake extends Sidetower
{
    public function __construct(protected Sidetower $real)
    {
        parent::__construct($real->app);
    }

    public function real(): Sidetower
    {
        return $this->real;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function offBecause(): ?string
    {
        return null;
    }

    public function persist(): void {}

    /**
     * What was recorded, oldest first, all of it or one type.
     *
     * @return list<array<string, mixed>>
     */
    public function recorded(?string $type = null): array
    {
        if ($type === null) {
            return $this->pending;
        }

        return array_values(array_filter($this->pending, fn (array $item): bool => ($item['type'] ?? null) === $type));
    }

    /**
     * Asserts an item of this type was recorded, and with a filter that one of them satisfies it. The filter gets the item as
     * an array.
     *
     * @param  (Closure(array<string, mixed>): bool)|null  $filter
     */
    public function assertRecorded(string $type, ?Closure $filter = null): void
    {
        Assert::assertNotEmpty(
            $this->matching($type, $filter),
            $filter === null ? "The expected [{$type}] item was not recorded.".$this->summary() : "No recorded [{$type}] item satisfies the filter.".$this->summary(),
        );
    }

    /**
     * Asserts no item of this type was recorded, or none that satisfies the filter.
     *
     * @param  (Closure(array<string, mixed>): bool)|null  $filter
     */
    public function assertNotRecorded(string $type, ?Closure $filter = null): void
    {
        Assert::assertEmpty(
            $this->matching($type, $filter),
            $filter === null ? "An unexpected [{$type}] item was recorded." : "A recorded [{$type}] item satisfies the filter, but none should.",
        );
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertEmpty($this->pending, 'Items were recorded, but none were expected.'.$this->summary());
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $filter
     * @return list<array<string, mixed>>
     */
    protected function matching(string $type, ?Closure $filter): array
    {
        $items = $this->recorded($type);

        return $filter === null ? $items : array_values(array_filter($items, fn (array $item): bool => (bool) $filter($item)));
    }

    protected function summary(): string
    {
        if ($this->pending === []) {
            return ' Nothing was recorded.';
        }

        $counts = array_count_values(array_map(fn (array $item): string => (string) ($item['type'] ?? '?'), $this->pending));
        ksort($counts);

        return ' Recorded: '.implode(', ', array_map(fn (string $type, int $count): string => "{$type} x{$count}", array_keys($counts), $counts)).'.';
    }
}
