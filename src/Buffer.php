<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The items that wait for the scheduled `sidetower:send`, kept under one cache key. Every change is a read-modify-write
 * under a lock (when the store has locks), because requests, workers and the send command all touch it at once.
 *
 * Methods throw when the cache does, or when the lock stays taken for 2 seconds; callers catch, since nothing here may reach
 * the host application.
 */
class Buffer
{
    public const ITEMS = 'sidetower:buffer';

    public const DROPPED = 'sidetower:dropped';

    public const RELEASE = 'sidetower:release';

    public const LAST_RUN = 'sidetower:last-run';

    protected const LOCK = 'sidetower:buffer:lock';

    protected const LOCK_SECONDS = 10;

    protected const LOCK_WAIT_SECONDS = 2;

    /** Older items would be refused as stale by the server anyway. */
    protected const TTL_SECONDS = 7 * 86400;

    /**
     * The store of `buffer.store`, the default cache store when that is null.
     */
    public function cache(): Repository
    {
        $name = config('sidetower.buffer.store');

        return Cache::store(is_string($name) && $name !== '' ? $name : null);
    }

    /**
     * Whether the store keeps items between processes. The `array` and `null` stores do not: items recorded by web requests
     * would never reach the command that sends them.
     */
    public function persistent(): bool
    {
        $configured = config('sidetower.buffer.store');
        $name = is_string($configured) && $configured !== '' ? $configured : config('cache.default');

        return ! in_array(config("cache.stores.{$name}.driver"), ['array', 'null'], true);
    }

    public function max(): int
    {
        return max(1, (int) config('sidetower.buffer.max_items', 5000));
    }

    /**
     * Appends items, oldest first. When the buffer then holds more than `buffer.max_items` the oldest items are dropped and
     * counted. `$dropped` is the number of items the caller already had to drop before it could get here.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function push(array $items, int $dropped = 0): void
    {
        $this->locked(function () use ($items, $dropped): void {
            $merged = [...$this->read(), ...$items];
            $over = count($merged) - $this->max();

            if ($over > 0) {
                $merged = array_slice($merged, $over);
                $dropped += $over;
            }

            $this->write($merged);

            if ($dropped > 0) {
                $this->cache()->increment(self::DROPPED, $dropped);
            }
        });
    }

    /**
     * Everything in the buffer, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * Removes the items with these ids (sent, or given up on). Items added meanwhile stay.
     *
     * @param  list<string>  $ids
     */
    public function remove(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->locked(function () use ($ids): void {
            $gone = array_flip($ids);

            $this->write(array_values(array_filter(
                $this->read(),
                fn (array $item): bool => ! isset($gone[$item['id'] ?? null]),
            )));
        });
    }

    public function dropped(): int
    {
        return max(0, (int) $this->cache()->get(self::DROPPED, 0));
    }

    /**
     * Takes `$count` off the counter. Drops that were counted since it was read stay on it.
     */
    public function forgetDropped(int $count): void
    {
        if ($count > 0) {
            $this->cache()->decrement(self::DROPPED, $count);
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    protected function locked(Closure $callback): mixed
    {
        $store = $this->cache();

        if (! $store->getStore() instanceof LockProvider) {
            return $callback();
        }

        return $store->lock(self::LOCK, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $callback);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function read(): array
    {
        $stored = $this->cache()->get(self::ITEMS);

        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function write(array $items): void
    {
        if ($items === []) {
            $this->cache()->forget(self::ITEMS);

            return;
        }

        $this->cache()->put(self::ITEMS, $items, self::TTL_SECONDS);
    }
}
