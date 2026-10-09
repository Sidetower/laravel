<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Sidetower\Laravel\Sidetower;

require_once __DIR__.'/../Support/helpers.php';

it('does not write to the cache while recording', function () {
    sdk()->record(sdkCheckinItem());

    expect(sdkBuffered())->toBe([])
        ->and(sdk()->pending())->toHaveCount(1);
});

it('persists when the request, command or Octane request ends', function () {
    sdk()->record(sdkCheckinItem('terminating'));

    app()->terminate();

    expect(array_column(sdkBuffered(), 'monitor'))->toBe(['terminating']);

    foreach (['RequestTerminated', 'TaskTerminated', 'TickTerminated'] as $event) {
        sdk()->record(sdkCheckinItem($event));

        Event::dispatch("Laravel\\Octane\\Events\\{$event}", [new stdClass]);
    }

    expect(array_column(sdkBuffered(), 'monitor'))->toBe(['terminating', 'RequestTerminated', 'TaskTerminated', 'TickTerminated']);
});

it('persists when a queue job is processed, fails or throws', function () {
    $job = Mockery::mock(Job::class);
    $events = [
        'processed' => new JobProcessed('sync', $job),
        'failed' => new JobFailed('sync', $job, new RuntimeException('boom')),
        'exception' => new JobExceptionOccurred('sync', $job, new RuntimeException('boom')),
    ];

    foreach ($events as $name => $event) {
        sdk()->record(sdkCheckinItem($name));

        expect(array_column(sdkBuffered(), 'monitor'))->not->toContain($name);

        Event::dispatch($event);
    }

    expect(array_column(sdkBuffered(), 'monitor'))->toBe(['processed', 'failed', 'exception']);
});

it('persists as soon as 200 items are in memory', function () {
    foreach (range(1, Sidetower::PERSIST_THRESHOLD - 1) as $number) {
        sdk()->record(sdkCheckinItem("m{$number}"));
    }

    expect(sdkBuffered())->toBe([]);

    sdk()->record(sdkCheckinItem('the 200th'));

    expect(sdkBuffered())->toHaveCount(200)
        ->and(sdk()->pending())->toBe([]);
});

it('does not try the cache at every record while it is down', function () {
    sdkUseStore('down');

    foreach (range(1, Sidetower::PERSIST_THRESHOLD + 50) as $number) {
        sdk()->record(sdkCheckinItem("m{$number}"));
    }

    expect(sdk()->pending())->toHaveCount(Sidetower::PERSIST_THRESHOLD + 50);
});

it('never throws out of record or persist', function () {
    sdkUseStore('down');
    config()->set('sidetower.buffer.max_items', 'many');

    sdk()->record(sdkCheckinItem());
    sdk()->persist();
    app()->terminate();

    expect(sdk()->pending())->toHaveCount(1);
});

it('does nothing at all while the SDK is off', function () {
    config()->set('sidetower.enabled', false);

    sdk()->record(sdkCheckinItem());
    app()->terminate();

    expect(sdk()->pending())->toBe([])
        ->and(sdkBuffered())->toBe([]);
});
