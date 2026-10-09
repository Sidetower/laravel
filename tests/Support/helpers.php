<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

if (! function_exists('envelopeOf')) {
    /**
     * The envelope a request carried: gunzipped, decoded.
     *
     * @return array<string, mixed>
     */
    function envelopeOf(Request $request): array
    {
        return json_decode((string) gzdecode($request->body()), true, flags: JSON_THROW_ON_ERROR);
    }
}

if (! function_exists('sentEnvelopes')) {
    /**
     * The envelopes sent so far through the Http client, in order.
     *
     * @return list<array<string, mixed>>
     */
    function sentEnvelopes(): array
    {
        return Http::recorded()->map(fn (array $pair): array => envelopeOf($pair[0]))->values()->all();
    }
}

if (! function_exists('fakeServer')) {
    /**
     * A server that accepts everything it is sent (202), unless told what to answer.
     */
    function fakeServer(mixed $answer = null): void
    {
        Http::fake([
            'sidetower.test/*' => $answer ?? fn (Request $request) => Http::response(['accepted' => count(envelopeOf($request)['items']), 'rejected' => []], 202),
        ]);
    }
}

if (! function_exists('fillBuffer')) {
    /**
     * Puts items straight into the buffer, as earlier runs would have left them.
     *
     * @param  array<string, mixed>  $overrides
     */
    function fillBuffer(int $count, array $overrides = []): void
    {
        $items = [];

        foreach (range(1, $count) as $number) {
            $items[] = [...aCheckin("monitor-{$number}"), 'id' => "item-{$number}", 'ts' => '2026-10-09T17:59:30.000Z', ...$overrides];
        }

        app(Buffer::class)->push($items);
    }
}
