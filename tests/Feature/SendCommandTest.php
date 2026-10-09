<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Buffer;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    Carbon::setTestNow('2026-10-09T13:39:30.000Z');
    config()->set('sidetower.health.enabled', false);
    config()->set('sidetower.deploys', false);
});

function sdkSendEvent(): ?Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $event) => str_contains((string) $event->command, 'sidetower:send'));
}

describe('the schedule', function () {
    it('runs sidetower:send every minute without overlapping and on every server', function () {
        $event = sdkSendEvent();

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('* * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeFalse()
            ->and($event->runInBackground)->toBeFalse();
    });

    it('lets a killed run go after ten minutes', function () {
        expect(sdkSendEvent()->expiresAt)->toBe(10);
    });

    it('does not run while the SDK is off', function () {
        expect(sdkSendEvent()->filtersPass(app()))->toBeTrue();

        config()->set('sidetower.enabled', false);

        expect(sdkSendEvent()->filtersPass(app()))->toBeFalse();
    });
});

describe('the run', function () {
    it('records the scheduler check-in and sends it', function () {
        sdkFakeServer();

        $this->artisan('sidetower:send')->assertSuccessful();

        $item = sdkSentEnvelopes()[0]['items'][0];

        expect($item)->toMatchArray([
            'type' => 'checkin',
            'monitor' => 'scheduler',
            'status' => 'ok',
            'expectedIntervalS' => 60,
            'ts' => '2026-10-09T13:39:30.000Z',
        ])->and($item['id'])->toMatch('/^[0-9a-z]{26}$/')
            ->and(sdkBuffered())->toBe([]);
    });

    it('sends what earlier runs left, then what is new', function () {
        sdkFakeServer();
        sdkFillBuffer(2);

        $this->artisan('sidetower:send')->assertSuccessful();

        $items = sdkSentEnvelopes()[0]['items'];

        expect(array_column($items, 'id'))->toHaveCount(3)
            ->and(array_column(array_slice($items, 0, 2), 'id'))->toBe(['item-1', 'item-2'])
            ->and($items[2]['monitor'])->toBe('scheduler');
    });

    it('sends what the process itself recorded before the command ran', function () {
        sdkFakeServer();
        sdk()->record(sdkCheckinItem('from this process'));

        $this->artisan('sidetower:send')->assertSuccessful();

        expect(array_column(sdkSentEnvelopes()[0]['items'], 'monitor'))->toBe(['from this process', 'scheduler']);
    });

    it('notes when it ran, for the health report', function () {
        sdkFakeServer();

        $this->artisan('sidetower:send')->assertSuccessful();

        expect(Cache::get(Buffer::LAST_RUN))->toBe('2026-10-09T13:39:30Z');
    });

    it('says what happened', function () {
        sdkFakeServer();

        $this->artisan('sidetower:send')->expectsOutputToContain('1 sent, 0 rejected, 0 dropped, 0 waiting (1 request)');
    });

    it('succeeds even when the endpoint cannot be reached, so the scheduler does not report a failure', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->artisan('sidetower:send')->assertSuccessful();

        expect(array_column(sdkBuffered(), 'monitor'))->toBe(['scheduler']);
    });

    it('warns when the buffer is in a store that forgets between processes', function () {
        sdkFakeServer();

        $this->artisan('sidetower:send')->expectsOutputToContain('does not keep items between processes')->assertSuccessful();
    });

    it('does not warn about a store that keeps items', function () {
        sdkFakeServer();
        config()->set('cache.stores.files', ['driver' => 'file', 'path' => sys_get_temp_dir().'/sidetower-cache-'.bin2hex(random_bytes(3))]);
        config()->set('sidetower.buffer.store', 'files');

        $this->artisan('sidetower:send')->doesntExpectOutputToContain('does not keep items')->assertSuccessful();
    });

    it('succeeds when the cache is down', function () {
        sdkFakeServer();
        sdkUseStore('down');

        $this->artisan('sidetower:send')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('does nothing while the SDK is off', function () {
        sdkFakeServer();
        config()->set('sidetower.key', null);

        $this->artisan('sidetower:send')->expectsOutputToContain('Sidetower is off')->assertSuccessful();

        Http::assertNothingSent();
        expect(sdkBuffered())->toBe([]);
    });
});

describe('the dropped items', function () {
    it('are sent as a counter metric and the count starts over', function () {
        sdkFakeServer();
        Cache::put(Buffer::DROPPED, 5);

        $this->artisan('sidetower:send')->assertSuccessful();

        $metric = collect(sdkSentEnvelopes()[0]['items'])->firstWhere('type', 'metric');

        expect($metric)->toMatchArray(['name' => 'sidetower.dropped', 'kind' => 'counter', 'value' => 5])
            ->and($metric['id'])->toMatch('/^[0-9a-z]{26}$/')
            ->and(app(Buffer::class)->dropped())->toBe(0);
    });

    it('come from a buffer that was too full', function () {
        sdkFakeServer();
        config()->set('sidetower.buffer.max_items', 20);
        sdkFillBuffer(27);

        $this->artisan('sidetower:send')->assertSuccessful();

        $metric = collect(sdkSentEnvelopes()[0]['items'])->firstWhere('type', 'metric');

        expect($metric['value'])->toBe(7);
    });

    it('send no metric when nothing was dropped', function () {
        sdkFakeServer();

        $this->artisan('sidetower:send')->assertSuccessful();

        expect(collect(sdkSentEnvelopes()[0]['items'])->where('type', 'metric'))->toBeEmpty();
    });

    it('wait in the buffer with the metric while the server cannot be reached, and are counted once', function () {
        $down = true;
        sdkFakeServer(function (Request $request) use (&$down) {
            return $down ? throw new ConnectionException('down') : Http::response(['accepted' => count(sdkEnvelopeOf($request)['items']), 'rejected' => []], 202);
        });
        Cache::put(Buffer::DROPPED, 3);

        $this->artisan('sidetower:send')->assertSuccessful();

        expect(sdkBuffered('metric'))->toHaveCount(1)
            ->and(sdkBuffered('metric')[0]['value'])->toBe(3)
            ->and(app(Buffer::class)->dropped())->toBe(0);

        $down = false;
        $this->artisan('sidetower:send')->assertSuccessful();

        expect(collect(sdkSentEnvelopes()[0]['items'])->where('type', 'metric'))->toHaveCount(1);
    });
});
