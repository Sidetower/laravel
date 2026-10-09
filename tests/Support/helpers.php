<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
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

if (! function_exists('sdk')) {
    function sdk(): Sidetower
    {
        return app(Sidetower::class);
    }
}

if (! function_exists('sdkBuffered')) {
    /**
     * @return list<array<string, mixed>>
     */
    function sdkBuffered(?string $type = null): array
    {
        $items = app(Buffer::class)->all();

        return $type === null ? $items : array_values(array_filter($items, fn (array $item): bool => $item['type'] === $type));
    }
}

if (! function_exists('sdkCheckinItem')) {
    /**
     * @return array<string, mixed>
     */
    function sdkCheckinItem(string $monitor = 'nightly', string $status = 'ok'): array
    {
        return ['type' => 'checkin', 'monitor' => $monitor, 'status' => $status];
    }
}

if (! function_exists('sdkUseStore')) {
    /**
     * Makes the buffer use a cache store that is not one of the framework's: a store without locks, or one that is down.
     */
    function sdkUseStore(string $kind): void
    {
        Cache::extend($kind, fn () => new Repository($kind === 'down' ? new BrokenStore : new NoLockStore));

        config()->set("cache.stores.{$kind}", ['driver' => $kind]);
        config()->set('sidetower.buffer.store', $kind);
    }
}

if (! function_exists('sdkEnvelopeOf')) {
    /**
     * The envelope a request carried: gunzipped, decoded.
     *
     * @return array<string, mixed>
     */
    function sdkEnvelopeOf(Request $request): array
    {
        return json_decode((string) gzdecode($request->body()), true, flags: JSON_THROW_ON_ERROR);
    }
}

if (! function_exists('sdkSentEnvelopes')) {
    /**
     * The envelopes sent so far through the Http client, in order.
     *
     * @return list<array<string, mixed>>
     */
    function sdkSentEnvelopes(): array
    {
        return Http::recorded()->map(fn (array $pair): array => sdkEnvelopeOf($pair[0]))->values()->all();
    }
}

if (! function_exists('sdkFakeServer')) {
    /**
     * A server that accepts everything it is sent (202), unless told what to answer. Replaces an earlier one.
     */
    function sdkFakeServer(mixed $answer = null): void
    {
        Http::swap(new Factory(app('events')));

        Http::fake([
            'sidetower.test/*' => $answer ?? fn (Request $request) => Http::response(['accepted' => count(sdkEnvelopeOf($request)['items']), 'rejected' => []], 202),
        ]);
    }
}

if (! function_exists('sdkFillBuffer')) {
    /**
     * Puts items straight into the buffer, as earlier runs would have left them.
     *
     * @param  array<string, mixed>  $overrides
     */
    function sdkFillBuffer(int $count, array $overrides = []): void
    {
        $items = [];

        foreach (range(1, $count) as $number) {
            $items[] = [...sdkCheckinItem("monitor-{$number}"), 'id' => "item-{$number}", 'ts' => '2026-10-09T17:59:30.000Z', ...$overrides];
        }

        app(Buffer::class)->push($items);
    }
}

if (! function_exists('sdkCheckout')) {
    /**
     * Points the application at a temporary directory that looks like a checkout: `.git/HEAD` with the given content and
     * the given files below `.git/`. With `$under` the directory is `<under>/<sha>`, like a Deployer release.
     *
     * @param  array<string, string>  $git  path below `.git/` => content
     */
    function sdkCheckout(string $head, array $git = [], ?string $under = null): string
    {
        $root = sys_get_temp_dir().'/sidetower-checkout-'.bin2hex(random_bytes(6));
        $path = $under === null ? $root : "{$root}/{$under}";

        mkdir("{$path}/.git", 0777, true);
        file_put_contents("{$path}/.git/HEAD", $head."\n");

        foreach ($git as $file => $content) {
            @mkdir(dirname("{$path}/.git/{$file}"), 0777, true);
            file_put_contents("{$path}/.git/{$file}", $content);
        }

        register_shutdown_function(fn () => @exec('rm -rf '.escapeshellarg($root)));
        app()->setBasePath($path);

        return $path;
    }
}
