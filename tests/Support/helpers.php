<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Sidetower\Laravel\Buffer;
use Sidetower\Laravel\Sidetower;
use Sidetower\Laravel\Tests\Support\BrokenStore;
use Sidetower\Laravel\Tests\Support\NoLockStore;

/*
 * Shared by the tests of this package; each test file that needs them requires this file.
 */

if (! function_exists('sidetower')) {
    function sidetower(): Sidetower
    {
        return app(Sidetower::class);
    }
}

if (! function_exists('buffered')) {
    /**
     * @return list<array<string, mixed>>
     */
    function buffered(?string $type = null): array
    {
        $items = app(Buffer::class)->all();

        return $type === null ? $items : array_values(array_filter($items, fn (array $item): bool => $item['type'] === $type));
    }
}

if (! function_exists('aCheckin')) {
    /**
     * @return array<string, mixed>
     */
    function aCheckin(string $monitor = 'nightly', string $status = 'ok'): array
    {
        return ['type' => 'checkin', 'monitor' => $monitor, 'status' => $status];
    }
}

if (! function_exists('useStore')) {
    /**
     * Makes the buffer use a cache store that is not one of the framework's: a store without locks, or one that is down.
     */
    function useStore(string $kind): void
    {
        Cache::extend($kind, fn () => new Repository($kind === 'down' ? new BrokenStore : new NoLockStore));

        config()->set("cache.stores.{$kind}", ['driver' => $kind]);
        config()->set('sidetower.buffer.store', $kind);
    }
}
